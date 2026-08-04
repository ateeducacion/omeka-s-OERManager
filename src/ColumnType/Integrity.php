<?php

namespace OERManager\ColumnType;

use Laminas\View\Renderer\PhpRenderer;
use OERManager\Service\IntegrityChecker;
use OERManager\Service\IntegrityResult;
use Omeka\Api\Representation\AbstractEntityRepresentation;
use Omeka\Api\Representation\ItemRepresentation;
use Omeka\ColumnType\ColumnTypeInterface;

/**
 * Semáforo de integridad de la ficha (RF-002, RF-006).
 *
 * Estrena en la UI un activo que llevaba desde TASK-005 calculándose en cada
 * guardado y yendo SOLO al log de Omeka.
 *
 * Dos estados, no tres (D-5): «error» solo lo produciría un enlace muerto, y el
 * core cascadea el borrado del valor cuando desaparece su destino
 * (Value.php, @JoinColumn(onDelete="CASCADE")), así que hoy ningún dato puede
 * producirlo. La constante sigue existiendo en IntegrityResult; lo que no se
 * hace es pintar un rojo que nunca se encenderá.
 *
 * La comprobación de enlace vivo va APAGADA (D-7): es la única que despierta el
 * proxy Doctrine de cada destino, y aquí se renderizan 25 filas por página.
 */
class Integrity implements ColumnTypeInterface
{
    public const STATUS_CLASS_PREFIX = 'oer-integrity-';

    private IntegrityChecker $checker;

    public function __construct(IntegrityChecker $checker)
    {
        $this->checker = $checker;
    }

    public function getLabel(): string
    {
        return 'Integridad'; // @translate
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

        $result = $this->checker->check($resource, false);
        $escape = $view->plugin('escapeHtml');
        $count = count($result->getIssues());

        if ($result->isOk()) {
            $label = $view->translate('Ficha completa'); // @translate
            return sprintf(
                '<span class="oer-integrity %s%s" title="%s"><span class="oer-visually-hidden">%s</span></span>',
                self::STATUS_CLASS_PREFIX,
                $escape(IntegrityResult::STATUS_OK),
                $escape($label),
                $escape($label)
            );
        }

        $label = sprintf(
            $view->translate('%s incidencias en la ficha'), // @translate
            $count
        );

        return sprintf(
            '<span class="oer-integrity %s%s" title="%s">%s<span class="oer-visually-hidden">%s</span></span>',
            self::STATUS_CLASS_PREFIX,
            $escape($result->getStatus()),
            $escape($label),
            $escape((string) $count),
            $escape($label)
        );
    }
}
