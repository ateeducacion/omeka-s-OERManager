<?php

namespace OERManager\Service;

use OERManager\Service\Governance\IntegrityPolicy;
use OERManager\Service\Governance\VocabEntries;
use Omeka\Api\Representation\ItemRepresentation;

/**
 * Comprobación de integridad de valores RDF (RF-006, TASK-005, ADR-0004).
 *
 * Desde la rebanada 2 de TASK-028 esta clase es un **proyector**: traduce la
 * ItemRepresentation a datos planos y delega las reglas en IntegrityPolicy, que
 * sí se puede probar en el host. Aquí no vive ninguna decisión.
 *
 * Uso: $checker->check($item) → IntegrityResult.
 */
class IntegrityChecker
{
    /** @var list<string> Alias de IntegrityPolicy, conservado por compatibilidad. */
    public const ALIGNMENT_TERMS = IntegrityPolicy::ALIGNMENT_TERMS;

    public const LICENSE_TERM = IntegrityPolicy::LICENSE_TERM;

    /**
     * @param VocabEntries $licenceVocab Lector del vocabulario de licencias
     *        (Task 5, service key `VocabEntries\Licence`). Única fuente de
     *        `uris()`/`null`: no se duplica aquí la lectura del setting ni de
     *        CustomVocab, para no bifurcar la degradación que centraliza.
     */
    public function __construct(private readonly VocabEntries $licenceVocab)
    {
    }

    /**
     * @param bool $checkLinks Comprobar que los enlaces tienen destino vivo.
     *        Encendido en el listener de guardado (un item, coste irrelevante) y
     *        en el drawer; APAGADO en la columna y en el filtro de la vista
     *        maestra, porque valueResource() inicializa el proxy Doctrine de
     *        cada destino —una consulta por valor— para perseguir un caso que
     *        `AbstractResourceEntityRepresentation::values()` hace inalcanzable
     *        POR CONSTRUCCIÓN (no la FK en cascada del core, que es un nivel más
     *        débil): esa `values()` descarta los valores ocultos —enlace con
     *        destino colgante— antes de devolverlos (D-7).
     */
    public function check(ItemRepresentation $item, bool $checkLinks = true): IntegrityResult
    {
        $requiredTerms = $this->requiredTerms($item);

        $terms = array_unique(array_merge(
            IntegrityPolicy::ALIGNMENT_TERMS,
            [IntegrityPolicy::LICENSE_TERM],
            $requiredTerms
        ));

        return new IntegrityResult(
            IntegrityPolicy::issuesFor(
                $this->project($item, $terms, $checkLinks),
                $requiredTerms,
                $checkLinks,
                $this->licenceVocab->uris()
            )
        );
    }

    /**
     * Proyecta los valores del item a la forma plana que espera la policy.
     *
     * `hasResource` solo se resuelve si hace falta: es la llamada cara
     * (valueResource() construye la representación del destino, despertando el
     * proxy). Con $checkLinks a false se deja en true, valor que la policy no
     * mira porque no evalúa la regla de enlace vivo.
     *
     * @param list<string> $terms
     * @return array<string, list<array{type:string, hasResource:bool, hasUri:bool, uri:string}>>
     */
    private function project(ItemRepresentation $item, array $terms, bool $checkLinks): array
    {
        $allValues = $item->values();
        $projected = [];

        foreach ($terms as $term) {
            $projected[$term] = [];
            foreach ($allValues[$term]['values'] ?? [] as $value) {
                $type = $value->type();
                $projected[$term][] = [
                    'type' => $type,
                    'hasResource' => (!$checkLinks || !str_starts_with($type, 'resource'))
                        ? true
                        : (bool) $value->valueResource(),
                    // Barato: uri() es un getter de la entidad, no despierta proxies.
                    'hasUri' => '' !== trim((string) $value->uri()),
                    'uri' => trim((string) $value->uri()),
                ];
            }
        }

        return $projected;
    }

    /** @return list<string> Campos obligatorios de la plantilla, [] si no hay plantilla. */
    private function requiredTerms(ItemRepresentation $item): array
    {
        $template = $item->resourceTemplate();
        if (!$template) {
            return [];
        }

        $required = [];
        foreach ($template->resourceTemplateProperties() as $templateProperty) {
            if ($templateProperty->isRequired()) {
                $required[] = $templateProperty->property()->term();
            }
        }

        return $required;
    }
}
