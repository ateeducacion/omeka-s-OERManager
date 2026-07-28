<?php

namespace OERManager\ColumnType;

/**
 * Tipo de columna «Visibilidad» disponible en la vista maestra (TASK-028).
 *
 * Los tipos del core declaran sus tipos de recurso a fuego y no hay evento para
 * ampliarlos, así que sin esta subclase el selector de columnas de la vista
 * maestra no ofrecería este tipo. Solo cambia getResourceTypes(): el
 * renderizado, el formulario de datos y la ordenación se heredan.
 */
class IsPublic extends \Omeka\ColumnType\IsPublic
{
    public function getResourceTypes(): array
    {
        return ['oer_items'];
    }
}
