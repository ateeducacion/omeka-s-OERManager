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
 *    `lrmi:LearningResource`, private, in the REA site, with its data values.
 *    A resume clears only the data terms the wizard manages and appends;
 *    then its media, in the order of step ①: each new file or link created on
 *    its own (so a refusal names its entry), then one partial update that
 *    keeps the listed media in that order and removes the rest. The first is
 *    the main one (owner's review of PR #100);
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

    /** Files and links of one REA in a save (step ①). */
    public const MAX_MEDIA = 50;

    public function __construct(
        private readonly ApiManager $api,
        private readonly CurationWriter $writer,
        private readonly GovernanceService $governance,
        private readonly RecatalogService $recatalog,
        private readonly WorkflowService $workflow,
        private readonly WizardUploadStore $uploads,
        private readonly ReaTemplateFields $templateFields,
        private readonly ReaSite $reaSite,
        private readonly Settings $settings,
        /** Omeka's `oembed.whitelist`, to pick a link's ingester (LinkMedia). */
        private readonly array $oembedPatterns = []
    ) {
    }

    /**
     * @param array<string,mixed> $state `values` (term => strings), `resources`
     *   (in order, each with a `key`: `{kind:upload,token}`, `{kind:url,url}` or
     *   `{kind:media,id}`, a media the REA already has),
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
        $resources = $this->resources($state['resources'] ?? [], $existing, $userId);
        if (isset($resources['error'])) {
            return ['ok' => false, 'error' => $resources['error'], 'key' => $resources['key']];
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
                ? $this->create($values, $fields)
                : $this->update($existing, $values, $fields, $keepTemplate);
        } catch (ValidationException $e) {
            return ['ok' => false, 'error' => 'invalid', 'messages' => self::messages($e)];
        } catch (PermissionDeniedException $e) {
            return ['ok' => false, 'error' => 'denied'];
        }

        // From here on the REA exists: every answer carries its id, so a retry updates it
        // instead of creating another one (code review of PR #100), and the media it created,
        // so a retry keeps them instead of adding them again.
        $media = ['created' => [], 'failed' => []];
        $answer = $this->afterCreation(
            $id,
            $existing,
            $resources['entries'],
            $media,
            $values,
            $fields,
            $alignment,
            $required,
            $meetsTemplate,
            $keepTemplate,
            $contributor,
            (bool) ($state['propose'] ?? false)
        );
        if (!$answer['ok'] && [] !== $media['created']) {
            $answer['created'] = $media['created'];
        }
        return $answer;
    }

    /**
     * Steps 1 (media) to 5, once the item exists.
     *
     * @param list<array<string,mixed>> $entries
     * @param array{created:list<array{key:string,id:int}>,failed:list<array<string,mixed>>} $media
     * @param array<string,list<string>> $values
     * @param array<string,array<string,mixed>> $fields
     * @param array<string,list<int>> $alignment
     * @param list<string> $required
     * @return array<string,mixed>
     */
    private function afterCreation(
        int $id,
        ?ItemRepresentation $existing,
        array $entries,
        array &$media,
        array $values,
        array $fields,
        array $alignment,
        array $required,
        bool $meetsTemplate,
        bool $keepTemplate,
        string $contributor,
        bool $propose
    ): array {
        $step = self::STEP_MEDIA;
        try {
            $this->writeMedia($id, $existing, $entries, $media);
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
            if ([] !== $media['failed']) {
                return ['ok' => false, 'id' => $id, 'step' => self::STEP_MEDIA, 'error' => 'media',
                    'failed' => $media['failed']];
            }
            if (!$propose) {
                return ['ok' => true, 'id' => $id, 'proposed' => false];
            }
            $step = self::STEP_PROPOSE;
            $item = $this->api->read('items', $id)->getContent();
            $hasResource = [] !== (array) ($item->media() ?? []) || null !== $item->value(ReaWizardSchema::LINK_TERM);
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
     * The REA's media as step ① lists them, in order: a stored upload (the
     * module's ingester), a link (LinkMedia) or a media the REA already has.
     * A media of another item is ignored; an expired upload or a bad link
     * stops the save before anything is written, naming its entry.
     *
     * @return array{entries:list<array<string,mixed>>}|array{error:string,key:string}
     */
    private function resources(mixed $posted, ?ItemRepresentation $existing, int $userId): array
    {
        $owned = [];
        foreach (null === $existing ? [] : (array) ($existing->media() ?? []) as $media) {
            $owned[(int) $media->id()] = true;
        }
        $entries = [];
        foreach (array_slice(is_array($posted) ? $posted : [], 0, self::MAX_MEDIA) as $resource) {
            if (!is_array($resource)) {
                continue;
            }
            $kind = (string) ($resource['kind'] ?? '');
            $key = mb_substr((string) ($resource['key'] ?? ''), 0, 64);
            if ('upload' === $kind) {
                $token = (string) ($resource['token'] ?? '');
                if (null === $this->uploads->get($userId, $token)) {
                    return ['error' => 'upload_expired', 'key' => $key];
                }
                $entries[] = ['key' => $key, 'token' => $token,
                    'new' => ['o:ingester' => 'oermanager_wizard', 'wizard_token' => $token]];
            } elseif ('url' === $kind) {
                $url = trim((string) ($resource['url'] ?? ''));
                if (!self::isWebLink($url)) {
                    return ['error' => 'url_invalid', 'key' => $key];
                }
                $entries[] = ['key' => $key, 'token' => null, 'new' => LinkMedia::spec($url, $this->oembedPatterns)];
            } elseif ('media' === $kind && isset($owned[(int) ($resource['id'] ?? 0)])) {
                $entries[] = ['key' => $key, 'id' => (int) $resource['id']];
            }
        }
        return ['entries' => $entries];
    }

    /**
     * Creates each new media on its own (a refusal is recorded with its entry's
     * key and Omeka's messages), then, when the list differs from what the REA
     * has, one partial update with `o:media`: Omeka keeps the listed media in
     * that order and removes the others.
     *
     * @param list<array<string,mixed>> $entries
     * @param array{created:list<array{key:string,id:int}>,failed:list<array<string,mixed>>} $media
     */
    private function writeMedia(int $id, ?ItemRepresentation $existing, array $entries, array &$media): void
    {
        $order = [];
        foreach ($entries as $entry) {
            if (isset($entry['id'])) {
                $order[] = (int) $entry['id'];
                continue;
            }
            try {
                $created = $this->api->create('media', ['o:item' => ['o:id' => $id]] + $entry['new'])->getContent();
            } catch (ValidationException $e) {
                $media['failed'][] = ['key' => $entry['key'], 'messages' => self::messages($e)];
                continue;
            }
            $order[] = (int) $created->id();
            $media['created'][] = ['key' => $entry['key'], 'id' => (int) $created->id()];
            if (null !== $entry['token']) {
                $this->uploads->forget($entry['token']);
            }
        }
        $current = null === $existing
            ? array_column($media['created'], 'id')
            : array_map(static fn ($m) => (int) $m->id(), (array) ($existing->media() ?? []));
        if ($order !== $current) {
            $this->api->update(
                'items',
                $id,
                ['o:media' => array_map(static fn (int $m) => ['o:id' => $m], $order)],
                [],
                ['isPartial' => true]
            );
        }
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
     */
    private function create(array $values, array $fields): int
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
        return (int) $this->api->create('items', $data)->getContent()->id();
    }

    /**
     * Clears and rewrites only the data terms whose posted values differ from
     * the stored ones, so an unchanged value keeps its language, visibility and
     * annotation (code review of PR #100).
     *
     * @param array<string,list<string>> $values
     * @param array<string,array<string,mixed>> $fields
     */
    private function update(
        ItemRepresentation $existing,
        array $values,
        array $fields,
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
        $data['clear_property_values'] = $clear;
        if (null !== $existing->resourceTemplate() && !$keepTemplate) {
            $data['o:resource_template'] = null;
        }
        $this->api->update('items', $id, $data, [], ['isPartial' => true, 'collectionAction' => 'append']);
        return $id;
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

    /** @return list<string> the stored values of a term as the wizard posts them (URI, item id or text) */
    private static function stored(ItemRepresentation $existing, string $term): array
    {
        return array_map(
            static fn ($value): string => self::posted($value),
            (array) $existing->value($term, ['all' => true, 'default' => []])
        );
    }

    /**
     * One stored value as the wizard shows and posts it: its URI, the id of the
     * item it links (an item-set vocabulary) or its text.
     */
    public static function posted(object $value): string
    {
        $uri = (string) $value->uri();
        if ('' !== $uri) {
            return $uri;
        }
        $resource = $value->valueResource();
        return null !== $resource ? (string) $resource->id() : trim((string) $value->value());
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
                // An item-set vocabulary links the chosen item (owner's report on PR #100).
                if (isset($option['resource']) && (string) $option['resource'] === $value) {
                    return ['type' => $dataType, 'property_id' => $propertyId, 'value_resource_id' => (int) $value];
                }
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
