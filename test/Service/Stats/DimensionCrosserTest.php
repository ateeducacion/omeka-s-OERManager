<?php

declare(strict_types=1);

namespace OERManager\Test\Service\Stats;

use OERManager\Service\Stats\DimensionCrosser;
use PHPUnit\Framework\TestCase;

final class DimensionCrosserTest extends TestCase
{
    public function testCatalogoVacioDaTablaVacia(): void
    {
        $crosser = new DimensionCrosser();
        $this->assertSame([], $crosser->cross([], 'materia', 'etapa'));
    }

    public function testCruceSimpleUnValorPorDimension(): void
    {
        $facts = [
            1 => ['materia' => [10], 'etapa' => [100]],
            2 => ['materia' => [10], 'etapa' => [100]],
            3 => ['materia' => [20], 'etapa' => [200]],
        ];
        $crosser = new DimensionCrosser();
        $this->assertSame(
            [10 => [100 => 2], 20 => [200 => 1]],
            $crosser->cross($facts, 'materia', 'etapa')
        );
    }

    /** PEND-007/TASK-036: un item con 2 cursos cruza con CADA curso. */
    public function testItemConVariosValoresCruzaConCadaUno(): void
    {
        $facts = [
            1 => ['materia' => [10], 'etapa' => [100, 200]],
        ];
        $crosser = new DimensionCrosser();
        $this->assertSame(
            [10 => [100 => 1, 200 => 1]],
            $crosser->cross($facts, 'materia', 'etapa')
        );
    }

    public function testDimensionLiteralEnElCruce(): void
    {
        $facts = [
            1 => ['materia' => [10], 'licencia' => 'ccbysa'],
        ];
        $crosser = new DimensionCrosser();
        $this->assertSame(
            [10 => ['ccbysa' => 1]],
            $crosser->cross($facts, 'materia', 'licencia')
        );
    }

    public function testValorAusenteEnUnaDimensionNoAporta(): void
    {
        $facts = [
            1 => ['materia' => [10], 'etapa' => []],
        ];
        $crosser = new DimensionCrosser();
        $this->assertSame([], $crosser->cross($facts, 'materia', 'etapa'));
    }
}
