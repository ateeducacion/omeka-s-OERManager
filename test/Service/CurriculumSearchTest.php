<?php

declare(strict_types=1);

namespace OERManager\Test\Service;

use OERManager\Service\CurriculumSearch;
use PHPUnit\Framework\TestCase;

/**
 * `CurriculumSearch::normalizeContextIds()` (TASK-035b): la sola pieza pura
 * del servicio, que no toca `Omeka\Api\Manager` y por eso es la única
 * testeable en host (ver «Limitación conocida del arnés» en project-memory).
 *
 * Nace del defecto reportado el 2026-08-25: con dos Cursos elegidos a la vez
 * (cardinalidad múltiple, PEND-007), el autocompletado de Materia solo se
 * acotaba por el PRIMERO — `firstChipId()` en el cliente y `(int) $context['level']`
 * aquí se quedaban con un único id. Esta función es la que ahora recibe el
 * contexto crudo (escalar o lista) y lo convierte en la lista de ids que
 * `searchDimension()` consulta uno por uno.
 */
final class CurriculumSearchTest extends TestCase
{
    public function testEscalarUnico(): void
    {
        $this->assertSame([5], CurriculumSearch::normalizeContextIds('5'));
        $this->assertSame([5], CurriculumSearch::normalizeContextIds(5));
    }

    public function testListaConservaElOrdenDeLlegada(): void
    {
        $this->assertSame([5, 12], CurriculumSearch::normalizeContextIds(['5', '12']));
    }

    /** El defecto exacto: dos cursos a la vez ya no pierde el segundo. */
    public function testDosCursosNoPierdeElSegundo(): void
    {
        $ids = CurriculumSearch::normalizeContextIds(['4676', '5047']);
        $this->assertCount(2, $ids);
        $this->assertContains(4676, $ids);
        $this->assertContains(5047, $ids);
    }

    public function testAusenteDaListaVacia(): void
    {
        $this->assertSame([], CurriculumSearch::normalizeContextIds(null));
    }

    public function testCeroYNegativosSeDescartan(): void
    {
        $this->assertSame([], CurriculumSearch::normalizeContextIds(0));
        $this->assertSame([], CurriculumSearch::normalizeContextIds('-3'));
        $this->assertSame([5], CurriculumSearch::normalizeContextIds(['0', '5', '-1']));
    }

    public function testNoNumericoSeDescarta(): void
    {
        $this->assertSame([], CurriculumSearch::normalizeContextIds(['', 'abc']));
    }

    public function testDuplicadosSeColapsan(): void
    {
        $this->assertSame([5], CurriculumSearch::normalizeContextIds(['5', '5', 5]));
    }
}
