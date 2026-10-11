<?php

declare(strict_types=1);

namespace OERManager\Test\Service\Stats;

use OERManager\Service\Stats\StatsLayout;
use PHPUnit\Framework\TestCase;

/**
 * TASK-047 revision (2026-10-11): each curator's layout of «Conteo por
 * dimensión» (card order, collapsed stages) is a user setting. What comes from
 * the browser is untrusted: only known cards and stage keys survive.
 */
final class StatsLayoutTest extends TestCase
{
    public function testTheDefaultIsTheCurrentOrderWithEverythingExpanded(): void
    {
        $this->assertSame(
            [
                'order' => ['etapa', 'materia', 'eje', 'proyecto', 'licencia', 'completitud'],
                'collapsed' => ['etapa' => [], 'materia' => []],
            ],
            StatsLayout::defaults()
        );
        $this->assertSame(StatsLayout::defaults(), StatsLayout::sanitize(null));
        $this->assertSame(StatsLayout::defaults(), StatsLayout::sanitize('not json'));
    }

    public function testAKnownOrderIsKeptAndMissingCardsAreAppendedInDefaultOrder(): void
    {
        $layout = StatsLayout::sanitize(['order' => ['licencia', 'materia', 'unknown', 'materia', 42]]);

        $this->assertSame(['licencia', 'materia', 'etapa', 'eje', 'proyecto', 'completitud'], $layout['order']);
    }

    public function testCollapsedStagesAcceptOnlyStageKeysOfCollapsibleCards(): void
    {
        $layout = StatsLayout::sanitize(json_encode([
            'collapsed' => [
                'etapa' => ['3', 7, 'none', '3', '<script>', '-1', '12345678901'],
                'materia' => ['2'],
                'eje' => ['1'],
            ],
        ]));

        $this->assertSame(['etapa' => ['3', '7', 'none'], 'materia' => ['2']], $layout['collapsed']);
    }

    public function testOversizedInputIsRejected(): void
    {
        $huge = json_encode(['order' => ['licencia'], 'collapsed' => ['etapa' => array_fill(0, 2000, '1')]]);

        $this->assertSame(StatsLayout::defaults(), StatsLayout::sanitize($huge));
    }
}
