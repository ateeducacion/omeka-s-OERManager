<?php

namespace OERManager\ColumnType;

use OERManager\Service\Governance\GovernanceColumns;

/**
 * Columna «Tipo de recurso» (lrmi:learningResourceType) con «Sin tipo» cuando
 * falta. Property fija: se puede añadir desde el selector de columnas sin
 * configurar nada (TASK-042).
 */
class ResourceType extends GovernanceValue
{
    protected const COLUMN = GovernanceColumns::RESOURCE_TYPE;
}
