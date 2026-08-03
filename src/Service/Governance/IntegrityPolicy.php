<?php

declare(strict_types=1);

namespace OERManager\Service\Governance;

/**
 * Reglas de integridad RDF (RF-006) expresadas sobre datos planos.
 *
 * Vive separada de IntegrityChecker para poder probarse en el HOST: el checker
 * tipa ItemRepresentation y cualquier test que lo instancie muere en el
 * autoload sin el core (limitación del arnés, TASK-008). Es el mismo movimiento
 * que ConfigPayload hizo en TASK-029 con el mapeo de settings.
 *
 * Cambios de la rebanada 2 de TASK-028 frente a lo que TASK-005 escribió:
 *
 *  - D-6: las reglas mínimas se aplican SIEMPRE. Antes eran la rama «else» de
 *    «¿tiene plantilla?», así que asignar una plantilla que no declarase
 *    obligatorios el anclaje y la licencia habría silenciado ambos avisos sin
 *    que el catálogo mejorase (trampa anticipada en TASK-027 §8.1).
 *  - D-3: un literal en una property de enlace es un aviso propio. Antes contaba
 *    como valor presente y la integridad decía «ok» (defecto D2 del estudio).
 *  - D-7: la comprobación de enlace vivo es opcional. Es la única que necesita
 *    el destino de cada valor, y en el core el FK cascadea al borrar
 *    (Value.php, @JoinColumn(onDelete="CASCADE")), así que el enlace muerto es
 *    casi inalcanzable: no se paga por buscarlo en cada fila de la tabla.
 */
final class IntegrityPolicy
{
    /** Properties de anclaje curricular (ADR-0004). Todas son de enlace. */
    public const ALIGNMENT_TERMS = [
        'lrmi:educationalLevel',
        'schema:about',
        'lrmi:teaches',
        'lrmi:assesses',
    ];

    public const LICENSE_TERM = 'dcterms:rights';

    /**
     * @param array<string, list<array{type:string, hasResource:bool}>> $values
     *        Término → sus valores. Basta con incluir los términos de anclaje,
     *        la licencia y los obligatorios de plantilla; el resto se ignora.
     * @param list<string> $requiredTerms Obligatorios de la plantilla, [] si no hay
     * @param bool $checkLinks Comprobar que los enlaces tienen destino vivo
     * @return list<array{severity:string, code:string, field:string, message:string}>
     */
    public static function issuesFor(array $values, array $requiredTerms, bool $checkLinks): array
    {
        $issues = [];

        foreach (self::ALIGNMENT_TERMS as $term) {
            $termValues = $values[$term] ?? [];

            if ([] === $termValues) {
                $issues[] = [
                    'severity' => 'warning',
                    'code' => 'missing_alignment',
                    'field' => $term,
                    'message' => sprintf(
                        'Falta el campo de anclaje "%s".', // @translate
                        $term
                    ),
                ];
                continue;
            }

            foreach ($termValues as $value) {
                if (!str_starts_with($value['type'], 'resource')) {
                    $issues[] = [
                        'severity' => 'warning',
                        'code' => 'literal_in_link_property',
                        'field' => $term,
                        'message' => sprintf(
                            // @translate
                            'El campo "%s" tiene un valor literal donde debería enlazar a un item-término.',
                            $term
                        ),
                    ];
                    continue;
                }
                if ($checkLinks && !$value['hasResource']) {
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

        if ([] === ($values[self::LICENSE_TERM] ?? [])) {
            $issues[] = [
                'severity' => 'warning',
                'code' => 'missing_license',
                'field' => self::LICENSE_TERM,
                'message' => 'El REA no tiene licencia asignada.', // @translate
            ];
        }

        foreach ($requiredTerms as $term) {
            if ([] === ($values[$term] ?? [])) {
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

        return $issues;
    }
}
