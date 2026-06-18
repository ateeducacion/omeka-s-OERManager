<?php

namespace OERManager\Service;

use Omeka\Api\Representation\ItemRepresentation;
use Omeka\Api\Representation\ResourceTemplateRepresentation;

/**
 * Servicio de comprobación de integridad de valores RDF (RF-006, TASK-005, ADR-0004).
 *
 * Reglas (RF-006):
 *   1. Destino vivo: las resource values de alineamiento apuntan a items existentes.
 *   2. Completitud con plantilla REA: todos los campos obligatorios de la plantilla presentes.
 *   3. Completitud mínima (sin plantilla): alineamiento + licencia presentes.
 *
 * Este servicio opera sobre representaciones Omeka; no accede directamente a la BD.
 * Uso: $checker->check($item) → IntegrityResult.
 */
class IntegrityChecker
{
    /** Properties de alineamiento curricular (ADR-0004). */
    public const ALIGNMENT_TERMS = [
        'lrmi:educationalLevel',
        'schema:about',
        'lrmi:teaches',
        'lrmi:assesses',
    ];

    public const LICENSE_TERM = 'dcterms:rights';

    public function check(ItemRepresentation $item): IntegrityResult
    {
        $issues = [];
        $this->checkLiveLinks($item, $issues);
        $this->checkCompleteness($item, $issues);
        return new IntegrityResult($issues);
    }

    /**
     * Verifica que los resource values de alineamiento apunten a items que existen.
     * Un enlace roto (valueResource = null con type resource) genera un error.
     *
     * @param array<int, array{severity: string, code: string, field: string, message: string}> $issues
     */
    private function checkLiveLinks(ItemRepresentation $item, array &$issues): void
    {
        $allValues = $item->values();
        foreach (self::ALIGNMENT_TERMS as $term) {
            $termValues = $allValues[$term]['values'] ?? [];
            foreach ($termValues as $value) {
                // Cubre 'resource', 'resource:item', 'resource:media', 'resource:itemset'
                if (str_starts_with($value->type(), 'resource') && !$value->valueResource()) {
                    $issues[] = [
                        'severity' => 'error',
                        'code' => 'dead_link',
                        'field' => $term,
                        'message' => sprintf(
                            'El valor de "%s" apunta a un recurso inexistente.', // @translate
                            $term
                        ),
                    ];
                }
            }
        }
    }

    /**
     * @param array<int, array{severity: string, code: string, field: string, message: string}> $issues
     */
    private function checkCompleteness(ItemRepresentation $item, array &$issues): void
    {
        $template = $item->resourceTemplate();
        if ($template) {
            $this->checkTemplateCompleteness($item, $template, $issues);
        } else {
            $this->checkMinimumCompleteness($item, $issues);
        }
    }

    /**
     * Con plantilla REA: valida los campos marcados como obligatorios.
     *
     * @param array<int, array{severity: string, code: string, field: string, message: string}> $issues
     */
    private function checkTemplateCompleteness(
        ItemRepresentation $item,
        ResourceTemplateRepresentation $template,
        array &$issues
    ): void {
        foreach ($template->resourceTemplateProperties() as $templateProperty) {
            if (!$templateProperty->isRequired()) {
                continue;
            }
            $term = $templateProperty->property()->term();
            if (!$item->value($term)) {
                $issues[] = [
                    'severity' => 'warning',
                    'code' => 'missing_required',
                    'field' => $term,
                    'message' => sprintf(
                        'El campo obligatorio "%s" de la plantilla no tiene valor.', // @translate
                        $term
                    ),
                ];
            }
        }
    }

    /**
     * Sin plantilla: mínimo = alineamiento vivo + licencia presente (RF-006).
     *
     * @param array<int, array{severity: string, code: string, field: string, message: string}> $issues
     */
    private function checkMinimumCompleteness(ItemRepresentation $item, array &$issues): void
    {
        foreach (self::ALIGNMENT_TERMS as $term) {
            if (!$item->value($term)) {
                $issues[] = [
                    'severity' => 'warning',
                    'code' => 'missing_alignment',
                    'field' => $term,
                    'message' => sprintf(
                        'Falta el campo de alineamiento "%s".', // @translate
                        $term
                    ),
                ];
            }
        }

        if (!$item->value(self::LICENSE_TERM)) {
            $issues[] = [
                'severity' => 'warning',
                'code' => 'missing_license',
                'field' => self::LICENSE_TERM,
                'message' => 'El REA no tiene licencia asignada.', // @translate
            ];
        }
    }
}
