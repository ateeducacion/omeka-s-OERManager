<?php

declare(strict_types=1);

namespace OERManager\Service\Ai;

/**
 * Ciclos LOMLOE de Infantil y Primaria (RD 95/2022, RD 157/2022).
 *
 * Decisión del propietario (2026-10-08): en Primaria un REA se alinea con los
 * dos cursos de su ciclo, y los saberes y criterios se trabajan dentro del
 * ciclo, no con precisión de curso. Dos hojas son gemelas de ciclo si solo
 * cambia el curso dentro del mismo ciclo: PMAT03CE4.1 y PMAT04CE4.1.
 *
 * Los códigos del currículo llevan el curso en dos dígitos tras la etapa (P/I)
 * y el área: P + MAT + 04 + CE + 4.1. ESO y Bachillerato no tienen ciclo: sus
 * cursos y hojas se comparan tal cual.
 */
final class CurriculumCycle
{
    /** Ciclo de un curso por su título («4º Primaria» → «Primaria-2»), o null. */
    public static function courseKey(string $courseTitle): ?string
    {
        if (1 !== preg_match('/^\s*(\d+)º\s+(Primaria|Infantil)\b/u', $courseTitle, $m)) {
            return null;
        }
        $cycle = self::cycle('Primaria' === $m[2] ? 'P' : 'I', (int) $m[1]);
        return null === $cycle ? null : $m[2] . '-' . $cycle;
    }

    /**
     * Clave de una hoja (saber o criterio) que iguala a sus gemelas de ciclo;
     * sin ciclo reconocible, su propio código.
     */
    public static function leafKey(string $code): string
    {
        // El catálogo guarda el identificador con prefijo de tipo: «sb:…», «crit:…».
        $code = (string) preg_replace('/^[a-z]+:/i', '', trim($code));
        if (1 !== preg_match('/^([PI])([A-Z0-9]{3})(\d{2})(SB|CE)(.+)$/', $code, $m)) {
            return $code;
        }
        $cycle = self::cycle($m[1], (int) $m[3]);
        return null === $cycle ? $code : $m[1] . $m[2] . '~' . $cycle . $m[4] . $m[5];
    }

    private static function cycle(string $stage, int $course): ?int
    {
        return match (true) {
            'P' === $stage && $course >= 1 && $course <= 6 => intdiv($course + 1, 2),
            'I' === $stage && $course >= 1 && $course <= 6 => $course <= 3 ? 1 : 2,
            default => null,
        };
    }
}
