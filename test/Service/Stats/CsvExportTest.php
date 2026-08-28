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

    public function testSanitizaFormulaInyeccionConIgual(): void
    {
        $csv = (new CsvExport())->toCsv(['titulo'], [['=cmd|\'test\'']]);
        $this->assertStringContainsString("'=cmd|", $csv);
    }

    public function testSanitizaFormulaInyeccionConMas(): void
    {
        $csv = (new CsvExport())->toCsv(['formula'], [['+1+1']]);
        $this->assertStringContainsString("'+1+1", $csv);
    }

    public function testSanitizaFormulaInyeccionConArroba(): void
    {
        $csv = (new CsvExport())->toCsv(['referencia'], [['@SUM(A1:A10)']]);
        $this->assertStringContainsString("'@SUM", $csv);
    }

    public function testNormalValoresNoSanitizados(): void
    {
        $csv = (new CsvExport())->toCsv(['materia', 'conteo'], [
            ['Matemáticas', 3],
            ['Lengua', 1],
        ]);
        $this->assertStringContainsString('Matemáticas', $csv);
        $this->assertStringNotContainsString("'Matemáticas", $csv);
    }

    public function testNumerosSinSanitizacion(): void
    {
        $csv = (new CsvExport())->toCsv(['numero'], [[42]]);
        $lines = preg_split('/\r\n|\n/', trim($csv));
        $this->assertSame('42', $lines[1]);
    }
}
