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
    public const STEP_TEMPLATE = 'template';
    public const STEP_PROPOSE = 'propose';

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
        $resource = $this->resource($state['resource'] ?? null, $userId);
        if (isset($resource['error'])) {
            return ['ok' => false, 'error' => $resource['error']];
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
        // Kept only if the stored REA already meets it: the governance and anchoring values that
        // will meet it are written after this update, which Omeka validates against the template.
        $keepTemplate = null !== $existing && null !== $existing->resourceTemplate()
            && $meetsTemplate && $this->storedMeets($existing, $required);

        try {
            $id = null === $existing
                ? $this->create($values, $fields, $resource)
                : $this->update($existing, $values, $fields, $resource, $keepTemplate);
        } catch (ValidationException $e) {
            return ['ok' => false, 'error' => 'invalid', 'messages' => self::messages($e)];
        } catch (PermissionDeniedException $e) {
            return ['ok' => false, 'error' => 'denied'];
        }

        // From here on the REA exists: every answer carries its id, so a retry updates it
        // instead of creating another one (code review of PR #100).
        $step = self::STEP_MEDIA;
        try {
            if (null !== $existing && null !== $resource['media']) {
                $this->replaceMedia($existing, $resource['media']);
            }
            if (null !== $resource['token']) {
                $this->uploads->forget($resource['token']);
            }
            $step = self::STEP_GOVERNANCE;
            $governance = $this->governanceRaw($values, $fields);
            if ($governance) {
                $result = $this->governance->apply($id, $governance, $contributor, null, false, null, false);
                if (!empty($result['errors'])) {
                    return ['ok' => false, 'id' => $id, 'step' => $step, 'error' => 'governance',
                        'errors' => $result['errors']];
                }
            }
            $step = self::STEP_ANCHORING;
            try {
                $this->recatalog->apply($id, $alignment, $contributor);
            } catch (ValidationException | PermissionDeniedException $e) {
                throw $e;
            } catch (\RuntimeException $e) {
                // RecatalogService's domain message names the invalid or retired term.
                return ['ok' => false, 'id' => $id, 'step' => $step, 'error' => 'anchoring',
                    'message' => $e->getMessage()];
            }
            $step = self::STEP_TEMPLATE;
            $templateId = GovernanceSettings::parseId($this->settings->get(GovernanceSettings::REA_TEMPLATE_ID));
            if ($meetsTemplate && !$keepTemplate && null !== $templateId) {
                $this->writer->commit($id, [], ['o:resource_template' => ['o:id' => $templateId]]);
            }
            if (!($state['propose'] ?? false)) {
                return ['ok' => true, 'id' => $id, 'proposed' => false];
            }
            $step = self::STEP_PROPOSE;
            $item = $this->api->read('items', $id)->getContent();
            $hasResource = [] !== $item->media() || null !== $item->value(ReaWizardSchema::LINK_TERM);
            $missing = MinimumRule::missing(
                ['resource' => $hasResource, 'values' => $values, 'alignment' => $alignment],
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
        } catch (ValidationException $e) {
            return ['ok' => false, 'id' => $id, 'step' => $step, 'error' => 'invalid',
                'messages' => self::messages($e)];
        } catch (PermissionDeniedException $e) {
            return ['ok' => false, 'id' => $id, 'step' => $step, 'error' => 'denied'];
        } catch (\Throwable $e) {
            return ['ok' => false, 'id' => $id, 'step' => $step, 'error' => 'unexpected', 'exception' => get_class($e)];
        }
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

    /**
     * The main resource: the stored upload (media of the module's ingester), a
     * link (a `schema:url` value: Omeka's `url` ingester downloads its target and
     * refuses a web page), or nothing to change.
     *
     * @return array{media:?array<string,mixed>,token:?string,link:?string,kind:string}|array{error:string}
     */
    private function resource(mixed $resource, int $userId): array
    {
        $kind = is_array($resource) ? (string) ($resource['kind'] ?? '') : '';
        if ('upload' === $kind) {
            $token = (string) ($resource['token'] ?? '');
            if (null === $this->uploads->get($userId, $token)) {
                return ['error' => 'upload_expired'];
            }
            return ['media' => ['o:ingester' => 'oermanager_wizard', 'wizard_token' => $token], 'token' => $token,
                'link' => null, 'kind' => $kind];
        }
        if ('url' === $kind) {
            $url = trim((string) ($resource['url'] ?? ''));
            if (!self::isWebLink($url) || null === $this->writer->propertyId(ReaWizardSchema::LINK_TERM)) {
                return ['error' => 'url_invalid'];
            }
            return ['media' => null, 'token' => null, 'link' => $url, 'kind' => $kind];
        }
        return ['media' => null, 'token' => null, 'link' => null, 'kind' => 'keep'];
    }

    /** An http(s) link of at most 2000 characters, IRIs included (accented paths), without spaces. */
    public static function isWebLink(string $url): bool
    {
        return mb_strlen($url) <= 2000 && 1 === preg_match('#^https?://[^\s/?\#]+[^\s]*$#iu', $url)
            && 1 !== preg_match('/[\x00-\x1F\x7F<>"]/u', $url);
    }

    /**
     * @param array<string,list<string>> $values
     * @param array<string,array<string,mixed>> $fields
     * @param array<string,mixed> $resource
     */
    private function create(array $values, array $fields, array $resource): int
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
        if (null !== $resource['media']) {
            $data['o:media'] = [$resource['media']];
        }
        if (null !== $resource['link']) {
            $data[ReaWizardSchema::LINK_TERM] = [$this->linkValue($resource['link'])];
        }
        return (int) $this->api->create('items', $data)->getContent()->id();
    }

    /**
     * Clears and rewrites only the data terms whose posted values differ from
     * the stored ones, so an unchanged value keeps its language, visibility and
     * annotation (code review of PR #100).
     *
     * @param array<string,list<string>> $values
     * @param array<string,array<string,mixed>> $fields
     * @param array<string,mixed> $resource
     */
    private function update(
        ItemRepresentation $existing,
        array $values,
        array $fields,
        array $resource,
        bool $keepTemplate
    ): int {
        $id = (int) $existing->id();
        $clear = [];
        $changed = [];
        foreach (array_keys($fields) as $term) {
            if (ReaWizardSchema::isGovernance($term) || self::stored($existing, $term) === $values[$term]) {
                continue;
            }
            $propertyId = $this->writer->propertyId($term);
            if (null !== $propertyId) {
                $clear[] = $propertyId;
                $changed[$term] = $values[$term];
            }
        }
        $data = $this->dataValues($changed, $fields);
        // The link is the main resource: a new upload or a new link replaces it.
        $linkId = $this->writer->propertyId(ReaWizardSchema::LINK_TERM);
        $storedLink = self::stored($existing, ReaWizardSchema::LINK_TERM);
        if (null !== $linkId && 'keep' !== $resource['kind'] && $storedLink !== array_filter([$resource['link']])) {
            $clear[] = $linkId;
            if (null !== $resource['link']) {
                $data[ReaWizardSchema::LINK_TERM] = [$this->linkValue($resource['link'])];
            }
        }
        $data['clear_property_values'] = $clear;
        if (null !== $existing->resourceTemplate() && !$keepTemplate) {
            $data['o:resource_template'] = null;
        }
        $this->api->update('items', $id, $data, [], ['isPartial' => true, 'collectionAction' => 'append']);
        if (null !== $resource['link']) {
            // One main resource per REA: a link replaces the files.
            $this->deleteMedia(array_map(static fn ($m) => (int) $m->id(), $existing->media()));
        }
        return $id;
    }

    /**
     * The new file becomes the only media: every previous one is deleted, in
     * one batch (TASK-064 lesson: two deletes in a request fail with Access).
     *
     * @param array<string,mixed> $media
     */
    private function replaceMedia(ItemRepresentation $existing, array $media): void
    {
        $previous = array_map(static fn ($m) => (int) $m->id(), $existing->media());
        $this->api->create('media', ['o:item' => ['o:id' => (int) $existing->id()]] + $media);
        $this->deleteMedia($previous);
    }

    /** @param list<int> $ids */
    private function deleteMedia(array $ids): void
    {
        if ($ids) {
            $this->api->batchDelete('media', $ids);
        }
    }

    /** Whether every required template property already has a stored value. */
    private function storedMeets(ItemRepresentation $existing, array $required): bool
    {
        foreach ($required as $term) {
            if (null === $existing->value($term)) {
                return false;
            }
        }
        return true;
    }

    /** @return list<string> the stored values of a term as the wizard posts them (URI or text) */
    private static function stored(ItemRepresentation $existing, string $term): array
    {
        $out = [];
        foreach ((array) $existing->value($term, ['all' => true, 'default' => []]) as $value) {
            $uri = (string) $value->uri();
            $out[] = '' !== $uri ? $uri : trim((string) $value->value());
        }
        return $out;
    }

    /** @return array<string,mixed> */
    private function linkValue(string $url): array
    {
        return ['type' => 'uri', 'property_id' => (int) $this->writer->propertyId(ReaWizardSchema::LINK_TERM),
            '@id' => $url];
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
            if (ReaWizardSchema::isGovernance($term) || [] === ($values[$term] ?? [])) {
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
