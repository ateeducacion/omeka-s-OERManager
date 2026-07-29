<?php

namespace OERManager\ColumnType;

use Laminas\View\Renderer\PhpRenderer;
use Omeka\Api\Representation\AbstractEntityRepresentation;

/**
 * Tipo de columna «Visibilidad» disponible en la vista maestra (TASK-028).
 *
 * Los tipos del core declaran sus tipos de recurso a fuego y no hay evento para
 * ampliarlos, así que sin esta subclase el selector de columnas de la vista
 * maestra no ofrecería este tipo. Además rotula y pinta el estado en el idioma
 * y el registro del módulo: el core devuelve «Is public» sin traducir y un
 * «Sí»/«No» suelto que no se distingue del resto de metadatos de la fila. El
 * formulario de datos y la ordenación se heredan.
 */
class IsPublic extends \Omeka\ColumnType\IsPublic
{
    public const PUBLIC_CLASS = 'oer-visibility-public';
    public const PRIVATE_CLASS = 'oer-visibility-private';

    public function getResourceTypes(): array
    {
        return ['oer_items'];
    }

    public function getLabel(): string
    {
        return 'Visibilidad'; // @translate
    }

    public function renderHeader(PhpRenderer $view, array $data): string
    {
        return $view->translate($this->getLabel());
    }

    public function renderContent(PhpRenderer $view, AbstractEntityRepresentation $resource, array $data): ?string
    {
        $isPublic = $resource->isPublic();
        $escape = $view->plugin('escapeHtml');
        $label = $isPublic
            ? $view->translate('Público') // @translate
            : $view->translate('Privado'); // @translate

        // El mismo marcado que repinta asset/js/ui/visibility.js tras un cambio
        // de visibilidad en lote: si cambia aquí, cambia allí.
        return sprintf(
            '<span class="oer-visibility %s">%s</span>',
            $isPublic ? self::PUBLIC_CLASS : self::PRIVATE_CLASS,
            $escape($label)
        );
    }
}
