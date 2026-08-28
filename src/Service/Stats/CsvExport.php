<?php

declare(strict_types=1);

namespace OERManager\Service\Stats;

/**
 * Export CSV de los datos agregados (RF-007, TASK-006). Puro: recibe
 * cabeceras y filas ya formateadas — los mismos números que pintó el
 * gráfico, no un cálculo aparte.
 *
 * Nota: Mitiga CWE-1236 (CSV formula injection) sanitizando valores que
 * comienzan con caracteres interpretados como inicio de fórmula por apps
 * de hojas de cálculo (=, +, -, @, tab, CR).
 */
final class CsvExport
{
    /**
     * @param list<string> $headers
     * @param list<list<int|string>> $rows
     */
    public function toCsv(array $headers, array $rows): string
    {
        $handle = fopen('php://temp', 'r+');
        $sanitizedHeaders = array_map([$this, 'sanitizeCell'], $headers);
        fputcsv($handle, $sanitizedHeaders, ',', '"', '\\');
        foreach ($rows as $row) {
            $sanitizedRow = array_map([$this, 'sanitizeCell'], $row);
            fputcsv($handle, $sanitizedRow, ',', '"', '\\');
        }
        rewind($handle);
        $csv = (string) stream_get_contents($handle);
        fclose($handle);
        return $csv;
    }

    /**
     * Sanitizes a cell value against CSV formula injection (CWE-1236).
     * If the value is a string starting with a formula indicator character
     * (=, +, -, @, tab, carriage return), prefixes it with an apostrophe
     * so spreadsheet applications render it as literal text.
     *
     * @param int|string $value
     */
    private function sanitizeCell(int|string $value): int|string
    {
        if (!is_string($value) || $value === '') {
            return $value;
        }

        $firstChar = $value[0];
        if (
            $firstChar === '=' || $firstChar === '+' || $firstChar === '-'
            || $firstChar === '@' || $firstChar === "\t" || $firstChar === "\r"
        ) {
            return "'" . $value;
        }

        return $value;
    }
}
