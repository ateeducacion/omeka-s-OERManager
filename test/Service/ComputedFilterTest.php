<?php

declare(strict_types=1);

namespace OERManager\Test\Service;

use OERManager\Service\ComputedFilter;
use PHPUnit\Framework\TestCase;

/**
 * D4 (TASK-028): un filtro computado debe dar total exacto y filas estables.
 * El patrón —ids con tope duro, predicado, paginar después— es el que ADR-0013
 * declara obligatorio para todos los filtros computados de ADR-0013.
 */
final class ComputedFilterTest extends TestCase
{
    private function evens(): callable
    {
        return static fn (int $id): bool => 0 === $id % 2;
    }

    public function testTotalIsExactNotApproximate(): void
    {
        $filter = new ComputedFilter();
        $result = $filter->apply(range(1, 10), $this->evens(), 1, 3);

        $this->assertSame(5, $result['total']);
        $this->assertSame([2, 4, 6], $result['ids']);
        $this->assertFalse($result['truncated']);
    }

    public function testPagesAreStableAndFull(): void
    {
        $filter = new ComputedFilter();
        $this->assertSame([8, 10], $filter->apply(range(1, 10), $this->evens(), 2, 3)['ids']);
    }

    public function testPageBeyondTheEndIsEmptyNotAnError(): void
    {
        $filter = new ComputedFilter();
        $this->assertSame([], $filter->apply(range(1, 10), $this->evens(), 9, 3)['ids']);
    }

    public function testPageZeroOrNegativeIsTreatedAsFirstPage(): void
    {
        $filter = new ComputedFilter();
        $this->assertSame([2, 4, 6], $filter->apply(range(1, 10), $this->evens(), 0, 3)['ids']);
    }

    public function testHardCapTruncatesAndSaysSo(): void
    {
        $filter = new ComputedFilter(4);
        $result = $filter->apply(range(1, 10), $this->evens(), 1, 10);

        // Solo se evalúan los 4 primeros ids: 1,2,3,4 → pares 2 y 4.
        $this->assertSame([2, 4], $result['ids']);
        $this->assertSame(2, $result['total']);
        $this->assertTrue($result['truncated']);
    }

    public function testDefaultCapIsTwoThousand(): void
    {
        $this->assertSame(2000, ComputedFilter::HARD_CAP);
    }
}
