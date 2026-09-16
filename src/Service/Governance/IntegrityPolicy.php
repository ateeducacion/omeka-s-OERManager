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
 *    el destino de cada valor, y el enlace muerto es inalcanzable POR
 *    CONSTRUCCIÓN, no por la FK en cascada del core (esa FK solo evita que SE
 *    CREE un valor colgante, un nivel más arriba y más débil):
 *    `AbstractResourceEntityRepresentation::values()` descarta los valores
 *    ocultos antes de devolverlos, y `ValueRepresentation::isHidden()` es
 *    exactamente «es un data type de recurso y `getValueResource()` es null».
 *    Un valor de enlace con destino colgante nunca llega hasta aquí. Reserva
 *    honesta: un data type de terceros con nombre `resource:*` que no
 *    extendiera `AbstractResource` sí sería un hueco. No se paga por buscarlo
 *    en cada fila de la tabla.
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

    /** Licencia del REA (ADR-0019): `dcterms:license`, guardada como URI. Antes `dcterms:rights` (ADR-0004 §5). */
    public const LICENSE_TERM = 'dcterms:license';

    /**
     * @param array<string, list<array{type:string, hasResource:bool, hasUri?:bool}>> $values
     *        Término → sus valores. Basta con incluir los términos de anclaje,
     *        la licencia y los obligatorios de plantilla; el resto se ignora.
     *        `hasUri` solo se mira en la licencia; si falta, cuenta como false.
     * @param list<string> $requiredTerms Obligatorios de la plantilla, [] si no hay
     * @param bool $checkLinks Comprobar que los enlaces tienen destino vivo
     * @param list<string>|null $licenceVocabUris URIs del vocabulario configurado,
     *        null si no resuelve (entonces no se juzga pertenencia)
     * @return list<array{severity:string, code:string, field:string, message:string}>
     */
    public static function issuesFor(
        array $values,
        array $requiredTerms,
        bool $checkLinks,
        ?array $licenceVocabUris = null
    ): array {
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
                            'El campo "%s" tiene un literal donde debería enlazar a un item-término.', // @translate
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

        $licenceValues = $values[self::LICENSE_TERM] ?? [];
        if ([] === $licenceValues) {
            $issues[] = [
                'severity' => 'warning',
                'code' => 'missing_license',
                'field' => self::LICENSE_TERM,
                'message' => 'El REA no tiene licencia asignada.', // @translate
            ];
        }

        // ADR-0019: la licencia se guarda como URI. Un literal («CC BY» metido
        // por la REST API, una importación o un CustomVocab de términos mal
        // apuntado en la configuración) tiene licencia pero no la que se puede
        // resolver. Un aviso por valor, como literal_in_link_property.
        foreach ($licenceValues as $value) {
            if (!($value['hasUri'] ?? false)) {
                $issues[] = [
                    'severity' => 'warning',
                    'code' => 'license_not_uri',
                    'field' => self::LICENSE_TERM,
                    'message' => 'La licencia no está guardada como URI.', // @translate
                ];
            }
        }

        // ADR-0020 / slice 3b: membership in the configured vocabulary. Only
        // evaluated when the setting resolves; unconfigured it stays silent,
        // because no curator can fix a list that does not exist. A value that
        // is not a URI already warned above and is not warned about twice.
        if (null !== $licenceVocabUris) {
            foreach ($licenceValues as $value) {
                if (!($value['hasUri'] ?? false)) {
                    continue;
                }
                $status = LicenceStatus::of([['uri' => (string) ($value['uri'] ?? '')]], $licenceVocabUris);
                if (LicenceStatus::IN_VOCAB !== $status) {
                    $issues[] = [
                        'severity' => 'warning',
                        'code' => 'license_not_in_vocab',
                        'field' => self::LICENSE_TERM,
                        'message' => 'La licencia no está en la lista de licencias aprobadas.', // @translate
                    ];
                }
            }
        }

        // A-3 (revisión final, TASK-028 rebanada 2): saltar los términos que las
        // reglas mínimas ya cubren (los cuatro de anclaje y la licencia). Sin
        // este guard, una plantilla que declarase obligatorio uno de esos mismos
        // términos emitía DOS issues para un solo campo real —missing_license/
        // missing_alignment más missing_required—, y el número de incidencias es
        // lo único que el curador lee de la celda de integridad. Se activa justo
        // cuando aterrice la plantilla REA (PEND-012), el escenario que la regla
        // de plantilla vino a proteger.
        $minimumTerms = [...self::ALIGNMENT_TERMS, self::LICENSE_TERM];
        foreach ($requiredTerms as $term) {
            if (in_array($term, $minimumTerms, true)) {
                continue;
            }
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
