<?php

namespace OERManager\ColumnType;

use Laminas\View\Renderer\PhpRenderer;
use Omeka\Api\Representation\AbstractEntityRepresentation;
use Omeka\Api\Representation\ItemRepresentation;
use Omeka\ColumnType\ColumnTypeInterface;
use OERManager\Service\Governance\AlignmentStatusValue;
use OERManager\Service\Governance\CurricularPairs;

/**
 * Indicador ligero de alineamiento curricular para la vista maestra v1
 * (TASK-003, ADR-0005 §4). La comprobación de integridad completa es
 * TASK-005.
 *
 * Las tres constantes de estado apuntan a `AlignmentStatusValue` (fuente única de verdad):
 * esta clase implementa una interfaz del core y no puede cargarse en código puro de host,
 * así que el valor en sí vive donde sí se puede leer sin arrastrar esa dependencia.
 */
class AlignmentStatus implements ColumnTypeInterface
{
    public const COMPLETE = AlignmentStatusValue::COMPLETE;
    public const PARTIAL = AlignmentStatusValue::PARTIAL;
    public const NONE = AlignmentStatusValue::NONE;

    public function getLabel(): string
    {
        // Renombrado de «Alineamiento» a «Anclaje» por decisión del propietario
        // (2026-08-03). Solo el rótulo: la clase, sus constantes y el parámetro
        // de query `alignment` espejan el vocabulario RDF (lrmi:educationalAlignment)
        // y sostienen los enlaces ya existentes.
        return 'Anclaje'; // @translate
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

        // Columna comprimida a solo glifo (ADR-0013 §Afinado 2026-07-29): a
        // miles de REA lo que se barre es «ajustado / no ajustado», y el
        // rótulo de texto gastaba ~10 em en decirlo. El estado íntegro no se
        // pierde: viaja en el nombre accesible y en el title, y la severidad
        // sigue en el color y en el riel de la fila. El glifo lo pone el CSS
        // (::before), que es presentación y no debe leerlo el lector de
        // pantalla — ya lo dice el texto oculto.
        return sprintf(
            '<span class="oer-alignment-status oer-alignment-status-%s" title="%s">'
                . '<span class="oer-visually-hidden">%s</span></span>',
            $escape($status),
            $escape($labels[$status]),
            $escape($labels[$status])
        );
    }

    /**
     * Calcula el estado de anclaje de un item (ADR-0005 §4):
     * completo = etapa + materia + ≥1 criterio + ≥1 saber **y ningún curso
     * huérfano**; sin alinear = sin criterios ni saberes; parcial = el resto.
     *
     * La condición del curso huérfano se añadió el 2026-08-10: un nivel
     * educativo que ninguna materia del REA sostiene es un anclaje mal hecho.
     * Cuesta el recorrido de `CurricularPairs`, que antes esta función no
     * pagaba — relevante en el filtro computado, que lo evalúa sobre hasta
     * 2 000 items (el coste real está acotado por el tamaño del grafo
     * curricular, que se comparte entre REA vía el identity map de Doctrine).
     */
    public static function statusFor(ItemRepresentation $item): string
    {
        // Proyector: resuelve las banderas desde la representación y delega la
        // decisión en AlignmentStatusValue, que es pura y está probada en host.
        // El curso huérfano —un nivel educativo que ninguna materia del REA
        // sostiene— degrada a `partial` desde el 2026-08-10; ver esa clase.
        return AlignmentStatusValue::fromFlags(
            (bool) $item->value('lrmi:educationalLevel'),
            (bool) $item->value('schema:about'),
            (bool) $item->value('lrmi:assesses'),
            (bool) $item->value('lrmi:teaches'),
            [] !== CurricularPairs::of($item)['orphanCourses']
        );
    }
}
