<?php

declare(strict_types=1);

namespace OERManager\Test\Service\Stats;

use OERManager\Service\Stats\CoverageMatrix;
use OERManager\Service\Stats\CurriculumMap;
use PHPUnit\Framework\TestCase;

/** Coverage heatmap (TASK-047 D2/D3): subject name × course, gaps told apart from n/a. */
final class CoverageMatrixTest extends TestCase
{
    private function build(array $facts): array
    {
        return (new CoverageMatrix())->build($facts, CurriculumMapTest::sample());
    }

    public function testColumnsFollowTheCurriculumAndStagesSpanThem(): void
    {
        $matrix = $this->build([]);
        $this->assertSame([20, 30, 31], array_column($matrix['columns'], 'id'));
        $this->assertSame(
            [['id' => 2, 'label' => 'Primaria', 'span' => 1], ['id' => 3, 'label' => 'ESO', 'span' => 2]],
            $matrix['stages']
        );
    }

    public function testRowsAreSubjectNamesWithNullForNonExistingAndZeroForGaps(): void
    {
        $matrix = $this->build([1 => ['materia' => [310]], 2 => ['materia' => [310, 311]]]);
        $this->assertSame(['Educación física', 'Matemáticas'], array_column($matrix['rows'], 'label'));
        // Columns: 1º Primaria, 1º ESO, 2º ESO.
        $this->assertSame([null, 0, 2], $matrix['rows'][0]['cells']);
        $this->assertSame([0, null, 1], $matrix['rows'][1]['cells']);
        $this->assertSame(2, $matrix['max']);
        $this->assertSame(0, $matrix['unplaced']);
    }

    public function testRowsMergeSpellingVariantsOfTheSameName(): void
    {
        $map = new CurriculumMap(
            [1 => ['label' => 'ESO', 'position' => 1]],
            [10 => ['label' => '1º ESO', 'stage' => 1], 11 => ['label' => '2º ESO', 'stage' => 1]],
            [100 => ['label' => 'Educación Física', 'course' => 10], 101 => ['label' => 'educacion  fisica', 'course' => 11]],
            []
        );
        $matrix = (new CoverageMatrix())->build([], $map);
        $this->assertCount(1, $matrix['rows']);
        $this->assertSame('Educación Física', $matrix['rows'][0]['label']);
    }

    public function testLinksToSubjectsWithoutCourseAreCountedAsUnplaced(): void
    {
        $matrix = $this->build([1 => ['materia' => [900, 4242]], 2 => ['materia' => [300]]]);
        $this->assertSame(2, $matrix['unplaced']);
        $this->assertNotContains('Huérfana', array_column($matrix['rows'], 'label'));
    }

    public function testFilterStageKeepsItsColumnsAndDropsRowsThatBecomeEmpty(): void
    {
        $coverage = new CoverageMatrix();
        $matrix = $coverage->filterStage($this->build([1 => ['materia' => [200]]]), 2);
        $this->assertSame([20], array_column($matrix['columns'], 'id'));
        $this->assertSame(['Matemáticas'], array_column($matrix['rows'], 'label'));
        $this->assertSame([1], $matrix['rows'][0]['cells']);
        $this->assertSame([['id' => 2, 'label' => 'Primaria', 'span' => 1]], $matrix['stages']);
    }

    public function testRowsForCsvSkipNonExistingCellsAndKeepGaps(): void
    {
        $coverage = new CoverageMatrix();
        $rows = $coverage->toRows($this->build([1 => ['materia' => [311]]]));
        $this->assertSame([
            ['ESO', '1º ESO', 'Educación física', 0],
            ['ESO', '2º ESO', 'Educación física', 0],
            ['Primaria', '1º Primaria', 'Matemáticas', 0],
            ['ESO', '2º ESO', 'Matemáticas', 1],
        ], $rows);
    }
}
