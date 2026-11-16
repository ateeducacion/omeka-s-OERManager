<?php

declare(strict_types=1);

namespace OERManager\Test\Service\Stats;

use OERManager\Service\Stats\CurriculumOrder;
use PHPUnit\Framework\TestCase;

/**
 * Stage and course order of the statistics on the `cur:` model (TASK-064
 * slice 5, owner decision 4): stages INF, PRI, ESO, BAC by `skos:notation`,
 * unknown stages last by notation; courses by `skos:notation`. Replaces the
 * ADR-0009 order (schema:position, leading ordinal of the title).
 */
final class CurriculumOrderTest extends TestCase
{
    public function testStagesFollowThePedagogicalNotationOrder(): void
    {
        // Ids in corpus order, which is alphabetical (BAC, ESO, INF, PRI).
        $stages = [
            46261 => ['label' => 'Bachillerato', 'notation' => 'BAC'],
            46262 => ['label' => 'Educación Secundaria Obligatoria', 'notation' => 'ESO'],
            46263 => ['label' => 'Educación Infantil', 'notation' => 'INF'],
            46264 => ['label' => 'Educación Primaria', 'notation' => 'PRI'],
            9 => ['label' => 'Sin notación', 'notation' => ''],
            8 => ['label' => 'Adultos', 'notation' => 'ADU'],
        ];
        $this->assertSame([46263, 46264, 46262, 46261, 8, 9], CurriculumOrder::sortStages($stages));
    }

    public function testStagesWithoutNotationFallBackToLabelThenId(): void
    {
        $stages = [
            3 => ['label' => 'B'],
            2 => ['label' => 'A'],
            1 => ['label' => 'A'],
        ];
        $this->assertSame([1, 2, 3], CurriculumOrder::sortStages($stages));
    }

    public function testCoursesFollowTheirNotationNotTheirTitle(): void
    {
        $courses = [
            10 => ['label' => '2º ESO', 'notation' => 'S02'],
            20 => ['label' => '1º ESO', 'notation' => 'S01'],
            // A notation beats a misleading title.
            30 => ['label' => '0º Algo', 'notation' => 'S03'],
            40 => ['label' => 'Curso suelto', 'notation' => ''],
        ];
        $this->assertSame([20, 10, 30, 40], CurriculumOrder::sortCourses($courses));
    }

    public function testCoursesWithoutNotationUseNaturalTitleOrderThenId(): void
    {
        // The classifier passes labels only: natural order keeps «10º» after «2º».
        $courses = [
            30 => ['label' => '10º Curso'],
            10 => ['label' => '2º ESO'],
            20 => ['label' => '1º ESO'],
            40 => ['label' => 'Curso puente'],
            6 => ['label' => 'Grupo A'],
            5 => ['label' => 'Grupo A'],
        ];
        $this->assertSame([20, 10, 30, 40, 5, 6], CurriculumOrder::sortCourses($courses));
    }
}
