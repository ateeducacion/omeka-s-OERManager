<?php

declare(strict_types=1);

namespace OERManager\Service\Workflow;

/**
 * Who may change `curation:status`/`curation:note` on a REA (security reviews
 * of TASK-067). They are plain values, so anyone with item update — a
 * `rea_proponente` inherits it from `site_editor` — could otherwise write
 * «Publicado» by hand, make the values public again, reorder them (the module
 * reads the FIRST status) or forge the annotation that records who published
 * and when (ADR-0023 §2).
 *
 * Accepted changes come only from WorkflowService, which runs its writes
 * inside `run()` (a flag scoped to this request's write), or from
 * `global_admin`, who loads the initial import (TASK-072). Nobody may leave a
 * REA with more than one status. Unchanged values always pass: the native
 * form resubmits the values it shows.
 *
 * «Changed» is decided on a canonical, ORDERED form of every value of both
 * terms — type, text, uri, language, linked resource, visibility and the
 * value annotation's own values — built alike from the database rows
 * (`fromRows()`, ordered by value id like Omeka's `Resource::$values`) and from
 * the hydrated entity (`fromEntityValues()`).
 *
 * One shared instance per request (service manager), so Module's listener and
 * WorkflowService see the same flag.
 */
class StatusGuard
{
    public const TERMS = [WorkflowStatus::STATUS_TERM, WorkflowStatus::NOTE_TERM];

    private int $depth = 0;

    /**
     * @template T
     * @param callable(): T $write
     * @return T
     */
    public function run(callable $write): mixed
    {
        $this->depth++;
        try {
            return $write();
        } finally {
            $this->depth--;
        }
    }

    /**
     * @param array<string, list<array>> $before term => canonical values in the database
     * @param array<string, list<array>> $after term => canonical values after hydration
     */
    public function rejects(array $before, array $after, bool $isLearningResource, ?string $role): bool
    {
        if (!$isLearningResource || $this->depth > 0) {
            return false;
        }
        $changed = false;
        foreach (self::TERMS as $term) {
            if (($before[$term] ?? []) !== ($after[$term] ?? [])) {
                $changed = true;
            }
        }
        if (!$changed) {
            return false;
        }
        if (count($after[WorkflowStatus::STATUS_TERM] ?? []) > 1) {
            return true;
        }
        return 'global_admin' !== $role;
    }

    /**
     * One value in canonical form. `$term` is set for annotation values only.
     *
     * @param list<array> $annotation canonical annotation values, in order
     */
    public static function canonical(
        ?string $type,
        ?string $text,
        ?string $uri,
        ?string $lang,
        int|string|null $resourceId,
        bool|int|string|null $isPublic,
        array $annotation = [],
        ?string $term = null
    ): array {
        $value = [
            'type' => (string) $type,
            'text' => (string) $text,
            'uri' => (string) $uri,
            'lang' => (string) $lang,
            'resource' => (int) $resourceId,
            'public' => (bool) $isPublic,
        ];
        if (null !== $term) {
            return ['term' => $term] + $value;
        }
        return $value + ['annotation' => $annotation];
    }

    /**
     * From the `value` rows of the two terms (ordered by id, `term` = local
     * name) and the rows of their annotations (`resource_id` = annotation id,
     * `term` = prefix:local, ordered by id).
     *
     * @param list<array<string, mixed>> $rows
     * @param list<array<string, mixed>> $annotationRows
     * @return array<string, list<array>>
     */
    public static function fromRows(array $rows, array $annotationRows): array
    {
        $annotations = [];
        foreach ($annotationRows as $row) {
            $annotations[(int) $row['resource_id']][] = self::canonical(
                $row['type'],
                $row['value'],
                $row['uri'],
                $row['lang'],
                $row['value_resource_id'],
                $row['is_public'],
                [],
                (string) $row['term']
            );
        }
        $out = [];
        foreach ($rows as $row) {
            if (null === ($row['term'] ?? null)) {
                continue;
            }
            $annotationId = (int) ($row['value_annotation_id'] ?? 0);
            $out['curation:' . $row['term']][] = self::canonical(
                $row['type'],
                $row['value'],
                $row['uri'],
                $row['lang'],
                $row['value_resource_id'],
                $row['is_public'],
                $annotations[$annotationId] ?? []
            );
        }
        return $out;
    }

    /**
     * From the hydrated entity's values (Omeka `Value` entities, duck-typed),
     * in collection order.
     *
     * @param iterable<object> $values
     * @return array<string, list<array>>
     */
    public static function fromEntityValues(iterable $values): array
    {
        $out = [];
        foreach ($values as $value) {
            $term = self::term($value->getProperty());
            if (!in_array($term, self::TERMS, true)) {
                continue;
            }
            $annotation = [];
            $valueAnnotation = $value->getValueAnnotation();
            foreach ($valueAnnotation ? $valueAnnotation->getValues() : [] as $annotationValue) {
                $annotationTerm = self::term($annotationValue->getProperty());
                $annotation[] = self::canonicalEntity($annotationValue, [], $annotationTerm);
            }
            $out[$term][] = self::canonicalEntity($value, $annotation, null);
        }
        return $out;
    }

    /** `prefix:local` of a property or resource class entity, or null. */
    public static function term(?object $member): ?string
    {
        return $member ? $member->getVocabulary()->getPrefix() . ':' . $member->getLocalName() : null;
    }

    private static function canonicalEntity(object $value, array $annotation, ?string $term): array
    {
        $resource = $value->getValueResource();
        return self::canonical(
            $value->getType(),
            $value->getValue(),
            $value->getUri(),
            $value->getLang(),
            $resource ? $resource->getId() : null,
            $value->isPublic(),
            $annotation,
            $term
        );
    }
}
