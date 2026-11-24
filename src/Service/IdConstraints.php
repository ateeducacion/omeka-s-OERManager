<?php

declare(strict_types=1);

namespace OERManager\Service;

use OERManager\Service\Workflow\WorkflowStatus;

/**
 * TASK-070: the unions the master view cannot ask the core in one search.
 *
 * A group is a union of branches; a branch is a fragment of item search
 * parameters. The request's ids are the intersection of its groups. Pure:
 * `IdConstraintResolver` runs the searches.
 *
 * The core joins property rows with AND/OR without parentheses and
 * `is_public` is not a property, so «Propuesto OR public outside the flow»
 * and «lacks any required field» cannot be one search (spec «Why id sets»).
 */
final class IdConstraints
{
    public const STATUS_QUEUE = 'queue';
    public const MISSING_REQUIRED = 'required';

    /**
     * The queue (ADR-0023 §2–3): Propuesto, plus public REA whose status is
     * none of Propuesto, Publicado and Descartado. Disjoint branches, so
     * `QueueCounter` may add their counts.
     *
     * @return list<array<string,mixed>>
     */
    public static function queueBranches(): array
    {
        $status = static fn (string $type, string $text): array => [
            'property' => WorkflowStatus::STATUS_TERM,
            'type' => $type,
            'text' => $text,
        ];
        return [
            ['property' => [$status('eq', WorkflowStatus::PROPOSED)]],
            ['is_public' => true, 'property' => [
                $status('neq', WorkflowStatus::PROPOSED),
                $status('neq', WorkflowStatus::PUBLISHED),
                $status('neq', WorkflowStatus::DISCARDED),
            ]],
        ];
    }

    /**
     * @param array<string,mixed> $query the master view's GET parameters
     * @param list<string> $requiredTerms required properties of the REA template
     * @return list<list<array<string,mixed>>>
     */
    public static function groups(array $query, array $requiredTerms): array
    {
        $groups = [];
        if (self::STATUS_QUEUE === ($query['status'] ?? null)) {
            $groups[] = self::queueBranches();
        }
        $missing = (array) ($query['missing'] ?? []);
        $terms = array_values(array_unique($requiredTerms));
        if (in_array(self::MISSING_REQUIRED, $missing, true) && [] !== $terms) {
            $groups[] = array_map(
                static fn (string $term): array => ['property' => [['property' => $term, 'type' => 'nex']]],
                $terms
            );
        }
        return $groups;
    }

    /**
     * @param list<list<list<int>>> $idsPerGroup for each group, the ids of each branch
     * @return list<int> ascending
     */
    public static function combine(array $idsPerGroup): array
    {
        $result = null;
        foreach ($idsPerGroup as $branches) {
            $union = [];
            foreach ($branches as $ids) {
                foreach ($ids as $id) {
                    $union[(int) $id] = true;
                }
            }
            $result = null === $result ? $union : array_intersect_key($result, $union);
        }
        $ids = array_keys($result ?? []);
        sort($ids);
        return $ids;
    }
}
