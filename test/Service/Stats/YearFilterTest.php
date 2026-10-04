<?php

declare(strict_types=1);

namespace OERManager\Test\Service\Stats;

use OERManager\Service\Stats\YearFilter;
use PHPUnit\Framework\TestCase;

final class YearFilterTest extends TestCase
{
    private const FACTS = [
        1 => ['year' => 2025, 'materia' => [1]],
        2 => ['year' => 2026, 'materia' => [1]],
        3 => ['year' => 2026, 'materia' => [2]],
        4 => ['year' => null, 'materia' => []],
    ];

    public function testYearsCountReaPerYearNewestFirst(): void
    {
        $this->assertSame([2026 => 2, 2025 => 1], (new YearFilter())->years(self::FACTS));
    }

    public function testApplyKeepsOnlyThatYearAndPreservesIds(): void
    {
        $filtered = (new YearFilter())->apply(self::FACTS, 2026);
        $this->assertSame([2, 3], array_keys($filtered));
    }

    public function testNoYearMeansEveryRea(): void
    {
        $this->assertSame(self::FACTS, (new YearFilter())->apply(self::FACTS, null));
    }

    public function testParseAcceptsOnlyPlausibleYears(): void
    {
        $this->assertSame(2026, YearFilter::parse('2026'));
        $this->assertNull(YearFilter::parse(''));
        $this->assertNull(YearFilter::parse('abc'));
        $this->assertNull(YearFilter::parse('26'));
        $this->assertNull(YearFilter::parse('2026; DROP'));
        $this->assertNull(YearFilter::parse(null));
    }
}
