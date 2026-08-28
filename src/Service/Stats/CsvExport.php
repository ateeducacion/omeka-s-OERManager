<?php

declare(strict_types=1);

namespace OERManager\Service\Stats;

/**
 * Export CSV de los datos agregados (RF-007, TASK-006). Puro: recibe
 * cabeceras y filas ya formateadas — los mismos números que pintó el
 * gráfico, no un cálculo aparte.
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
        fputcsv($handle, $headers, ',', '"', '\\');
        foreach ($rows as $row) {
            fputcsv($handle, $row, ',', '"', '\\');
        }
        rewind($handle);
        $csv = (string) stream_get_contents($handle);
        fclose($handle);
        return $csv;
    }
}
