<?php

declare(strict_types=1);

namespace OERManager\Service;

use Omeka\Api\Manager as ApiManager;
use Omeka\Api\Representation\ItemRepresentation;
use Omeka\Settings\Settings;
use OERManager\Service\Curation\CurationWriter;
use OERManager\Service\Governance\GovernanceFields;
use OERManager\Service\Governance\LicenceStatus;
use OERManager\Service\Governance\VocabEntries;

/**
 * Reads and writes the five governance fields of RF-015, through the same
 * ledger as recataloguing (ADR-0020). Not testable on the host: it needs the
 * Omeka core. Everything it decides lives in the pure pieces it calls.
 */
class GovernanceService
{
    public function __construct(
        private ApiManager $api,
        private CurationWriter $writer,
        private VocabEntries $licenceVocab,
        private VocabEntries $publisherVocab,
        private Settings $settings
    ) {
    }

    /** @return array<string,mixed> */
    public function read(ItemRepresentation $item): array
    {
        $values = $this->currentValues($item);
        return [
            'values' => $values,
            'licenceStatus' => LicenceStatus::of($values[GovernanceFields::LICENCE] ?? [], $this->licenceVocab->uris()),
            'options' => [
                'licence' => $this->licenceVocab->entries(),
                'publisher' => $this->publisherVocab->entries(),
            ],
            'notices' => $this->notices(),
            'defaultRightsHolder' => (string) $this->settings->get(GovernanceSettings::DEFAULT_RIGHTS_HOLDER, ''),
        ];
    }

    /** @return array<string,mixed> */
    public function apply(int $itemId, array $raw, string $contributor, ?string $undoOf = null): array
    {
        $normalised = GovernanceFields::normalise($raw);
        if ($normalised['errors']) {
            return ['updated' => false, 'errors' => $normalised['errors']];
        }

        $when = $this->writer->stamp();
        $item = $this->api->read('items', $itemId)->getContent();
        $current = $this->currentValues($item);

        $data = [];
        $clear = [];
        $terms = [];
        foreach ($normalised['values'] as $term => $submitted) {
            $propertyId = $this->writer->propertyId($term);
            if (null === $propertyId) {
                continue;
            }
            $after = $this->withVocabularyType($term, $submitted);
            $terms[$term] = ['before' => $current[$term] ?? [], 'after' => $after];
            $clear[] = $propertyId;
            if ($after) {
                $data[$term] = $this->buildValues($propertyId, $after, $contributor, $when, $term);
            }
        }
        if (!$clear) {
            return ['updated' => false, 'properties' => []];
        }

        // Nothing changed means nothing is written: rewriting identical values
        // would reseal every annotation with a new author and time.
        $event = CurationEvent::buildTyped($terms, $undoOf);
        if (null === $event) {
            return ['updated' => false, 'unchanged' => true];
        }

        $eventValue = $this->writer->eventValue($event, $contributor, $when);
        if ($eventValue) {
            $data['dcterms:provenance'] = [$eventValue];
        }
        $this->writer->commit($itemId, $clear, $data);

        $fresh = $this->api->read('items', $itemId)->getContent();
        return [
            'updated' => true,
            'values' => $this->currentValues($fresh),
            'event' => ['when' => $when, 'summary' => CurationEvent::summary($event)],
        ];
    }

    /** @return array<string,mixed> */
    public function undoEvent(int $itemId, array $event, string $contributor, bool $force = false): array
    {
        $item = $this->api->read('items', $itemId)->getContent();
        $current = $this->currentValues($item);
        if (!$force) {
            $stale = [];
            foreach (CurationEvent::expectedValues($event['payload']) as $term => $expected) {
                if (($current[$term] ?? []) !== $expected) {
                    $stale[] = $term;
                }
            }
            if ($stale) {
                return ['updated' => false, 'error' => 'stale', 'terms' => $stale];
            }
        }
        $restore = [];
        foreach (CurationEvent::restoreValues($event['payload']) as $term => $values) {
            $restore[$term] = array_map(
                static fn (array $value): string => (string) ($value['uri'] ?? $value['value'] ?? ''),
                $values
            );
        }
        $result = $this->apply($itemId, $restore, $contributor, $event['when']);
        $result['undoneAt'] = $event['when'];
        return $result;
    }

    /**
     * Current state of the five governance fields, in the same typed shape
     * apply() writes and compares: a `uri` entry for URI-shaped values (native
     * `uri` type or a URI-typed CustomVocab), a `value` entry otherwise. `uri()`
     * is the discriminator, not `type()`: a term-typed CustomVocab reports the
     * same `customvocab:N` type as a URI-typed one but carries no URI (same
     * check ValueText already relies on). Order is preserved as Omeka returns
     * it, because the typed event compares before/after as ordered lists.
     *
     * @return array<string,list<array<string,string>>>
     */
    private function currentValues(ItemRepresentation $item): array
    {
        $values = [];
        foreach (GovernanceFields::all() as $term) {
            $entries = [];
            foreach ($item->value($term, ['all' => true, 'default' => []]) as $value) {
                $uri = trim((string) $value->uri());
                if ('' !== $uri) {
                    $entry = ['type' => $value->type(), 'uri' => $uri];
                    $label = trim((string) $value->value());
                    if ('' !== $label) {
                        $entry['label'] = $label;
                    }
                } else {
                    $entry = ['type' => $value->type(), 'value' => (string) $value->value()];
                }
                $entries[] = $entry;
            }
            $values[$term] = $entries;
        }
        return $values;
    }

    /**
     * Upgrades a licence or publisher value to its configured vocabulary's data
     * type when the submitted value matches one of its entries. Every other
     * term, and any value that does not match, passes through unchanged
     * (rule 2 of the brief).
     *
     * Matching and writing are uniform across both fields and both vocabulary
     * shapes (fix round 1, Ruling 1): the submitted value's own identifying
     * text — its `uri` if it has one, else its `value` — is compared against
     * each entry's `uri` (canonicalised, Ruling 2) and against its display text
     * (`label` if set, else `value`), and the FIRST MATCHING ENTRY decides the
     * written shape, not the field. A match against a URI-typed entry is always
     * written as `{type, uri, label?}` (`@id` + `o:label`, never `@value`: the
     * core CustomVocab data type silently stores an `@value` sent to a
     * URI-typed vocabulary as a bare literal and drops the URI, which is worse
     * than an error). A match against a term-typed entry is written as
     * `{type, value}`.
     *
     * This is also what makes undo round-trip: a publisher matched to a
     * URI-typed entry is restored via `restoreValues()`'s `uri ?? value`, which
     * yields the URI, resubmitted as literal text for `dcterms:publisher` (its
     * field kind never validates URI-ness) — and this matching logic finds it
     * again by comparing that text against each entry's `uri`, not only its
     * label.
     *
     * @param list<array<string,string>> $submitted
     * @return list<array<string,string>>
     */
    private function withVocabularyType(string $term, array $submitted): array
    {
        $vocab = GovernanceFields::LICENCE === $term
            ? $this->licenceVocab
            : (GovernanceFields::PUBLISHER === $term ? $this->publisherVocab : null);
        if (null === $vocab) {
            return $submitted;
        }
        $dataType = $vocab->dataType();
        if (null === $dataType) {
            return $submitted;
        }
        $entries = $vocab->entries();

        $upgraded = [];
        foreach ($submitted as $value) {
            $needle = (string) ($value['uri'] ?? $value['value'] ?? '');
            $match = '' === $needle ? null : $this->matchingEntry($needle, $entries);
            if (null === $match) {
                $upgraded[] = $value;
                continue;
            }
            if (isset($match['uri'])) {
                $upgraded[] = isset($match['label']) && '' !== $match['label']
                    ? ['type' => $dataType, 'uri' => $match['uri'], 'label' => $match['label']]
                    : ['type' => $dataType, 'uri' => $match['uri']];
            } else {
                $upgraded[] = ['type' => $dataType, 'value' => $match['value'] ?? ''];
            }
        }
        return $upgraded;
    }

    /**
     * First vocabulary entry whose URI (canonicalised) or display text equals
     * $needle, or null. Split out of withVocabularyType() only because PHP has
     * no early-return `break` out of a nested foreach with a value to keep.
     *
     * @param list<array<string,string>> $entries
     * @return array<string,string>|null
     */
    private function matchingEntry(string $needle, array $entries): ?array
    {
        $canonicalNeedle = LicenceStatus::canonicalUri($needle);
        foreach ($entries as $entry) {
            if (isset($entry['uri']) && $canonicalNeedle === LicenceStatus::canonicalUri((string) $entry['uri'])) {
                return $entry;
            }
            $text = $entry['label'] ?? $entry['value'] ?? null;
            if (null !== $text && $needle === $text) {
                return $entry;
            }
        }
        return null;
    }

    /**
     * API payload for one term's values, each carrying the same value
     * annotation (rule 5): contributor, timestamp and the governance-specific
     * provenance text. `uri` values are written as `@id` (with `o:label` when
     * the entry has one); everything else is written as `@value`.
     *
     * @param list<array<string,string>> $values
     * @return list<array<string,mixed>>
     */
    private function buildValues(int $propertyId, array $values, string $contributor, string $when, string $term): array
    {
        $annotation = $this->writer->annotation(
            $contributor,
            $when,
            sprintf('OERManager edición de gobernanza de %s', $term)
        );
        $built = [];
        foreach ($values as $entry) {
            $isUri = isset($entry['uri']);
            $data = [
                'type' => $entry['type'] ?? ($isUri ? 'uri' : 'literal'),
                'property_id' => $propertyId,
                '@annotation' => $annotation,
            ];
            if ($isUri) {
                $data['@id'] = $entry['uri'];
                if (isset($entry['label']) && '' !== $entry['label']) {
                    $data['o:label'] = $entry['label'];
                }
            } else {
                $data['@value'] = $entry['value'] ?? '';
            }
            $built[] = $data;
        }
        return $built;
    }

    /**
     * One message per configured governance vocabulary that does not resolve
     * (missing setting, or the CustomVocab it points to no longer exists): the
     * panel tells the curator the field degrades to free text instead of
     * silently accepting anything.
     *
     * @return array<string,string>
     */
    private function notices(): array
    {
        $notices = [];
        if (null === $this->licenceVocab->dataType()) {
            $notices[GovernanceFields::LICENCE] =
                'Vocabulario de licencias no configurado: la licencia se guarda como URI libre.'; // @translate
        }
        if (null === $this->publisherVocab->dataType()) {
            $notices[GovernanceFields::PUBLISHER] =
                'Vocabulario de editores no configurado: el editor se guarda como texto libre.'; // @translate
        }
        return $notices;
    }
}
