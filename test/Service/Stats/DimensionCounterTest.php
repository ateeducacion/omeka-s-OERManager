<?php

declare(strict_types=1);

namespace OERManager\Test\Service\Stats;

use OERManager\Service\Stats\DimensionCounter;
use PHPUnit\Framework\TestCase;

/**
 * Pieza pura (RF-007, TASK-006): agrega conteos por dimensión sobre los
 * "hechos" ya extraídos de los items (ver DimensionFacts, Task 5). No toca el
 * core, así que es TDD real en host.
 */
final class DimensionCounterTest extends TestCase
{
    public function testCatalogoVacioDaConteoVacio(): void
    {
        $counter = new DimensionCounter();
        $this->assertSame([], $counter->count([], 'materia'));
    }

    public function testCuentaUnaVezPorItemConValorUnico(): void
    {
        $facts = [
            1 => ['materia' => [10]],
            2 => ['materia' => [10]],
            3 => ['materia' => [20]],
        ];
        $counter = new DimensionCounter();
        $this->assertSame([10 => 2, 20 => 1], $counter->count($facts, 'materia'));
    }

    /** PEND-007/TASK-036: cardinalidad múltiple — un item cuenta en CADA valor que tiene. */
    public function testItemConDosValoresCuentaEnAmbos(): void
    {
        $facts = [
            1 => ['etapa' => [100, 200]],
        ];
        $counter = new DimensionCounter();
        $this->assertSame([100 => 1, 200 => 1], $counter->count($facts, 'etapa'));
    }

    public function testDimensionLiteralComoLicencia(): void
    {
        $facts = [
            1 => ['licencia' => 'ccbysa'],
            2 => ['licencia' => 'ccbysa'],
            3 => ['licencia' => null],
        ];
        $counter = new DimensionCounter();
        $this->assertSame(['ccbysa' => 2], $counter->count($facts, 'licencia'));
    }

    public function testDimensionAusenteEnUnaFilaSeIgnora(): void
    {
        $facts = [
            1 => ['materia' => [10]],
            2 => [],
        ];
        $counter = new DimensionCounter();
        $this->assertSame([10 => 1], $counter->count($facts, 'materia'));
    }
}
