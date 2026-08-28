<?php

declare(strict_types=1);

namespace OERManager\Test\Service\Stats;

use OERManager\Service\Stats\CompletenessAggregator;
use PHPUnit\Framework\TestCase;

/** % de completitud (RF-006/RF-007, TASK-006), sobre los status ya calculados por IntegrityChecker. */
final class CompletenessAggregatorTest extends TestCase
{
    public function testCatalogoVacio(): void
    {
        $aggregator = new CompletenessAggregator();
        $this->assertSame(
            ['ok' => 0, 'warning' => 0, 'error' => 0, 'total' => 0, 'okPercent' => 0.0],
            $aggregator->aggregate([])
        );
    }

    public function testCuentaLosTresEstados(): void
    {
        $aggregator = new CompletenessAggregator();
        $result = $aggregator->aggregate(['ok', 'ok', 'warning', 'error']);
        $this->assertSame(2, $result['ok']);
        $this->assertSame(1, $result['warning']);
        $this->assertSame(1, $result['error']);
        $this->assertSame(4, $result['total']);
    }

    public function testOkPercentRedondeaAUnDecimal(): void
    {
        $aggregator = new CompletenessAggregator();
        // 1 ok de 3 = 33.333...% -> 33.3
        $result = $aggregator->aggregate(['ok', 'warning', 'error']);
        $this->assertSame(33.3, $result['okPercent']);
    }

    public function testTodosOkEsCienPorCiento(): void
    {
        $aggregator = new CompletenessAggregator();
        $result = $aggregator->aggregate(['ok', 'ok']);
        $this->assertSame(100.0, $result['okPercent']);
    }
}
