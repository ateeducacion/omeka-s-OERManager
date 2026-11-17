<?php

namespace OERManager\Service\Curriculum;

use Omeka\Api\Representation\AbstractResourceEntityRepresentation;

/**
 * The curriculum model of ADR-0022 (`cur:` classes, SKOS properties), as pure
 * data, query builders and readers (TASK-064).
 *
 * Every consumer that queries the curriculum builds its query here, so the
 * contract (`docs/omeka.md` of `curriculo_lomloe_canarias`) is written down in
 * one place: terms live in the curriculum item set, carry the resource class
 * of their type, and a current term has no `skos:changeNote` (`nex`).
 *
 * Pure: it only reads the representations it is given and never calls the
 * API, so the host test harness covers all of it.
 */
final class CurModel
{
    public const ETAPA = 'cur:Etapa';
    public const CURSO = 'cur:Curso';
    public const MATERIA = 'cur:Materia';
    public const MATERIA_CURSO = 'cur:MateriaCurso';
    public const BLOQUE_COMPETENCIAL = 'cur:BloqueCompetencial';
    public const CRITERIO = 'cur:CriterioEvaluacion';
    public const BLOQUE_SABERES = 'cur:BloqueSaberes';
    public const SABER = 'cur:SaberBasico';

    public const PREF_LABEL = 'skos:prefLabel';
    public const NOTATION = 'skos:notation';
    public const DEFINITION = 'skos:definition';
    public const CHANGE_NOTE = 'skos:changeNote';
    public const BROADER = 'skos:broader';
    public const IS_REPLACED_BY = 'dcterms:isReplacedBy';
    public const IDENTIFIER = 'dcterms:identifier';

    public const EDGE_ETAPA = 'cur:etapa';
    public const EDGE_CURSO = 'cur:curso';
    public const EDGE_MATERIA = 'cur:materia';
    public const EDGE_MATERIA_CURSO = 'cur:materiaCurso';
    public const EDGE_BLOQUE = 'cur:bloqueCompetencial';

    /**
     * The edge from any term to its course. Replaces the per-file copies of
     * the ancestor edges of the old graph (ADR-0009, superseded by ADR-0022).
     */
    public const COURSE_EDGE = self::EDGE_CURSO;

    /** The curriculum item set's identifier URI is `<base>id/curriculo`. */
    public const SCHEME_URI_SUFFIX = '/id/curriculo';

    /** The retired set's identifier URI is the scheme's plus this suffix. */
    public const RETIRED_URI_SUFFIX = '/retirados';

    /** Deepest knowledge nesting is 3 in 1.1.2; the margin only guards loops. */
    private const MAX_BLOCK_DEPTH = 8;

    /**
     * Resource dimension → target class, scoping edges by context key (closest
     * ancestor first) and the edges that qualify an ambiguous title.
     *
     * Course and course-subject labels are unique («1º ESO», «Matemáticas ·
     * 1º ESO»); knowledge and criteria labels start with their notation, so
     * the course subject is what tells a curator where they belong.
     */
    private const DIMENSIONS = [
        'lrmi:educationalLevel' => [
            'class' => self::CURSO,
            'scope' => ['etapa' => self::EDGE_ETAPA],
            'qualifiers' => [],
        ],
        'schema:about' => [
            'class' => self::MATERIA_CURSO,
            'scope' => ['level' => self::EDGE_CURSO, 'etapa' => self::EDGE_ETAPA],
            'qualifiers' => [],
        ],
        'lrmi:teaches' => [
            'class' => self::SABER,
            'scope' => [
                'about' => self::EDGE_MATERIA_CURSO,
                'level' => self::EDGE_CURSO,
                'etapa' => self::EDGE_ETAPA,
            ],
            'qualifiers' => [self::EDGE_MATERIA_CURSO],
        ],
        'lrmi:assesses' => [
            'class' => self::CRITERIO,
            'scope' => [
                'about' => self::EDGE_MATERIA_CURSO,
                'level' => self::EDGE_CURSO,
                'etapa' => self::EDGE_ETAPA,
            ],
            'qualifiers' => [self::EDGE_MATERIA_CURSO],
        ],
    ];

    private const DEFAULT_PER_PAGE = 25;

    /**
     * The panel's tree per leaf dimension (TASK-064 slice 4): the block class
     * (a root, never selectable), the leaf class (selectable at any depth) and
     * the edge from a leaf up to its parent. Knowledge nests by
     * `skos:broader` (a block or another knowledge item); a criterion hangs
     * from its competence block. Every node of both trees carries
     * `cur:materiaCurso`, so one course subject scopes the whole tree.
     */
    private const TREES = [
        'lrmi:teaches' => ['block' => self::BLOQUE_SABERES, 'leaf' => self::SABER, 'parent' => self::BROADER],
        'lrmi:assesses' => [
            'block' => self::BLOQUE_COMPETENCIAL,
            'leaf' => self::CRITERIO,
            'parent' => self::EDGE_BLOQUE,
        ],
    ];

    /** @return list<string> the dimensions chosen in a tree (knowledge, criteria) */
    public static function treeDimensions(): array
    {
        return array_keys(self::TREES);
    }

    /** @return array{block:string,leaf:string,parent:string}|null */
    public static function treeShape(string $dimension): ?array
    {
        return self::TREES[$dimension] ?? null;
    }

    /**
     * One page of the current terms of one class under a course subject
     * (`cur:materiaCurso`): the bounded query behind the panel's tree.
     *
     * @return array<string,mixed>
     */
    public static function courseSubjectQuery(
        int $schemeId,
        int $classId,
        int $courseSubjectId,
        int $perPage,
        int $page = 1
    ): array {
        return self::withResource(
            self::termsQuery($schemeId, $classId, '', $perPage, $page),
            self::EDGE_MATERIA_CURSO,
            $courseSubjectId
        );
    }

    /** @return list<string> the REA properties that point at the curriculum */
    public static function dimensions(): array
    {
        return array_keys(self::DIMENSIONS);
    }

    public static function classOf(string $dimension): ?string
    {
        return self::DIMENSIONS[$dimension]['class'] ?? null;
    }

    /** @return array<string,string> context key → edge, closest ancestor first */
    public static function scopeEdges(string $dimension): array
    {
        return self::DIMENSIONS[$dimension]['scope'] ?? [];
    }

    /** The edge shown as a result's lineage: its closest scoping ancestor. */
    public static function parentEdge(string $dimension): ?string
    {
        $edges = self::scopeEdges($dimension);
        return $edges ? reset($edges) : null;
    }

    /** @return list<string> */
    public static function qualifierEdges(string $dimension): array
    {
        return self::DIMENSIONS[$dimension]['qualifiers'] ?? [];
    }

    /**
     * Closest chosen ancestor of a dimension: [edge, ids] or null. Multiple
     * cardinality (PEND-007): a context key may hold several ids, and the
     * caller runs one query per id because the core cannot group OR rows.
     *
     * @param array<string,mixed> $context raw ids by key (etapa, level, about)
     * @return array{0:string,1:list<int>}|null
     */
    public static function scopeFor(string $dimension, array $context): ?array
    {
        foreach (self::scopeEdges($dimension) as $key => $edge) {
            $ids = self::ids($context[$key] ?? null);
            if ($ids) {
                return [$edge, $ids];
            }
        }
        return null;
    }

    /**
     * Current terms of one class in the curriculum item set, optionally
     * filtered by label. `per_page` only applies with `page`
     * (AbstractEntityAdapter::limitQuery): without it the core returns the
     * whole class (NFR-004).
     *
     * @return array<string,mixed>
     */
    public static function termsQuery(
        int $schemeId,
        int $classId,
        string $text = '',
        int $perPage = self::DEFAULT_PER_PAGE,
        int $page = 1
    ): array {
        $query = [
            'item_set_id' => $schemeId,
            'resource_class_id' => $classId,
            'property' => [
                // Only retirement writes skos:changeNote on curriculum items
                // (contract, «Diferencias con el Turtle» 4): none = current.
                ['property' => self::CHANGE_NOTE, 'type' => 'nex'],
            ],
            'sort_by' => 'title',
            'sort_order' => 'asc',
            'page' => max(1, $page),
            'per_page' => max(1, $perPage),
        ];
        $text = trim($text);
        if ('' !== $text) {
            $query['property'][] = [
                'property' => self::PREF_LABEL,
                'type' => 'in',
                'text' => $text,
                'joiner' => 'and',
            ];
        }
        return $query;
    }

    /**
     * Adds «edge points at item $id». `text` must be scalar: the core trims it
     * and an array is a TypeError on PHP 8.4.
     *
     * @param array<string,mixed> $query
     * @return array<string,mixed>
     */
    public static function withResource(array $query, string $edge, int $id): array
    {
        $query['property'][] = ['property' => $edge, 'type' => 'res', 'text' => (string) $id, 'joiner' => 'and'];
        return $query;
    }

    /**
     * Adds «edge points at an item titled $label» (`eq` matches the linked
     * resource's title, which is its skos:prefLabel), or «literal property
     * equals $label» when `$edge` is a literal property such as skos:notation.
     *
     * @param array<string,mixed> $query
     * @return array<string,mixed>
     */
    public static function withLabel(array $query, string $edge, string $label): array
    {
        $query['property'][] = ['property' => $edge, 'type' => 'eq', 'text' => trim($label), 'joiner' => 'and'];
        return $query;
    }

    /**
     * One current term of one class whose literal `$property` equals `$value`
     * (TASK-064 slice 6: a code declared by a package is a `skos:notation`, a
     * declared course a `skos:prefLabel`). Two rows per page: enough to tell a
     * unique match from an ambiguous one.
     *
     * @return array<string,mixed>
     */
    public static function exactQuery(int $schemeId, int $classId, string $property, string $value): array
    {
        return self::withLabel(self::termsQuery($schemeId, $classId, '', 2), $property, $value);
    }

    /** @return array<string,mixed> item sets that look like a curriculum scheme */
    public static function schemeQuery(): array
    {
        return [
            'property' => [['property' => self::IDENTIFIER, 'type' => 'ew', 'text' => self::SCHEME_URI_SUFFIX]],
            'page' => 1,
            'per_page' => 10,
        ];
    }

    /** @return array<string,mixed> the retired set of the scheme with URI $schemeUri */
    public static function retiredSetQuery(string $schemeUri): array
    {
        return [
            'property' => [[
                'property' => self::IDENTIFIER,
                'type' => 'eq',
                'text' => $schemeUri . self::RETIRED_URI_SUFFIX,
            ]],
            'page' => 1,
            'per_page' => 1,
        ];
    }

    /**
     * Every member of the retired set. Meant for `['returnScalar' => 'id']`:
     * ids only, the whole set in one query (no page), so the integrity rule
     * compares ids and never reads a term (TASK-064 slice 5).
     *
     * @return array<string,int>
     */
    public static function retiredMembersQuery(int $retiredSetId): array
    {
        return ['item_set_id' => $retiredSetId];
    }

    /** @return array<string,string> resource_classes search for one term */
    public static function classQuery(string $term): array
    {
        return ['term' => $term];
    }

    public static function isSchemeUri(string $uri): bool
    {
        return str_ends_with(rtrim($uri), self::SCHEME_URI_SUFFIX);
    }

    /** The scheme URI of an item set, or '' when it is not a curriculum scheme. */
    public static function schemeUri(AbstractResourceEntityRepresentation $itemSet): string
    {
        foreach ($itemSet->value(self::IDENTIFIER, ['all' => true, 'default' => []]) as $value) {
            $uri = trim((string) $value->uri());
            if ('' !== $uri && self::isSchemeUri($uri)) {
                return $uri;
            }
        }
        return '';
    }

    public static function label(AbstractResourceEntityRepresentation $item): string
    {
        $label = self::literal($item, self::PREF_LABEL);
        return '' !== $label ? $label : trim((string) $item->displayTitle());
    }

    /**
     * The subject part of a course subject's label: «Matemáticas · 1º ESO»
     * with course «1º ESO» is «Matemáticas». Where the course is shown next to
     * the subject (pairs, statistics) the suffix would repeat it. Any other
     * label is returned trimmed and whole.
     */
    public static function courseSubjectName(string $label, string $courseLabel): string
    {
        $label = trim($label);
        $suffix = ' · ' . trim($courseLabel);
        if ('' !== trim($courseLabel) && str_ends_with($label, $suffix)) {
            $name = trim(substr($label, 0, -strlen($suffix)));
            if ('' !== $name) {
                return $name;
            }
        }
        return $label;
    }

    /**
     * The label of a course subject (`cur:MateriaCurso`): «Matemáticas · 4º
     * Primaria». Inverse of courseSubjectName(); a package declares the
     * subject and its course apart.
     */
    public static function courseSubjectLabel(string $subjectName, string $courseLabel): string
    {
        return trim($subjectName) . ' · ' . trim($courseLabel);
    }

    public static function notation(AbstractResourceEntityRepresentation $item): string
    {
        return self::literal($item, self::NOTATION);
    }

    public static function definition(AbstractResourceEntityRepresentation $item): string
    {
        return self::literal($item, self::DEFINITION);
    }

    public static function isRetired(AbstractResourceEntityRepresentation $item): bool
    {
        return [] !== $item->value(self::CHANGE_NOTE, ['all' => true, 'default' => []]);
    }

    /** @return list<int> */
    public static function replacedBy(AbstractResourceEntityRepresentation $item): array
    {
        return array_column(self::refs($item, self::IS_REPLACED_BY), 'id');
    }

    public static function classTerm(AbstractResourceEntityRepresentation $item): string
    {
        $class = $item->resourceClass();
        return $class ? (string) $class->term() : '';
    }

    /**
     * Label of the block a leaf belongs to: a criterion's competence block, a
     * knowledge item's knowledge block (the `cur:BloqueSaberes` up its
     * `skos:broader` chain). Empty for other classes or a broken chain.
     */
    public static function block(AbstractResourceEntityRepresentation $item): string
    {
        $class = self::classTerm($item);
        if (self::CRITERIO === $class) {
            $block = self::target($item, self::EDGE_BLOQUE);
            return $block ? self::label($block) : '';
        }
        if (self::SABER !== $class) {
            return '';
        }
        $node = $item;
        for ($depth = 0; $depth < self::MAX_BLOCK_DEPTH; $depth++) {
            $node = self::target($node, self::BROADER);
            if (null === $node) {
                return '';
            }
            if (self::BLOQUE_SABERES === self::classTerm($node)) {
                return self::label($node);
            }
        }
        return '';
    }

    /** First resource an edge points at, or null. */
    public static function target(
        AbstractResourceEntityRepresentation $item,
        string $edge
    ): ?AbstractResourceEntityRepresentation {
        foreach ($item->value($edge, ['all' => true, 'default' => []]) as $value) {
            $resource = $value->valueResource();
            if (null !== $resource) {
                return $resource;
            }
        }
        return null;
    }

    /**
     * Id of the first resource an edge points at, 0 if none. Unlike ref() it
     * never asks the target for its title.
     */
    public static function refId(AbstractResourceEntityRepresentation $item, string $edge): int
    {
        $target = self::target($item, $edge);
        return null === $target ? 0 : (int) $target->id();
    }

    /** @return array{id:int,title:string} */
    public static function ref(AbstractResourceEntityRepresentation $item, string $edge): array
    {
        return self::refs($item, $edge)[0] ?? ['id' => 0, 'title' => ''];
    }

    /** @return list<array{id:int,title:string}> */
    public static function refs(AbstractResourceEntityRepresentation $item, string $edge): array
    {
        $refs = [];
        foreach ($item->value($edge, ['all' => true, 'default' => []]) as $value) {
            $resource = $value->valueResource();
            if (null !== $resource) {
                $refs[] = ['id' => (int) $resource->id(), 'title' => (string) $resource->displayTitle()];
            }
        }
        return $refs;
    }

    /**
     * Positive, unique ids from a raw context value, scalar or list.
     *
     * @param mixed $value
     * @return list<int>
     */
    public static function ids($value): array
    {
        $ids = [];
        foreach ((array) $value as $raw) {
            $id = (int) $raw;
            if ($id > 0 && !in_array($id, $ids, true)) {
                $ids[] = $id;
            }
        }
        return $ids;
    }

    private static function literal(AbstractResourceEntityRepresentation $item, string $term): string
    {
        foreach ($item->value($term, ['all' => true, 'default' => []]) as $value) {
            if ('literal' === $value->type()) {
                $text = trim((string) $value->value());
                if ('' !== $text) {
                    return $text;
                }
            }
        }
        return '';
    }
}
