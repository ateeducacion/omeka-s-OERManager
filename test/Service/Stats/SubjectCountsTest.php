<?php

declare(strict_types=1);

namespace OERManager\Test\Service\Stats;

use OERManager\Service\Stats\SubjectCounts;
use PHPUnit\Framework\TestCase;

/** «Materia» card (TASK-047 D10): a subject is never listed without its course. */
final class SubjectCountsTest extends TestCase
{
    private function facts(): array
    {
        return [
            1 => ['materia' => [300]],
            2 => ['materia' => [310, 311]],
            3 => ['materia' => [311]],
            4 => ['materia' => [200]],
            5 => ['materia' => [900, 7777]],
        ];
    }

    private function build(): array
    {
        return (new SubjectCounts())->build($this->facts(), CurriculumMapTest::sample(), 'Sin curso');
    }

    public function testSameNamedSubjectsStayUnderTheirOwnCourse(): void
    {
        $groups = $this->build();
        $this->assertSame(['Primaria', 'ESO', 'Sin curso'], array_column($groups, 'label'));

        $eso = $groups[1];
        $this->assertSame(['1º ESO', '2º ESO'], array_column($eso['courses'], 'label'));
        $this->assertSame([['label' => 'Educación física', 'count' => 1]], $eso['courses'][0]['subjects']);
        // Within a course: by count, then name.
        $this->assertSame(
            [['label' => 'Matemáticas', 'count' => 2], ['label' => 'Educación física', 'count' => 1]],
            $eso['courses'][1]['subjects']
        );
    }

    public function testSubjectsWithoutKnownCourseGoToALastGroup(): void
    {
        $other = $this->build()[2];
        $this->assertNull($other['stageId']);
        $this->assertSame(['7777', 'Huérfana'], array_column($other['courses'][0]['subjects'], 'label'));
    }

    public function testFilterByStageAndCourse(): void
    {
        $counts = new SubjectCounts();
        $groups = $this->build();
        $this->assertSame(['ESO'], array_column($counts->filter($groups, 3, null), 'label'));
        $byCourse = $counts->filter($groups, null, 31);
        $this->assertCount(1, $byCourse);
        $this->assertSame(['2º ESO'], array_column($byCourse[0]['courses'], 'label'));
        $this->assertSame([], $counts->filter($groups, 2, 31));
        $this->assertSame($groups, $counts->filter($groups, null, null));
    }

    public function testRowsForCsvCarryStageAndCourse(): void
    {
        $counts = new SubjectCounts();
        $rows = $counts->toRows($counts->filter($this->build(), 3, null));
        $this->assertSame([
            ['ESO', '1º ESO', 'Educación física', 1],
            ['ESO', '2º ESO', 'Matemáticas', 2],
            ['ESO', '2º ESO', 'Educación física', 1],
        ], $rows);
    }
}
