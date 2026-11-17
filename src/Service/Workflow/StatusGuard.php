<?php

declare(strict_types=1);

namespace OERManager\Service\Workflow;

/**
 * Who may change `curation:status`/`curation:note` on a REA (security review
 * of TASK-067 slice 2). They are plain literals, so anyone with item update —
 * a `rea_proponente` inherits it from `site_editor` — could write «Publicado»
 * by hand in the native form or the API, make the REA public and skip the
 * curator without even raising «Publicado fuera del flujo».
 *
 * Accepted changes come only from WorkflowService, which runs its writes
 * inside `run()` (a flag scoped to this request's write), or from
 * `global_admin`, who loads the initial import (TASK-072). Unchanged values
 * always pass: the native form resubmits the values it shows. Compared as
 * text, order-insensitive; visibility and annotations are not compared.
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
     * @param array<string, list<string>> $before term => texts in the database
     * @param array<string, list<string>> $after term => texts after hydration
     */
    public function rejects(array $before, array $after, bool $isLearningResource, ?string $role): bool
    {
        if (!$isLearningResource || $this->depth > 0 || 'global_admin' === $role) {
            return false;
        }
        foreach (self::TERMS as $term) {
            $old = array_map('strval', $before[$term] ?? []);
            $new = array_map('strval', $after[$term] ?? []);
            sort($old);
            sort($new);
            if ($old !== $new) {
                return true;
            }
        }
        return false;
    }
}
