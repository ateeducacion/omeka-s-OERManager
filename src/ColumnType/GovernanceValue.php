<?php

namespace OERManager\ColumnType;

use Laminas\View\Renderer\PhpRenderer;
use Omeka\Api\Representation\AbstractEntityRepresentation;
use Omeka\Api\Representation\ItemRepresentation;
use Omeka\ColumnType\ColumnTypeInterface;
use OERManager\Service\Governance\GovernanceColumns;
use OERManager\Service\Governance\ValueText;

/**
 * Valor de una property con ESTADO VACÍO EXPLÍCITO (TASK-027 §3).
 *
 * Desde TASK-042 lo usan dos subtipos con property fija, `Licence` y
 * `ResourceType`, que son los que ofrece el selector de columnas. Este tipo
 * genérico —property y texto de vacío en los datos de la columna— queda solo
 * para pintar las selecciones ya guardadas con él: sin formulario de datos, en
 * el selector era una trampa (se añadía una columna sin property que mostrar).
 *
 * La aportación frente a ColumnType\Value es la ausencia: hoy la celda vacía no
 * dice nada, y con 18 de 19 REA sin licencia eso era justo la información que
 * faltaba. El estado vacío va en tinta apagada y con texto, SIN glifo: ADR-0014
 * reserva la marca por forma a lo que dispara una acción distinta, y una marca
 * presente en el 90 % de las filas deja de señalar la excepción.
 */
class GovernanceValue implements ColumnTypeInterface
{
    /** Clave de GovernanceColumns del subtipo; null en el genérico. */
    protected const COLUMN = null;

    public function getLabel(): string
    {
        return GovernanceColumns::label(static::COLUMN) ?? 'Valor con estado vacío'; // @translate
    }

    /**
     * El genérico no se ofrece en el selector (TASK-042). El core solo usa esto
     * para construir el selector: una selección guardada con este tipo se sigue
     * pintando mientras el tipo esté registrado (`columnTypeIsKnown()`).
     */
    public function getResourceTypes(): array
    {
        return null === static::COLUMN ? [] : ['oer_items'];
    }

    public function getMaxColumns(): ?int
    {
        return null === static::COLUMN ? null : 1;
    }

    public function renderDataForm(PhpRenderer $view, array $data): string
    {
        return '';
    }

    public function getSortBy(array $data): ?string
    {
        return GovernanceColumns::resolve(static::COLUMN, $data)['property_term'] ?? null;
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

        $data = GovernanceColumns::resolve(static::COLUMN, $data);
        $term = (string) ($data['property_term'] ?? '');
        if ('' === $term) {
            return null;
        }

        $escape = $view->plugin('escapeHtml');
        $values = $resource->value($term, ['all' => true, 'default' => []]);

        $texts = [];
        foreach ($values as $value) {
            // (string) $value es la etiqueta; una licencia URI sin etiqueta
            // daría '' y la celda diría «Sin licencia» (ADR-0019).
            $text = ValueText::of((string) $value, $value->uri());
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
