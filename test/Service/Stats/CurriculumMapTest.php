<?php

declare(strict_types=1);

namespace OERManager\Test\Service\Stats;

use OERManager\Service\Stats\CurriculumMap;
use PHPUnit\Framework\TestCase;

final class CurriculumMapTest extends TestCase
{
    public static function sample(): CurriculumMap
    {
        return new CurriculumMap(
            [
                3 => ['label' => 'ESO', 'position' => 3],
                2 => ['label' => 'Primaria', 'position' => 2],
            ],
            [
                31 => ['label' => '2º ESO', 'stage' => 3],
                30 => ['label' => '1º ESO', 'stage' => 3],
                20 => ['label' => '1º Primaria', 'stage' => 2],
                99 => ['label' => 'Curso suelto', 'stage' => null],
            ],
            [
                300 => ['label' => 'Educación física', 'course' => 30],
                310 => ['label' => 'Educación física', 'course' => 31],
                311 => ['label' => 'Matemáticas', 'course' => 31],
                200 => ['label' => 'Matemáticas', 'course' => 20],
                900 => ['label' => 'Huérfana', 'course' => null],
            ],
            [500 => 'Eje verde']
        );
    }

    public function testCoursesAreOrderedByStageThenLevelWithStagelessLast(): void
    {
        $map = self::sample();
        $this->assertSame([2, 3], $map->orderedStageIds());
        $this->assertSame([20, 30, 31, 99], $map->orderedCourseIds());
    }

    public function testEdgesAndLabels(): void
    {
        $map = self::sample();
        $this->assertSame(31, $map->courseOfSubject(310));
        $this->assertNull($map->courseOfSubject(900));
        $this->assertNull($map->courseOfSubject(12345));
        $this->assertSame(3, $map->stageOfCourse(31));
        $this->assertNull($map->stageOfCourse(99));
        $this->assertTrue($map->isCourse(30));
        $this->assertFalse($map->isCourse(300));
        $this->assertSame('Eje verde', $map->label(500));
        $this->assertSame('1º ESO', $map->label(30));
        $this->assertSame('ESO', $map->label(3));
        $this->assertSame('Educación física', $map->label(300));
        $this->assertSame('777', $map->label(777));
    }

    public function testSameNamedSubjectsAreQualifiedWithTheirCourse(): void
    {
        $map = self::sample();
        $this->assertSame('Educación física · 1º ESO', $map->qualifiedSubjectLabel(300));
        $this->assertSame('Educación física · 2º ESO', $map->qualifiedSubjectLabel(310));
        $this->assertSame('Huérfana', $map->qualifiedSubjectLabel(900));
    }

    public function testUniverseFlagDefaultsToTrue(): void
    {
        $this->assertTrue(self::sample()->hasUniverse());
        $this->assertFalse((new CurriculumMap([], [], [], [], false))->hasUniverse());
    }
}
