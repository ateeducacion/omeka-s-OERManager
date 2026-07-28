<?php

namespace OERManager\ColumnType;

/**
 * Tipo de columna «Plantilla de recurso» disponible en la vista maestra
 * (TASK-028).
 *
 * Los tipos del core declaran sus tipos de recurso a fuego y no hay evento para
 * ampliarlos, así que sin esta subclase el selector de columnas de la vista
 * maestra no ofrecería este tipo. Solo cambia getResourceTypes(): el
 * renderizado, el formulario de datos y la ordenación se heredan.
 */
class ResourceTemplate extends \Omeka\ColumnType\ResourceTemplate
{
    public function getResourceTypes(): array
    {
        return ['oer_items'];
    }
}
