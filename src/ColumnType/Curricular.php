<?php

namespace OERManager\ColumnType;

use Laminas\View\Renderer\PhpRenderer;
use OERManager\Service\Governance\CurricularSummary;
use Omeka\Api\Representation\AbstractEntityRepresentation;
use Omeka\Api\Representation\ItemRepresentation;
use Omeka\ColumnType\ColumnTypeInterface;

/**
 * Celda curricular: fusiona lrmi:educationalLevel (curso) y schema:about
 * (materia) en una sola columna, sustituyendo las dos que había (TASK-027 §3).
 *
 * Arregla dos defectos del estudio: deduplica el título repetido —el currículo
 * repite «Matemáticas» en cuatro cursos y la tabla pintaba cuatro chips
 * idénticos— y marca con ⚠ el valor LITERAL en una property de enlace (D2), que
 * hoy se pinta como enlace legítimo.
 *
 * Ese ⚠ es uno de los dos únicos glifos que ADR-0014 regla 3 autoriza en la
 * fila, porque pide una acción concreta y distinta de las demás: promover el
 * valor a enlace. La decisión de qué se marca vive en CurricularSummary; aquí
 * solo se pinta.
 */
class Curricular implements ColumnTypeInterface
{
    public const SUBJECT_TERM = 'schema:about';
    public const STAGE_TERM = 'lrmi:educationalLevel';

    public function getLabel(): string
    {
        return 'Curricular'; // @translate
    }

    public function getResourceTypes(): array
    {
        return ['oer_items'];
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

        $summary = CurricularSummary::summarise(
            $this->titlesFor($resource, self::SUBJECT_TERM),
            $this->titlesFor($resource, self::STAGE_TERM)
        );

        if ([] === $summary['subjects'] && '' === $summary['primaryStage']) {
            return null;
        }

        $escape = $view->plugin('escapeHtml');
        $translate = $view->plugin('translate');
        $parts = [];

        if ($summary['hasLiteral']) {
            $warning = $translate('Hay un valor literal donde debería haber un enlace al item-término'); // @translate
            $parts[] = sprintf(
                '<span class="oer-curricular-literal" title="%s"><span class="oer-visually-hidden">%s</span></span>',
                $escape($warning),
                $escape($warning)
            );
        }

        if ([] !== $summary['subjects']) {
            $parts[] = '<span class="oer-curricular-subject">'
                . $escape(implode(', ', $summary['subjects'])) . '</span>';
        }

        if ('' !== $summary['primaryStage']) {
            $stage = $summary['primaryStage'];
            if ($summary['extraStages'] > 0) {
                $stage .= sprintf(
                    $view->translate(' (+%s cursos)'), // @translate
                    $summary['extraStages']
                );
            }
            $parts[] = '<span class="oer-curricular-stage">' . $escape($stage) . '</span>';
        }

        return sprintf(
            '<span class="oer-curricular" title="%s">%s</span>',
            $escape($summary['tooltip']),
            implode(' ', $parts)
        );
    }

    /**
     * El título de un enlace es el del item destino; el de un literal, su propio
     * texto. valueResource() aquí SÍ se paga: es lo que la celda muestra, no una
     * comprobación evitable como la de D-7.
     *
     * @return list<array{title:string, isLiteral:bool}>
     */
    private function titlesFor(ItemRepresentation $item, string $term): array
    {
        $out = [];
        foreach ($item->value($term, ['all' => true, 'default' => []]) as $value) {
            $isLiteral = !str_starts_with($value->type(), 'resource');
            if ($isLiteral) {
                $out[] = ['title' => (string) $value->value(), 'isLiteral' => true];
                continue;
            }
            $target = $value->valueResource();
            $out[] = [
                'title' => $target ? (string) $target->displayTitle() : '',
                'isLiteral' => false,
            ];
        }
        return $out;
    }
}
