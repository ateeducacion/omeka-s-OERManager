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
}
