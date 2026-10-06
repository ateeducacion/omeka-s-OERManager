<?php

declare(strict_types=1);

namespace OERManager\Test\Service\Stats;

use OERManager\Service\Stats\StageCounts;
use PHPUnit\Framework\TestCase;

/** «Etapa y curso» card (TASK-047 D9): grouped by stage, ordered by level, never by count. */
final class StageCountsTest extends TestCase
{
    public function testGroupsByStageInCurriculumOrderWithDistinctTotals(): void
    {
        $facts = [
            1 => ['etapa' => [31]],
            2 => ['etapa' => [31]],
            3 => ['etapa' => [30, 31]],
            4 => ['etapa' => [20]],
            5 => ['etapa' => [4242]],
            6 => ['etapa' => []],
        ];
        $groups = (new StageCounts())->build($facts, CurriculumMapTest::sample(), 'Sin etapa');

        $this->assertSame(['Primaria', 'ESO', 'Sin etapa'], array_column($groups, 'label'));
        $this->assertSame([2, 3, null], array_column($groups, 'stageId'));

        $eso = $groups[1];
        // REA 3 is in 1º and 2º ESO: one REA for the stage.
        $this->assertSame(3, $eso['total']);
        $this->assertSame(
            [['courseId' => 30, 'label' => '1º ESO', 'count' => 1], ['courseId' => 31, 'label' => '2º ESO', 'count' => 3]],
            $eso['courses']
        );

        $other = $groups[2];
        // Stageless known course listed with 0, unknown term with its count.
        $this->assertSame(['Curso suelto', '4242'], array_column($other['courses'], 'label'));
        $this->assertSame([0, 1], array_column($other['courses'], 'count'));
        $this->assertSame(1, $other['total']);
    }

    public function testEmptyCatalogueKeepsTheCurriculumWithZeros(): void
    {
        $groups = (new StageCounts())->build([], CurriculumMapTest::sample(), 'Sin etapa');
        $this->assertSame([0, 0, 0], array_column($groups, 'total'));
        $this->assertSame(0, $groups[1]['courses'][1]['count']);
    }

    public function testRowsForCsv(): void
    {
        $groups = (new StageCounts())->build([1 => ['etapa' => [30]]], CurriculumMapTest::sample(), 'Sin etapa');
        $rows = (new StageCounts())->toRows($groups);
        $this->assertSame(['Primaria', '1º Primaria', 0], $rows[0]);
        $this->assertSame(['ESO', '1º ESO', 1], $rows[1]);
    }
}
