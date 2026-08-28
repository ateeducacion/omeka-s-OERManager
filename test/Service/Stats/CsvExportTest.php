<?php

declare(strict_types=1);

namespace OERManager\Test\Service\Stats;

use OERManager\Service\Stats\CsvExport;
use PHPUnit\Framework\TestCase;

/** Formateador CSV puro (RF-007, TASK-006): mismos datos que pintó el gráfico, sin cálculo aparte. */
final class CsvExportTest extends TestCase
{
    public function testCabeceraYFilas(): void
    {
        $csv = (new CsvExport())->toCsv(['materia', 'conteo'], [
            ['Matemáticas', 3],
            ['Lengua', 1],
        ]);
        $lines = preg_split('/\r\n|\n/', trim($csv));
        $this->assertSame('materia,conteo', $lines[0]);
        $this->assertSame('Matemáticas,3', $lines[1]);
        $this->assertSame('Lengua,1', $lines[2]);
    }

    public function testSoloCabeceraSinFilas(): void
    {
        $csv = (new CsvExport())->toCsv(['estado', 'conteo'], []);
        $this->assertSame('estado,conteo', trim($csv));
    }

    public function testEscapaComasYComillas(): void
    {
        $csv = (new CsvExport())->toCsv(['a', 'b'], [['uno, dos', 'con "comillas"']]);
        $this->assertStringContainsString('"uno, dos"', $csv);
        $this->assertStringContainsString('"con ""comillas"""', $csv);
    }
}
