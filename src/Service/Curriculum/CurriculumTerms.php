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

    /** Resource class id of a term such as `cur:Curso`, or null. */
    public function classId(string $term): ?int;
}
