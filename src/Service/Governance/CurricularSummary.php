<?php

declare(strict_types=1);

namespace OERManager\Service\Governance;

/**
 * Resumen de la celda «Anclaje curricular»: los PARES materia→curso a los que
 * el REA está vinculado, agrupados por materia.
 *
 * Sustituye a la versión de la rebanada 2, que deduplicaba materias por un lado
 * y cursos por otro. Aquello no solo gastaba dos columnas: producía una lectura
 * **falsa**. El item 4362 del catálogo real tiene «Biología y Geología» en 1º
 * ESO y «Descubrimiento y exploración del entorno» en tres cursos de Infantil,
 * y la celda plana los mezclaba insinuando que Biología estaba en Infantil.
 *
 * El par es derivable sin coste de grafo: la Asignatura lleva su propio curso
 * (ADR-0009), y esa arista se recorre sobre la representación ya cargada —el
 * mismo recorrido que `RecatalogService::qualifiedTitle()` ya hacía para el
 * diff del re-catalogador.
 *
 * **Los cursos huérfanos no se pierden.** 7 de los 19 REA del catálogo tienen
 * un curso que ninguna de sus materias cubre; una vista de solo pares los
 * habría descartado en silencio en el 37 % del catálogo.
 *
 * Pura a propósito: la resolución de valores a títulos la hace el ColumnType.
 */
final class CurricularSummary
{
    /**
     * @param list<array{subject:string, course:string, isLiteral:bool}> $pairs
     * @param list<string> $orphanCourses Cursos del item que ninguna materia cubre
     * @return array{
     *     groups: list<array{subject:string, courses:list<string>, isLiteral:bool}>,
     *     orphanCourses: list<string>,
     *     hasLiteral: bool,
     *     tooltip: string
     * }
     */
    public static function summarise(array $pairs, array $orphanCourses): array
    {
        $hasLiteral = false;
        $groups = [];

        foreach ($pairs as $pair) {
            if (!empty($pair['isLiteral'])) {
                $hasLiteral = true;
            }

            $subject = trim((string) ($pair['subject'] ?? ''));
            if ('' === $subject) {
                continue;
            }

            if (!isset($groups[$subject])) {
                $groups[$subject] = [
                    'subject' => $subject,
                    'courses' => [],
                    'isLiteral' => false,
                ];
            }
            if (!empty($pair['isLiteral'])) {
                $groups[$subject]['isLiteral'] = true;
            }

            $course = trim((string) ($pair['course'] ?? ''));
            if ('' !== $course && !in_array($course, $groups[$subject]['courses'], true)) {
                $groups[$subject]['courses'][] = $course;
            }
        }

        $groups = array_values($groups);
        $orphans = self::uniqueNonEmpty($orphanCourses);

        return [
            'groups' => $groups,
            'orphanCourses' => $orphans,
            'hasLiteral' => $hasLiteral,
            'tooltip' => self::tooltip($groups, $orphans),
        ];
    }

    /**
     * Une los cursos de una materia, factorizando la cola común cuando hacerlo
     * no pierde información.
     *
     * «3º Primaria, 4º Primaria, 5º Primaria» → «3º · 4º · 5º Primaria».
     *
     * Solo se factoriza si lo que queda delante de la cola común es **una sola
     * palabra** por curso. Sin esa guarda, «4º Infantil de 3 años» y «5º
     * Infantil de 4 años» —que comparten «años»— darían «4º Infantil de 3 · 5º
     * Infantil de 4 años», que es más largo y además ilegible.
     *
     * @param list<string> $courses
     */
    public static function abbreviateCourses(array $courses): string
    {
        $courses = array_values(array_filter(array_map('trim', $courses), static fn (string $c): bool => '' !== $c));

        if (count($courses) < 2) {
            return $courses[0] ?? '';
        }

        $words = array_map(static fn (string $c): array => preg_split('/\s+/', $c) ?: [], $courses);

        // Cola común, contada por palabras desde el final.
        $common = 0;
        $shortest = min(array_map('count', $words));
        while ($common < $shortest - 1) {
            $candidate = $words[0][count($words[0]) - 1 - $common];
            foreach ($words as $parts) {
                if ($parts[count($parts) - 1 - $common] !== $candidate) {
                    break 2;
                }
            }
            $common++;
        }

        if (0 === $common) {
            return implode(' · ', $courses);
        }

        $heads = [];
        foreach ($words as $parts) {
            $head = array_slice($parts, 0, count($parts) - $common);
            if (1 !== count($head)) {
                // La parte distintiva no cabe en una palabra: no se factoriza.
                return implode(' · ', $courses);
            }
            $heads[] = $head[0];
        }

        $tail = implode(' ', array_slice($words[0], count($words[0]) - $common));

        return implode(' · ', $heads) . ' ' . $tail;
    }

    /**
     * @param list<array{subject:string, courses:list<string>, isLiteral:bool}> $groups
     * @param list<string> $orphans
     */
    private static function tooltip(array $groups, array $orphans): string
    {
        $parts = [];
        foreach ($groups as $group) {
            $parts[] = [] === $group['courses']
                ? $group['subject']
                : $group['subject'] . ': ' . implode(', ', $group['courses']);
        }
        if ([] !== $orphans) {
            $parts[] = 'Sin materia: ' . implode(', ', $orphans); // @translate
        }

        return implode(' — ', $parts);
    }

    /**
     * @param list<string> $values
     * @return list<string>
     */
    private static function uniqueNonEmpty(array $values): array
    {
        $out = [];
        foreach ($values as $value) {
            $value = trim((string) $value);
            if ('' !== $value && !in_array($value, $out, true)) {
                $out[] = $value;
            }
        }
        return $out;
    }
}
