<?php

namespace OERManager\ColumnType;

use Laminas\View\Renderer\PhpRenderer;
use Omeka\Api\Representation\AbstractEntityRepresentation;
use Omeka\Api\Representation\ItemRepresentation;
use Omeka\ColumnType\ColumnTypeInterface;

/**
 * Indicador ligero de alineamiento curricular para la vista maestra v1
 * (TASK-003, ADR-0005 §4). La comprobación de integridad completa es
 * TASK-005.
 */
class AlignmentStatus implements ColumnTypeInterface
{
    public const COMPLETE = 'complete';
    public const PARTIAL = 'partial';
    public const NONE = 'none';

    public function getLabel(): string
    {
        return 'Alineamiento'; // @translate
    }

    public function getResourceTypes(): array
    {
        // 'items' se conserva: quitarlo retiraría la posibilidad, hoy
        // existente, de añadir esta columna al browse nativo de items.
        return ['items', 'oer_items'];
    }

    public function getMaxColumns(): ?int
    {
        return 1;
    }

    public function renderDataForm(PhpRenderer $view, array $data): string
    {
        return '';
    }

    public function getSortBy(array $data): ?string
    {
        return null;
    }

    public function renderHeader(PhpRenderer $view, array $data): string
    {
        return $view->translate($this->getLabel());
    }

    public function renderContent(PhpRenderer $view, AbstractEntityRepresentation $resource, array $data): ?string
    {
        if (!$resource instanceof ItemRepresentation) {
            return null;
        }
        $labels = [
            self::COMPLETE => $view->translate('Completo'), // @translate
            self::PARTIAL => $view->translate('Parcial'), // @translate
            self::NONE => $view->translate('Sin alinear'), // @translate
        ];
        $status = self::statusFor($resource);
        $escape = $view->plugin('escapeHtml');
        return sprintf(
            '<span class="oer-alignment-status oer-alignment-status-%s">%s</span>',
            $escape($status),
            $escape($labels[$status])
        );
    }

    /**
     * Calcula el estado de alineamiento de un item (ADR-0005 §4):
     * completo = etapa + materia + ≥1 criterio + ≥1 saber;
     * sin alinear = sin criterios ni saberes; parcial = el resto.
     */
    public static function statusFor(ItemRepresentation $item): string
    {
        $hasCriteria = (bool) $item->value('lrmi:assesses');
        $hasSkills = (bool) $item->value('lrmi:teaches');

        if (!$hasCriteria && !$hasSkills) {
            return self::NONE;
        }

        $hasStage = (bool) $item->value('lrmi:educationalLevel');
        $hasSubject = (bool) $item->value('schema:about');
        if ($hasStage && $hasSubject && $hasCriteria && $hasSkills) {
            return self::COMPLETE;
        }

        return self::PARTIAL;
    }
}
