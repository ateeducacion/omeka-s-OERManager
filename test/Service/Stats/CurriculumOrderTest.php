<?php

declare(strict_types=1);

namespace OERManager\Test\Service\Stats;

use OERManager\Service\Stats\CurriculumOrder;
use PHPUnit\Framework\TestCase;

/**
 * Stage and course order of the statistics (TASK-047, spec D8): stages by
 * schema:position, courses by an explicit position if the curriculum ever
 * carries one, else by the leading ordinal of the title.
 */
final class CurriculumOrderTest extends TestCase
{
    public function testOrdinalReadsTheLeadingNumberOfTheTitle(): void
    {
        $this->assertSame(1, CurriculumOrder::ordinal('1º ESO'));
        $this->assertSame(6, CurriculumOrder::ordinal(' 6º Infantil de 5 años'));
        $this->assertSame(12, CurriculumOrder::ordinal('12 Nivel'));
        $this->assertNull(CurriculumOrder::ordinal('Bachillerato'));
        $this->assertNull(CurriculumOrder::ordinal(''));
    }

    public function testStagesFollowPositionThenTitle(): void
    {
        $stages = [
            5054 => ['label' => 'Bachillerato', 'position' => 4],
            5055 => ['label' => 'ESO', 'position' => 3],
            5056 => ['label' => 'Educación Infantil', 'position' => 1],
            5057 => ['label' => 'Educación Primaria', 'position' => 2],
            9 => ['label' => 'Adultos', 'position' => null],
        ];
        $this->assertSame([5056, 5057, 5055, 5054, 9], CurriculumOrder::sortStages($stages));
    }

    public function testCoursesFollowOrdinalNotAlphabetNorId(): void
    {
        $courses = [
            30 => ['label' => '10º Curso', 'position' => null],
            10 => ['label' => '2º ESO', 'position' => null],
            20 => ['label' => '1º ESO', 'position' => null],
            40 => ['label' => 'Curso puente', 'position' => null],
        ];
        $this->assertSame([20, 10, 30, 40], CurriculumOrder::sortCourses($courses));
    }

    public function testAnExplicitCoursePositionWinsOverTheTitle(): void
    {
        $courses = [
            1 => ['label' => '1º ESO', 'position' => 2],
            2 => ['label' => '2º ESO', 'position' => 1],
        ];
        $this->assertSame([2, 1], CurriculumOrder::sortCourses($courses));
    }

    public function testTiesFallBackToNaturalTitleThenId(): void
    {
        $courses = [
            7 => ['label' => 'Grupo B', 'position' => null],
            5 => ['label' => 'Grupo A', 'position' => null],
            6 => ['label' => 'Grupo A', 'position' => null],
        ];
        $this->assertSame([5, 6, 7], CurriculumOrder::sortCourses($courses));
    }
}
