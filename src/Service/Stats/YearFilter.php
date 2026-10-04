<?php

declare(strict_types=1);

namespace OERManager\Service\Stats;

/**
 * Creation-year filter of the statistics (TASK-047, spec D6/D7). Pure. The
 * year comes from the native `o:created` (DimensionFacts). One filter over the
 * facts feeds every block and every CSV, so they cannot diverge.
 */
final class YearFilter
{
    /** A `year` query parameter as a year, or null when absent or implausible. */
    public static function parse(mixed $raw): ?int
    {
        if (!is_string($raw) && !is_int($raw)) {
            return null;
        }
        $raw = trim((string) $raw);
        if (!preg_match('/^\d{4}$/', $raw)) {
            return null;
        }
        return (int) $raw;
    }

    /**
     * @param array<int, array<string, mixed>> $facts
     * @return array<int, int> Year => number of REA, newest first.
     */
    public function years(array $facts): array
    {
        $years = [];
        foreach ($facts as $row) {
            $year = $row['year'] ?? null;
            if (is_int($year)) {
                $years[$year] = ($years[$year] ?? 0) + 1;
            }
        }
        krsort($years);
        return $years;
    }

    /**
     * @template T of array<string, mixed>
     * @param array<int, T> $facts
     * @return array<int, T> The facts of that year, ids preserved.
     */
    public function apply(array $facts, ?int $year): array
    {
        if (null === $year) {
            return $facts;
        }
        return array_filter($facts, static fn (array $row): bool => ($row['year'] ?? null) === $year);
    }
}
