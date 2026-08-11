<?php

declare(strict_types=1);

namespace OERManager\Service\Governance;

/**
 * Los tres valores de estado de alineamiento, sin ninguna dependencia del core.
 *
 * `OERManager\ColumnType\AlignmentStatus` es la fuente histórica, pero implementa
 * `Omeka\ColumnType\ColumnTypeInterface`: en PHP, leer una de sus constantes obliga a
 * autocargar y declarar la clase completa, lo que exige esa interfaz del core y revienta
 * en cualquier proceso PHP en host (fuera del contenedor de Omeka). Estos valores son el
 * contrato del filtro de la query y del atributo `data-*` de la fila en la vista maestra,
 * así que tienen que poder leerse desde código puro en host. Esta clase es esa única
 * fuente de verdad; `AlignmentStatus` referencia estas constantes en vez de duplicarlas.
 */
final class AlignmentStatusValue
{
    public const COMPLETE = 'complete';
    public const PARTIAL = 'partial';
    public const NONE = 'none';

    /**
     * Estado de anclaje a partir de las dimensiones presentes (ADR-0005 §4).
     *
     * **Ampliado el 2026-08-10 por decisión del propietario:** un curso del REA
     * al que ninguna de sus materias corresponde degrada el estado a `partial`.
     * Un curso huérfano no es un caso de visualización sino un **anclaje mal
     * hecho** — el REA declara un nivel educativo que su propio grafo de
     * materias no sostiene. Afecta a 7 de los 19 REA del catálogo real, que
     * hasta ahora se contaban como completos.
     *
     * `none` sigue mandando sobre todo lo demás: sin criterios ni saberes no
     * hay anclaje que graduar, y un curso huérfano no lo empeora ni lo mejora.
     *
     * La regla vive aquí, pura y probada en host; `ColumnType\AlignmentStatus`
     * es el proyector que resuelve las banderas desde la representación.
     */
    public static function fromFlags(
        bool $hasStage,
        bool $hasSubject,
        bool $hasCriteria,
        bool $hasSkills,
        bool $hasOrphanCourse
    ): string {
        if (!$hasCriteria && !$hasSkills) {
            return self::NONE;
        }

        if ($hasStage && $hasSubject && $hasCriteria && $hasSkills && !$hasOrphanCourse) {
            return self::COMPLETE;
        }

        return self::PARTIAL;
    }
}
