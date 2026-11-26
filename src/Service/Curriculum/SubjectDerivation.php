<?php

declare(strict_types=1);

namespace OERManager\Service\Curriculum;

/**
 * TASK-076: the stage subjects (`cur:Materia`) of a REA, derived from its
 * `schema:about` values (`cur:MateriaCurso`) through `cur:materia`
 * (ADR-0022 §5). Stored in `lrmi:educationalAlignment` so property-based
 * search (AdvancedSearch facets, REST API) can filter by subject. Pure, so
 * it is tested on the host; DerivedSubjectWriter feeds it from the entity.
 */
final class SubjectDerivation
{
    /**
     * @param list<array{class:?string, subjects:list<array{id:int, class:?string, title:string}>}> $about
     * @return list<array{id:int, title:string}> distinct, by title then id
     */
    public static function derive(array $about): array
    {
        $byId = [];
        foreach ($about as $target) {
            if (CurModel::MATERIA_CURSO !== ($target['class'] ?? null)) {
                continue;
            }
            foreach ($target['subjects'] ?? [] as $subject) {
                if (CurModel::MATERIA !== ($subject['class'] ?? null)) {
                    continue;
                }
                $id = (int) $subject['id'];
                $byId[$id] = ['id' => $id, 'title' => (string) $subject['title']];
            }
        }
        $derived = array_values($byId);
        usort(
            $derived,
            static fn (array $a, array $b): int => [$a['title'], $a['id']] <=> [$b['title'], $b['id']]
        );
        return $derived;
    }

    /**
     * @param list<array{id:int, title:string}> $derived
     * @return list<int>
     */
    public static function ids(array $derived): array
    {
        return array_map(static fn (array $subject): int => $subject['id'], $derived);
    }

    /**
     * True when the stored values are exactly the derived ones, in order.
     * A stored value that is not an item link is null, so it never matches.
     *
     * @param list<?int> $stored
     * @param list<int> $derived
     */
    public static function matches(array $stored, array $derived): bool
    {
        return array_values($stored) === array_values($derived);
    }
}
