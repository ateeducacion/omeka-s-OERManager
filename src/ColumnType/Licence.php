<?php

namespace OERManager\ColumnType;

use OERManager\Service\Governance\GovernanceColumns;

/**
 * Columna «Licencia» (dcterms:license, ADR-0019) con «Sin licencia» cuando falta.
 * Property fija: se puede añadir desde el selector de columnas sin configurar
 * nada (TASK-042).
 */
class Licence extends GovernanceValue
{
    protected const COLUMN = GovernanceColumns::LICENCE;
}
