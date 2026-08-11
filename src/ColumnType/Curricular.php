<?php

namespace OERManager\ColumnType;

use Laminas\View\Renderer\PhpRenderer;
use OERManager\Service\Governance\AlignmentStatusValue;
use OERManager\Service\Governance\CurricularPairs;
use OERManager\Service\Governance\CurricularSummary;
use Omeka\Api\Representation\AbstractEntityRepresentation;
use Omeka\Api\Representation\ItemRepresentation;
use Omeka\ColumnType\ColumnTypeInterface;

/**
 * Celda «Anclaje curricular»: funde en una sola columna el estado de anclaje y
 * los pares materia→curso a los que el REA está vinculado.
 *
 * Sustituye a las dos columnas de la rebanada 2 (Anclaje + Curricular), que
 * gastaban el doble de ancho para decir menos: la de Curricular deduplicaba
 * materias por un lado y cursos por otro, de modo que un REA con «Biología y
 * Geología» en 1º ESO y «Descubrimiento…» en Infantil los mezclaba insinuando
 * que Biología estaba en Infantil (item 4362 del catálogo real).
 *
 * **Sin ✓ cuando está bien.** Es la saturación invertida de ADR-0014 llevada
 * hasta el final: la vista se recorre buscando lo que falta, así que lo
 * correcto no gasta tinta. Solo se marca lo que reclama una acción:
 *   ⚠ anclaje incompleto · ✗ sin anclar · ⚠ valor literal donde debería enlazar.
 *
 * El par se deriva sin consultas de grafo nuevas: la Asignatura lleva su propio
 * curso (ADR-0009) y esa arista se recorre sobre la representación que ya se
 * tenía — el mismo camino que `RecatalogService::qualifiedTitle()`.
 */
class Curricular implements ColumnTypeInterface
{
    public function getLabel(): string
    {
        return 'Anclaje curricular'; // @translate
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

    /** Un estado calculado en PHP no es ordenable en SQL. */
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

        $escape = $view->plugin('escapeHtml');
        $translate = $view->plugin('translate');
        $resolved = CurricularPairs::of($resource);
        $summary = CurricularSummary::summarise($resolved['pairs'], $resolved['orphanCourses']);
        $status = AlignmentStatus::statusFor($resource);

        $lines = [];

        $badge = $this->badge($view, $status, $summary['hasLiteral'], [] !== $summary['orphanCourses']);
        if ('' !== $badge) {
            $lines[] = $badge;
        }

        foreach ($summary['groups'] as $group) {
            $courses = CurricularSummary::abbreviateCourses($group['courses']);
            $line = '<span class="oer-curricular-subject">' . $escape($group['subject']) . '</span>';
            if ('' !== $courses) {
                $line .= '<span class="oer-curricular-stage">' . $escape($courses) . '</span>';
            }
            $lines[] = '<span class="oer-curricular-group">' . $line . '</span>';
        }

        if ([] !== $summary['orphanCourses']) {
            $lines[] = sprintf(
                '<span class="oer-curricular-group oer-curricular-orphan">'
                    . '<span class="oer-curricular-subject">%s</span>'
                    . '<span class="oer-curricular-stage">%s</span></span>',
                $escape($translate('Sin materia')), // @translate
                $escape(CurricularSummary::abbreviateCourses($summary['orphanCourses']))
            );
        }

        if ([] === $lines) {
            return null;
        }

        return sprintf(
            '<span class="oer-curricular" title="%s">%s</span>',
            $escape($summary['tooltip']),
            implode('', $lines)
        );
    }

    /**
     * Marca solo lo accionable (ADR-0014 regla 3). Un anclaje completo y sin
     * literales no produce ningún glifo.
     */
    private function badge(PhpRenderer $view, string $status, bool $hasLiteral, bool $hasOrphan): string
    {
        $escape = $view->plugin('escapeHtml');
        $translate = $view->plugin('translate');

        if (AlignmentStatusValue::NONE === $status) {
            $label = $translate('Sin anclar'); // @translate
            return sprintf(
                '<span class="oer-anchor-flag oer-anchor-none" title="%s">'
                    . '<span class="oer-visually-hidden">%s</span></span>',
                $escape($label),
                $escape($label)
            );
        }

        $flags = [];
        if (AlignmentStatusValue::PARTIAL === $status) {
            $flags[] = $translate('Anclaje incompleto'); // @translate
        }
        if ($hasOrphan) {
            $flags[] = $translate('Hay cursos que ninguna materia del REA sostiene'); // @translate
        }
        if ($hasLiteral) {
            $flags[] = $translate('Hay un valor literal donde debería haber un enlace al item-término'); // @translate
        }
        if ([] === $flags) {
            return '';
        }

        $label = implode('. ', $flags);

        return sprintf(
            '<span class="oer-anchor-flag oer-anchor-partial" title="%s">'
                . '<span class="oer-visually-hidden">%s</span></span>',
            $escape($label),
            $escape($label)
        );
    }
}
