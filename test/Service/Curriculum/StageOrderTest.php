<?php

declare(strict_types=1);

namespace OERManager\Test\Service\Curriculum;

use OERManager\Service\Curriculum\StageOrder;
use PHPUnit\Framework\TestCase;

/** TASK-064: corpus order is alphabetical (BAC, ESO, INF, PRI); the cascade is pedagogical. */
final class StageOrderTest extends TestCase
{
    public function testKnownStagesFollowThePedagogicalOrderAndUnknownGoLastByNotation(): void
    {
        $rows = array_map(static fn ($n) => ['notation' => $n], ['BAC', 'ZZZ', 'ESO', 'FP2', 'INF', 'PRI', 'FP10']);
        $this->assertSame(
            ['INF', 'PRI', 'ESO', 'BAC', 'FP2', 'FP10', 'ZZZ'],
            array_column(StageOrder::sortStages($rows), 'notation')
        );
    }

    public function testCompareRanksStagesBeforeNotation(): void
    {
        $this->assertLessThan(0, StageOrder::compare('PRI', 'ESO'));
        $this->assertGreaterThan(0, StageOrder::compare('', 'BAC'));
        $this->assertSame(0, StageOrder::compare('ESO', 'ESO'));
    }

    public function testCoursesSortByStageThenNaturalNotation(): void
    {
        $rows = [
            ['id' => 1, 'stageNotation' => 'BAC', 'notation' => 'B01'],
            ['id' => 2, 'stageNotation' => 'ESO', 'notation' => 'S02'],
            ['id' => 3, 'stageNotation' => 'INF', 'notation' => 'I06'],
            ['id' => 4, 'stageNotation' => 'ESO', 'notation' => 'S01'],
            ['id' => 5, 'stageNotation' => 'PRI', 'notation' => 'P10'],
            ['id' => 6, 'stageNotation' => 'PRI', 'notation' => 'P02'],
            ['id' => 7, 'notation' => 'X01'],
        ];
        $this->assertSame([3, 6, 5, 4, 2, 1, 7], array_column(StageOrder::sortCourses($rows), 'id'));
    }
}
