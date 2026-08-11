<?php

namespace OERManager\ColumnType;

use Laminas\View\Renderer\PhpRenderer;
use Omeka\Api\Representation\AbstractEntityRepresentation;
use Omeka\Api\Representation\ItemRepresentation;
use Omeka\ColumnType\ColumnTypeInterface;

/**
 * Valor de una property con ESTADO VACÍO EXPLÍCITO (TASK-027 §3).
 *
 * Sirve a Licencia (dcterms:rights) y a Tipo de recurso
 * (lrmi:learningResourceType): el mismo problema, un solo tipo registrado y
 * usado dos veces con `property_term` distinto.
 *
 * La aportación frente a ColumnType\Value es la ausencia: hoy la celda vacía no
 * dice nada, y con 18 de 19 REA sin licencia eso era justo la información que
 * faltaba. El estado vacío va en tinta apagada y con texto, SIN glifo: ADR-0014
 * reserva la marca por forma a lo que dispara una acción distinta, y una marca
 * presente en el 90 % de las filas deja de señalar la excepción.
 */
class GovernanceValue implements ColumnTypeInterface
{
    public function getLabel(): string
    {
        return 'Valor con estado vacío'; // @translate
    }

    public function getResourceTypes(): array
    {
        return ['oer_items'];
    }

    public function getMaxColumns(): ?int
    {
        return null;
    }

    public function renderDataForm(PhpRenderer $view, array $data): string
    {
        return '';
    }

    public function getSortBy(array $data): ?string
    {
        return $data['property_term'] ?? null;
    }

    public function renderHeader(PhpRenderer $view, array $data): string
    {
        return $view->translate($data['header'] ?? $this->getLabel());
    }

    public function renderContent(PhpRenderer $view, AbstractEntityRepresentation $resource, array $data): ?string
    {
        if (!$resource instanceof ItemRepresentation) {
            return null;
        }

        $term = (string) ($data['property_term'] ?? '');
        if ('' === $term) {
            return null;
        }

        $escape = $view->plugin('escapeHtml');
        $values = $resource->value($term, ['all' => true, 'default' => []]);

        $texts = [];
        foreach ($values as $value) {
            $text = trim((string) $value);
            if ('' !== $text) {
                $texts[] = $text;
            }
        }

        if ([] === $texts) {
            return sprintf(
                '<span class="oer-value-missing">%s</span>',
                $escape($view->translate($data['empty_label'] ?? 'Sin valor')) // @translate
            );
        }

        return sprintf('<span class="oer-value">%s</span>', $escape(implode(', ', $texts)));
    }
}
