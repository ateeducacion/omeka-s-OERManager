<?php

declare(strict_types=1);

namespace OERManager\Service\Governance;

/**
 * Tinte decorativo de la píldora de materia en «Anclaje curricular» (TASK-046,
 * addendum de ADR-0014 del 2026-10-03).
 *
 * Se calcula sobre el NOMBRE de la materia, no sobre el id del item-término: una
 * Asignatura lleva su propio curso (ADR-0009), así que la misma materia existe
 * como un item distinto por curso («Educación física» son 11 items en el
 * catálogo real) y un hash del id la pintaría de 11 colores en la misma columna.
 * El nombre se normaliza (mayúsculas, tildes, espacios) para que «Educación
 * física» y «EDUCACION FISICA» caigan en el mismo tinte.
 *
 * Es una ayuda de agrupación sin significado: dos materias pueden compartir
 * tinte y nada puede leerse solo del color.
 */
final class SubjectTint
{
    /** Debe coincidir con los tokens `--oer-tint-1..8` de oer-master-view.css. */
    public const PALETTE_SIZE = 8;

    /** @return int Índice 1..PALETTE_SIZE */
    public static function indexFor(string $subject): int
    {
        return (crc32(self::normalise($subject)) % self::PALETTE_SIZE) + 1;
    }

    private static function normalise(string $subject): string
    {
        if (class_exists(\Normalizer::class)) {
            $subject = \Normalizer::normalize($subject, \Normalizer::FORM_D) ?: $subject;
        }
        $subject = (string) preg_replace('/\p{Mn}+/u', '', $subject);
        $subject = (string) preg_replace('/[\p{Z}\s]+/u', ' ', $subject);

        return mb_strtolower(trim($subject));
    }
}
