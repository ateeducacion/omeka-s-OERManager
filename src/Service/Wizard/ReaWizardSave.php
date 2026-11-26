<?php

declare(strict_types=1);

namespace OERManager\Service\Wizard;

use OERManager\Service\Curation\CurationWriter;
use OERManager\Service\GovernanceService;
use OERManager\Service\GovernanceSettings;
use OERManager\Service\ReaSite;
use OERManager\Service\ReaTemplateFields;
use OERManager\Service\RecatalogService;
use OERManager\Service\Workflow\WorkflowService;
use Omeka\Api\Exception\ValidationException;
use Omeka\Api\Manager as ApiManager;
use Omeka\Api\Representation\ItemRepresentation;
use Omeka\Permissions\Exception\PermissionDeniedException;
use Omeka\Settings\Settings;

/**
 * What the wizard's `save` writes (TASK-077 spec, «What saving writes»), in
 * order:
 *
 * 1. the item, created or updated through the API: class
 *    `lrmi:LearningResource`, private, in the REA site, with its data values
 *    and its main resource (the file stored in step ① or a URL). A resume
 *    clears only the data terms the wizard manages and appends;
 * 2. the governance fields (licence, authorship…) with GovernanceService;
 * 3. the anchoring and the axes with RecatalogService::apply only;
 * 4. the REA template, once every required template property has a value
 *    (Omeka refuses the template otherwise, so a draft «with only a title»
 *    has none);
 * 5. «Guardar y proponer»: WorkflowService::propose, after checking the
 *    minimum rule again. The status is never written here.
 *
 * If step 1 fails nothing is written. If a later step fails, the REA stays a
 * draft with what was written, and the answer says which step failed.
 */
class ReaWizardSave
{
    public const STEP_GOVERNANCE = 'governance';
    public const STEP_ANCHORING = 'anchoring';
    public const STEP_MEDIA = 'media';

    public function __construct(
        private readonly ApiManager $api,
        private readonly CurationWriter $writer,
        private readonly GovernanceService $governance,
        private readonly RecatalogService $recatalog,
        private readonly WorkflowService $workflow,
        private readonly WizardUploadStore $uploads,
        private readonly ReaTemplateFields $templateFields,
        private readonly ReaSite $reaSite,
        private readonly Settings $settings
    ) {
    }

    /**
     * @param array<string,mixed> $state `values` (term => strings), `resource`
     *   (`{kind:upload,token}`, `{kind:url,url}`, `{kind:keep}` or null),
     *   `alignment` (term => ids), `propose` (bool)
     * @param array{data:list<array<string,mixed>>} $schema ReaWizardSchema data fields with their `options`
     * @return array<string,mixed>
     */
    public function save(
        array $state,
        array $schema,
        ?ItemRepresentation $existing,
        int $userId,
        string $contributor
    ): array {
        $fields = [];
        foreach ($schema['data'] as $field) {
            $fields[(string) $field['term']] = $field;
        }
        $values = $this->values((array) ($state['values'] ?? []), $fields);
        if ('' === ($values[ReaWizardSchema::TITLE_TERM][0] ?? '')) {
            return ['ok' => false, 'error' => 'title_required'];
        }
        $media = $this->media($state['resource'] ?? null, $userId);
        if (isset($media['error'])) {
            return ['ok' => false, 'error' => $media['error']];
        }
        $alignment = [];
        foreach (RecatalogService::ALIGNMENT_TERMS as $term) {
            $alignment[$term] = array_values(array_unique(array_filter(
                array_map('intval', (array) ($state['alignment'][$term] ?? [])),
                static fn (int $id) => $id > 0
            )));
        }
        $required = $this->templateFields->requiredTerms();
        $meetsTemplate = MinimumRule::meetsTemplate(['values' => $values, 'alignment' => $alignment], $required);

        try {
            $id = null === $existing
                ? $this->create($values, $fields, $media['payload'])
                : $this->update($existing, $values, $fields, $meetsTemplate);
        } catch (ValidationException $e) {
            return ['ok' => false, 'error' => 'invalid', 'messages' => self::messages($e)];
        } catch (PermissionDeniedException $e) {
            return ['ok' => false, 'error' => 'denied'];
        }

        if (null !== $existing && null !== $media['payload']) {
            try {
                $this->replaceMedia($existing, $media['payload']);
            } catch (ValidationException $e) {
                return ['ok' => false, 'id' => $id, 'step' => self::STEP_MEDIA, 'error' => 'invalid',
                    'messages' => self::messages($e)];
            }
        }
        if (null !== $media['token']) {
            $this->uploads->forget($media['token']);
        }

        $governance = $this->governanceRaw($values, $fields);
        if ($governance) {
            $result = $this->governance->apply($id, $governance, $contributor, null, false, null, false);
            if (!empty($result['errors'])) {
                return ['ok' => false, 'id' => $id, 'step' => self::STEP_GOVERNANCE, 'error' => 'governance',
                    'errors' => $result['errors']];
            }
        }
        try {
            $this->recatalog->apply($id, $alignment, $contributor);
        } catch (\RuntimeException $e) {
            return ['ok' => false, 'id' => $id, 'step' => self::STEP_ANCHORING, 'error' => 'anchoring',
                'message' => $e->getMessage()];
        }

        $templateId = GovernanceSettings::parseId($this->settings->get(GovernanceSettings::REA_TEMPLATE_ID));
        $hasTemplate = null !== $existing && null !== $existing->resourceTemplate() && $meetsTemplate;
        if ($meetsTemplate && !$hasTemplate && null !== $templateId) {
            $this->writer->commit($id, [], ['o:resource_template' => ['o:id' => $templateId]]);
        }

        if (!($state['propose'] ?? false)) {
            return ['ok' => true, 'id' => $id, 'proposed' => false];
        }
        $item = $this->api->read('items', $id)->getContent();
        $missing = MinimumRule::missing(
            ['resource' => [] !== $item->media(), 'values' => $values, 'alignment' => $alignment],
            $required
        );
        if ($missing) {
            return ['ok' => false, 'id' => $id, 'error' => 'incomplete', 'missing' => $missing];
        }
        $proposed = $this->workflow->propose($item, $contributor);
        if (!($proposed['updated'] ?? false)) {
            return ['ok' => false, 'id' => $id, 'error' => (string) ($proposed['error'] ?? 'invalid_transition')];
        }
        return ['ok' => true, 'id' => $id, 'proposed' => true];
    }

    /**
     * Trimmed, non-empty strings of the schema's data terms only: anything
     * else posted (the status, the ledger) is dropped here.
     *
     * @param array<string,mixed> $posted
     * @param array<string,array<string,mixed>> $fields
     * @return array<string,list<string>>
     */
    private function values(array $posted, array $fields): array
    {
        $values = [];
        foreach ($fields as $term => $field) {
            $list = array_values(array_filter(
                array_map(static fn ($v) => trim(mb_substr((string) $v, 0, 10000)), (array) ($posted[$term] ?? [])),
                static fn (string $v) => '' !== $v
            ));
            $values[$term] = $list;
        }
        return $values;
    }

    /** @return array{payload:?array<string,mixed>,token:?string}|array{error:string} */
    private function media(mixed $resource, int $userId): array
    {
        $kind = is_array($resource) ? (string) ($resource['kind'] ?? '') : '';
        if ('upload' === $kind) {
            $token = (string) ($resource['token'] ?? '');
            if (null === $this->uploads->get($userId, $token)) {
                return ['error' => 'upload_expired'];
            }
            return ['payload' => ['o:ingester' => 'oermanager_wizard', 'wizard_token' => $token], 'token' => $token];
        }
        if ('url' === $kind) {
            $url = trim((string) ($resource['url'] ?? ''));
            $scheme = strtolower((string) parse_url($url, PHP_URL_SCHEME));
            if (false === filter_var($url, FILTER_VALIDATE_URL) || !in_array($scheme, ['http', 'https'], true)) {
                return ['error' => 'url_invalid'];
            }
            return ['payload' => ['o:ingester' => 'url', 'ingest_url' => $url], 'token' => null];
        }
        return ['payload' => null, 'token' => null];
    }

    /**
     * @param array<string,list<string>> $values
     * @param array<string,array<string,mixed>> $fields
     * @param array<string,mixed>|null $media
     */
    private function create(array $values, array $fields, ?array $media): int
    {
        $data = $this->dataValues($values, $fields) + ['o:is_public' => false];
        $classId = $this->learningResourceClassId();
        if (null !== $classId) {
            $data['o:resource_class'] = ['o:id' => $classId];
        }
        $siteId = $this->reaSite->siteId();
        if (null !== $siteId) {
            $data['o:site'] = [['o:id' => $siteId]];
        }
        if (null !== $media) {
            $data['o:media'] = [$media];
        }
        return (int) $this->api->create('items', $data)->getContent()->id();
    }

    /**
     * @param array<string,list<string>> $values
     * @param array<string,array<string,mixed>> $fields
     */
    private function update(ItemRepresentation $existing, array $values, array $fields, bool $meetsTemplate): int
    {
        $id = (int) $existing->id();
        $clear = [];
        foreach (array_keys($fields) as $term) {
            $propertyId = ReaWizardSchema::isGovernance($term) ? null : $this->writer->propertyId($term);
            if (null !== $propertyId) {
                $clear[] = $propertyId;
            }
        }
        $data = $this->dataValues($values, $fields) + ['clear_property_values' => $clear];
        // Omeka would refuse the update while the template is set and a required value is gone.
        if (null !== $existing->resourceTemplate() && !$meetsTemplate) {
            $data['o:resource_template'] = null;
        }
        $this->api->update('items', $id, $data, [], ['isPartial' => true, 'collectionAction' => 'append']);
        return $id;
    }

    /** @param array<string,mixed> $media */
    private function replaceMedia(ItemRepresentation $existing, array $media): void
    {
        $previous = $existing->media()[0] ?? null;
        $this->api->create('media', ['o:item' => ['o:id' => (int) $existing->id()]] + $media);
        if (null !== $previous) {
            $this->api->delete('media', (int) $previous->id());
        }
    }

    /**
     * @param array<string,list<string>> $values
     * @param array<string,array<string,mixed>> $fields
     * @return array<string,list<array<string,mixed>>>
     */
    private function dataValues(array $values, array $fields): array
    {
        $data = [];
        foreach ($fields as $term => $field) {
            if (ReaWizardSchema::isGovernance($term) || [] === $values[$term]) {
                continue;
            }
            $propertyId = $this->writer->propertyId($term);
            if (null === $propertyId) {
                continue;
            }
            foreach ($values[$term] as $value) {
                $data[$term][] = $this->value($field, $propertyId, $value);
            }
        }
        return $data;
    }

    /**
     * @param array<string,mixed> $field
     * @return array<string,mixed>
     */
    private function value(array $field, int $propertyId, string $value): array
    {
        $dataType = (string) ($field['dataType'] ?? 'literal');
        if ('url' === ($field['control'] ?? '')) {
            return ['type' => 'uri', 'property_id' => $propertyId, '@id' => $value];
        }
        if (null !== ($field['vocabId'] ?? null) || str_starts_with($dataType, 'customvocab:')) {
            foreach ((array) ($field['options'] ?? []) as $option) {
                if (isset($option['uri']) && $option['uri'] === $value) {
                    $out = ['type' => $dataType, 'property_id' => $propertyId, '@id' => $value];
                    if ('' !== (string) ($option['label'] ?? '')) {
                        $out['o:label'] = (string) $option['label'];
                    }
                    return $out;
                }
            }
            return ['type' => $dataType, 'property_id' => $propertyId, '@value' => $value];
        }
        return ['type' => 'literal', 'property_id' => $propertyId, '@value' => $value];
    }

    /**
     * Every governance term of the schema, empty ones too, so a resume can
     * clear a value; GovernanceService writes nothing when nothing changed.
     *
     * @param array<string,list<string>> $values
     * @param array<string,array<string,mixed>> $fields
     * @return array<string,list<string>>
     */
    private function governanceRaw(array $values, array $fields): array
    {
        $raw = [];
        foreach (array_keys($fields) as $term) {
            if (ReaWizardSchema::isGovernance($term)) {
                $raw[$term] = $values[$term];
            }
        }
        return $raw;
    }

    private function learningResourceClassId(): ?int
    {
        $classes = $this->api->search('resource_classes', ['term' => 'lrmi:LearningResource'])->getContent();
        return $classes ? (int) $classes[0]->id() : null;
    }

    /** @return list<string> */
    private static function messages(ValidationException $e): array
    {
        $messages = [];
        $errors = (array) $e->getErrorStore()->getErrors();
        array_walk_recursive(
            $errors,
            static function ($message) use (&$messages): void {
                $messages[] = (string) $message;
            }
        );
        return $messages;
    }
}
