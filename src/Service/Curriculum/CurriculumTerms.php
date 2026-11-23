<?php

namespace OERManager\Service\Curriculum;

/**
 * Runtime lookups of the curriculum that need the Omeka API (TASK-064).
 *
 * Kept small on purpose: queries are built by CurModel; this port only
 * resolves what the queries need (the curriculum item set, the retired set,
 * resource class ids). The implementation caches each answer for the request.
 */
interface CurriculumTerms
{
    /** Id of the curriculum item set (ADR-0006: fixed reference by id). */
    public const SCHEME_SETTING = 'oermanager_curriculum_item_set_id';

    /**
     * The configured curriculum item set if it is one, otherwise the only
     * item set whose identifier URI is `<base>id/curriculo`; null if none.
     */
    public function schemeId(): ?int;

    /** Is $itemSetId an item set identified as `<base>id/curriculo`? */
    public function isScheme(int $itemSetId): bool;

    /** The single curriculum item set found by its identifier URI, or null. */
    public function detectSchemeId(): ?int;

    /** The retired set `<scheme URI>/retirados`, or null. */
    public function retiredSetId(): ?int;

    /**
     * Ids of every item in the retired set, [] without one. One scalar query
     * per request (ids only): the integrity rule «REA linked to a retired
     * term» compares the REA's target ids against this list.
     *
     * @return list<int>
     */
    public function retiredIds(): array;

    /** Resource class id of a term such as `cur:Curso`, or null. */
    public function classId(string $term): ?int;

    /**
     * The knowledge and criteria trees of one course subject (TASK-064 slice
     * 4), or null when $courseSubjectId is not a `cur:MateriaCurso` of the
     * curriculum item set. Only current terms; each tree is built by
     * LeafTree from rows {id, parentId, notation, title, definition,
     * selectable}: blocks are roots and not selectable, knowledge and criteria
     * are selectable at any depth. Queries are bounded by `cur:materiaCurso`,
     * the class and a page (at most 119 knowledge and 30 criteria per course
     * subject in 1.1.2).
     *
     * @return array{subject:array{id:int,title:string}, course:array{id:int,title:string},
     *     'lrmi:teaches':list<array<string,mixed>>, 'lrmi:assesses':list<array<string,mixed>>}|null
     */
    public function courseSubjectTree(int $courseSubjectId): ?array;

    /**
     * The retired terms among $ids, with their label and the labels of their
     * successors (`dcterms:isReplacedBy`). Only retired terms are read; the
     * rest is compared by id with retiredIds().
     *
     * @param list<int> $ids
     * @return list<array{id:int,title:string,replacedBy:list<string>}>
     */
    public function retiredTerms(array $ids): array;
}
