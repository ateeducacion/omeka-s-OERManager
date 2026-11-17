<?php

namespace OERManager\Service\Curriculum;

/**
 * Pedagogical order of the cascade (TASK-064, owner decision 4).
 *
 * The corpus and its ids are alphabetical (BAC, ESO, INF, PRI). Stages follow
 * the fixed list INF, PRI, ESO, BAC; an unknown stage goes last, by notation.
 * Courses follow their stage, then their own `skos:notation` (I01…I06,
 * P01…P06, S01…S04, B01, B02).
 */
final class StageOrder
{
    public const ORDER = ['INF', 'PRI', 'ESO', 'BAC'];

    public static function compare(string $a, string $b): int
    {
        return (self::rank($a) <=> self::rank($b)) ?: strnatcasecmp($a, $b);
    }

    /**
     * @param list<array<string,mixed>> $rows each with 'notation'
     * @return list<array<string,mixed>>
     */
    public static function sortStages(array $rows): array
    {
        usort($rows, static fn (array $a, array $b): int => self::compare(
            (string) ($a['notation'] ?? ''),
            (string) ($b['notation'] ?? '')
        ));
        return $rows;
    }

    /**
     * @param list<array<string,mixed>> $rows each with 'stageNotation' and 'notation'
     * @return list<array<string,mixed>>
     */
    public static function sortCourses(array $rows): array
    {
        usort($rows, static fn (array $a, array $b): int => self::compare(
            (string) ($a['stageNotation'] ?? ''),
            (string) ($b['stageNotation'] ?? '')
        ) ?: strnatcasecmp((string) ($a['notation'] ?? ''), (string) ($b['notation'] ?? '')));
        return $rows;
    }

    private static function rank(string $notation): int
    {
        $rank = array_search($notation, self::ORDER, true);
        return false === $rank ? count(self::ORDER) : $rank;
    }
}
