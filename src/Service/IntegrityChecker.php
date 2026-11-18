<?php

namespace OERManager\Service;

use OERManager\Service\Curriculum\CurModel;
use OERManager\Service\Curriculum\CurriculumTerms;
use OERManager\Service\Governance\IntegrityPolicy;
use OERManager\Service\Governance\VocabEntries;
use OERManager\Service\Workflow\WorkflowStatus;
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
     * @param CurriculumTerms|null $curriculum Retired-set ids for the rule
     *        «REA linked to a retired term» (TASK-064 slice 5); null = no rule.
     * @param ReaSite|null $reaSite Membership of the REA site (TASK-067); null =
     *        no rule. Visibility and status come from the item itself.
     */
    public function __construct(
        private readonly VocabEntries $licenceVocab,
        private readonly ?CurriculumTerms $curriculum = null,
        private readonly ?ReaSite $reaSite = null
    ) {
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
     *        The retired-term rule (TASK-064 slice 5) does NOT depend on this
     *        flag: it runs in the column and the master view filter as well, so
     *        they agree with the drawer. Measured on the 4.2 container
     *        (test/container/retired-term-check.php): values() already loads
     *        the targets (25 REA, 85 resource values: 0 extra SQL for
     *        valueResource()), and the retired ids are one scalar query per
     *        request, made only once a row has an alignment link.
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
                $this->licenceVocab->uris(),
                [
                    'isPublic' => (bool) $item->isPublic(),
                    'status' => $this->statusOf($item),
                    'inReaSite' => $this->reaSite?->contains((int) $item->id()),
                ]
            )
        );
    }

    /**
     * Proyecta los valores del item a la forma plana que espera la policy.
     *
     * `hasResource` solo se resuelve si hace falta. Con $checkLinks a false se
     * deja en true, valor que la policy no mira porque no evalúa la regla de
     * enlace vivo. The target of an alignment link is read with a curriculum
     * port (retired rule) whatever the flag: values() has loaded it already.
     *
     * @param list<string> $terms
     * @return array<string, list<array{type:string, hasResource:bool, hasUri:bool, uri:string,
     *     retired?:array{label:string, replacedBy:list<string>}}>>
     */
    private function project(ItemRepresentation $item, array $terms, bool $checkLinks): array
    {
        $allValues = $item->values();
        $projected = [];
        $retiredIds = null;

        foreach ($terms as $term) {
            $projected[$term] = [];
            $isAlignment = in_array($term, IntegrityPolicy::ALIGNMENT_TERMS, true);
            foreach ($allValues[$term]['values'] ?? [] as $value) {
                $type = $value->type();
                $isLink = str_starts_with($type, 'resource');
                $needsTarget = $isLink && ($checkLinks || ($isAlignment && null !== $this->curriculum));
                $target = $needsTarget ? $value->valueResource() : null;
                $row = [
                    'type' => $type,
                    'hasResource' => (!$checkLinks || !$isLink) ? true : null !== $target,
                    // Barato: uri() es un getter de la entidad, no despierta proxies.
                    'hasUri' => '' !== trim((string) $value->uri()),
                    'uri' => trim((string) $value->uri()),
                ];
                if ($isAlignment && null !== $target && null !== $this->curriculum) {
                    // One scalar query per request (ApiCurriculumTerms caches
                    // it), resolved only once a linked target is in hand.
                    $retiredIds ??= array_flip($this->curriculum->retiredIds());
                    if (isset($retiredIds[(int) $target->id()])) {
                        $row['retired'] = [
                            'label' => CurModel::label($target),
                            'replacedBy' => array_column(CurModel::refs($target, CurModel::IS_REPLACED_BY), 'title'),
                        ];
                    }
                }
                $projected[$term][] = $row;
            }
        }

        return $projected;
    }

    private function statusOf(ItemRepresentation $item): ?string
    {
        $value = $item->value(WorkflowStatus::STATUS_TERM);
        return null === $value ? null : WorkflowStatus::normalize((string) $value->value());
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
