<?php

declare(strict_types=1);

namespace OERManager\Service\Stats;

use OERManager\Service\Curriculum\StageOrder;

/**
 * Curriculum order of stages and courses for the statistics. Pure.
 *
 * TASK-064 slice 5 (owner decision 4): stages follow `StageOrder` on their
 * `skos:notation` (INF, PRI, ESO, BAC; unknown stages last, by notation);
 * courses follow their own `skos:notation` (S01, S02…). This replaces the
 * ADR-0009 order (`schema:position`, then the leading ordinal of the title,
 * TASK-047 D8). A node without a notation (a legacy term, or the labels the
 * classifier passes) goes after the notated ones, in natural label order:
 * «10º» still follows «2º». Never by count, ties by id.
 */
final class CurriculumOrder
{
    /**
     * @param array<int, array{label:string, notation?:string}> $stages
     * @return list<int>
     */
    public static function sortStages(array $stages): array
    {
        $ids = array_keys($stages);
        usort($ids, static function (int $a, int $b) use ($stages): int {
            $na = (string) ($stages[$a]['notation'] ?? '');
            $nb = (string) ($stages[$b]['notation'] ?? '');
            return (('' === $na) <=> ('' === $nb))
                ?: StageOrder::compare($na, $nb)
                ?: strnatcasecmp($stages[$a]['label'], $stages[$b]['label'])
                ?: $a <=> $b;
        });
        return $ids;
    }

    /**
     * @param array<int, array{label:string, notation?:string}> $courses
     * @return list<int>
     */
    public static function sortCourses(array $courses): array
    {
        $ids = array_keys($courses);
        usort($ids, static function (int $a, int $b) use ($courses): int {
            $na = (string) ($courses[$a]['notation'] ?? '');
            $nb = (string) ($courses[$b]['notation'] ?? '');
            return (('' === $na) <=> ('' === $nb))
                ?: strnatcasecmp($na, $nb)
                ?: strnatcasecmp($courses[$a]['label'], $courses[$b]['label'])
                ?: $a <=> $b;
        });
        return $ids;
    }
}
