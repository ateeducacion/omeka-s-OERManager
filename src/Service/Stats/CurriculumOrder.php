<?php

declare(strict_types=1);

namespace OERManager\Service\Stats;

/**
 * Curriculum order of stages and courses for the statistics (TASK-047,
 * spec D8). Pure.
 *
 * Stages carry `schema:position` in the curriculum. Courses carry none
 * (measured 2026-10-04 on the 18 real courses), so a course is ordered by an
 * explicit position if one ever appears, else by the leading ordinal of its
 * title («1º ESO» → 1), then by natural title order, then by id. Never by
 * count and never alphabetically first: «10º» must follow «2º».
 */
final class CurriculumOrder
{
    /** Leading number of a course title, or null when the title has none. */
    public static function ordinal(string $title): ?int
    {
        return preg_match('/^\s*(\d+)/u', $title, $match) ? (int) $match[1] : null;
    }

    /**
     * @param array<int, array{label:string, position:?int}> $stages
     * @return list<int>
     */
    public static function sortStages(array $stages): array
    {
        $ids = array_keys($stages);
        usort($ids, static function (int $a, int $b) use ($stages): int {
            return self::compareNullable($stages[$a]['position'] ?? null, $stages[$b]['position'] ?? null)
                ?: strnatcasecmp($stages[$a]['label'], $stages[$b]['label'])
                ?: $a <=> $b;
        });
        return $ids;
    }

    /**
     * @param array<int, array{label:string, position?:?int}> $courses
     * @return list<int>
     */
    public static function sortCourses(array $courses): array
    {
        $ids = array_keys($courses);
        usort($ids, static function (int $a, int $b) use ($courses): int {
            return self::compareNullable($courses[$a]['position'] ?? null, $courses[$b]['position'] ?? null)
                ?: self::compareNullable(self::ordinal($courses[$a]['label']), self::ordinal($courses[$b]['label']))
                ?: strnatcasecmp($courses[$a]['label'], $courses[$b]['label'])
                ?: $a <=> $b;
        });
        return $ids;
    }

    /** Known values first, in ascending order; unknown values last. */
    private static function compareNullable(?int $a, ?int $b): int
    {
        if (null === $a || null === $b) {
            return (null === $a) <=> (null === $b);
        }
        return $a <=> $b;
    }
}
