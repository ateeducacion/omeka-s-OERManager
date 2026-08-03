<?php

declare(strict_types=1);

namespace OERManager\Service\Governance;

/**
 * Resumen de la celda curricular: fusiona lrmi:educationalLevel (curso) y
 * schema:about (materia) en una sola columna (TASK-027 §3).
 *
 * Deduplica por título porque el currículo repite la misma materia en varios
 * cursos —«Matemáticas» aparece en cuatro— y la tabla pintaba un chip por cada
 * uno. NO empareja materia con curso: hacerlo exigiría recorrer el grafo
 * curricular en cada fila, y la pregunta que la celda responde («¿de qué va
 * este REA?») no lo necesita.
 *
 * Pura a propósito: la resolución de valores a títulos la hace el ColumnType.
 */
final class CurricularSummary
{
    /**
     * @param list<array{title:string, isLiteral:bool}> $subjects schema:about
     * @param list<array{title:string, isLiteral:bool}> $stages   lrmi:educationalLevel
     * @return array{subjects:list<string>, primaryStage:string, extraStages:int, hasLiteral:bool, tooltip:string}
     */
    public static function summarise(array $subjects, array $stages): array
    {
        $hasLiteral = false;
        foreach ([...$subjects, ...$stages] as $value) {
            if ($value['isLiteral'] && '' !== trim($value['title'])) {
                $hasLiteral = true;
                break;
            }
        }

        $subjectTitles = self::uniqueTitles($subjects);
        $stageTitles = self::uniqueTitles($stages);

        $tooltip = '';
        if ([] !== $subjectTitles || [] !== $stageTitles) {
            $tooltip = trim(
                implode(', ', $subjectTitles)
                . ([] !== $stageTitles ? ' — ' . implode(', ', $stageTitles) : '')
            );
        }

        return [
            'subjects' => $subjectTitles,
            'primaryStage' => $stageTitles[0] ?? '',
            'extraStages' => max(0, count($stageTitles) - 1),
            'hasLiteral' => $hasLiteral,
            'tooltip' => $tooltip,
        ];
    }

    /**
     * @param list<array{title:string, isLiteral:bool}> $values
     * @return list<string>
     */
    private static function uniqueTitles(array $values): array
    {
        $titles = [];
        foreach ($values as $value) {
            $title = trim($value['title']);
            if ('' !== $title && !in_array($title, $titles, true)) {
                $titles[] = $title;
            }
        }
        return $titles;
    }
}
