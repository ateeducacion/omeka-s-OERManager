<?php

declare(strict_types=1);

namespace OERManager\Service\Workflow;

use OERManager\Service\Curation\CurationWriter;
use Omeka\Api\Manager as ApiManager;
use Omeka\Api\Representation\ItemRepresentation;

/**
 * Lee y escribe el estado del flujo autor→curador (RF-016, ADR-0018; estados y
 * transiciones de ADR-0023 §2, TASK-067) sobre un item. Las guardas son las
 * de `WorkflowStatus`; quién puede pedir cada transición lo decide el ACL
 * (`Permissions\ReaAcl`), y sobre qué item, el permiso nativo de edición,
 * que `$api->update()` aplica por sí mismo.
 *
 * Cada valor de `curation:status` que se escribe lleva su value annotation
 * (ADR-0002): `dcterms:contributor`, `dcterms:modified` con la misma marca de
 * microsegundos que el re-catalogador y `dcterms:provenance`. Así «Publicado»
 * dice quién publicó y cuándo, en vez de borrarse como antes.
 *
 * Revisión de seguridad (TASK-067): estado, motivo y anotación se escriben
 * PRIVADOS — el API público no debe enseñar el nombre de quien publicó ni el
 * motivo de un rechazo —, y la escritura corre dentro del flag de
 * `StatusGuard`, el único camino (con global_admin) que puede cambiarlos.
 */
final class WorkflowService
{
    /** Prefijo legible de la procedencia anotada en cada estado. */
    public const PROVENANCE = 'OERManager flujo: %s';

    public function __construct(
        private readonly ApiManager $api,
        private readonly CurationWriter $writer,
        private readonly StatusGuard $guard = new StatusGuard()
    ) {
    }

    public function statusOf(ItemRepresentation $item): ?string
    {
        $value = $item->value(WorkflowStatus::STATUS_TERM);
        return null === $value ? null : WorkflowStatus::normalize((string) $value->value());
    }

    /**
     * Borrador o Rechazado → Propuesto. Limpia `curation:note` para que el
     * motivo de un rechazo previo no sobreviva a una nueva propuesta (spec §4
     * de TASK-037). No toca la visibilidad.
     *
     * @return array{updated:bool, status?:string, error?:string}
     */
    public function propose(ItemRepresentation $item, string $contributor): array
    {
        if (!WorkflowStatus::canPropose($this->statusOf($item))) {
            return self::invalid();
        }
        return $this->transition($item, WorkflowStatus::PROPOSED, '', null, $contributor);
    }

    /** @return array{updated:bool, status?:string, error?:string} */
    public function reject(ItemRepresentation $item, string $reason, string $contributor): array
    {
        if (!WorkflowStatus::canReject($this->statusOf($item))) {
            return self::invalid();
        }
        return $this->transition($item, WorkflowStatus::REJECTED, trim($reason), null, $contributor);
    }

    /**
     * Descartado: no publicable como REA. Pasa a privado, para no quedarse
     * como «Publicado fuera del flujo» para siempre.
     *
     * @return array{updated:bool, status?:string, error?:string}
     */
    public function discard(ItemRepresentation $item, string $reason, string $contributor): array
    {
        if (!WorkflowStatus::canDiscard($this->statusOf($item))) {
            return self::invalid();
        }
        return $this->transition($item, WorkflowStatus::DISCARDED, trim($reason), false, $contributor);
    }

    /** Descartado → Propuesto (sigue privado). @return array{updated:bool, status?:string, error?:string} */
    public function reopen(ItemRepresentation $item, string $contributor): array
    {
        if (!WorkflowStatus::canReopen($this->statusOf($item))) {
            return self::invalid();
        }
        return $this->transition($item, WorkflowStatus::PROPOSED, '', null, $contributor);
    }

    /**
     * Propuesto (o público fuera del flujo) → Publicado y público. El gate de
     * integridad lo aplica el controlador antes de llamar aquí.
     *
     * @return array{updated:bool, status?:string, error?:string}
     */
    public function publish(ItemRepresentation $item, string $contributor): array
    {
        if (!WorkflowStatus::canPublish($this->statusOf($item), (bool) $item->isPublic())) {
            return self::invalid();
        }
        return $this->transition($item, WorkflowStatus::PUBLISHED, '', true, $contributor);
    }

    /**
     * «Despublicar»: Publicado (o cualquier REA público) → Propuesto y
     * privado, con motivo. Vuelve a la cola.
     *
     * @return array{updated:bool, status?:string, error?:string}
     */
    public function unpublish(ItemRepresentation $item, string $reason, string $contributor): array
    {
        if (!WorkflowStatus::canUnpublish($this->statusOf($item), (bool) $item->isPublic())) {
            return self::invalid();
        }
        return $this->transition($item, WorkflowStatus::PROPOSED, trim($reason), false, $contributor);
    }

    /**
     * ⚠️ TRAMPA CRÍTICA (incidente 2026-06-25, skill rdf-curation):
     * `$api->update(..., ['isPartial' => true])` con **valores** de propiedades
     * NO es por-propiedad — `ValueHydrator` recorre la colección PLANA de
     * TODOS los valores del item y borra los no reutilizados. Pasar solo
     * `curation:status`/`curation:note` en `$data` sin más borraría título,
     * descripción, alineamiento curricular y todo lo demás. Patrón correcto
     * (el de `CurationWriter::commit()`): limpiar SOLO las properties que se
     * tocan vía `clear_property_values` y anexar con
     * `'collectionAction' => 'append'`. La visibilidad (`o:is_public`) no es
     * un valor RDF y viaja en el mismo payload.
     *
     * @param string $note '' = limpiar `curation:note`
     * @param bool|null $isPublic null = no tocar la visibilidad
     * @return array{updated:bool, status?:string, error?:string}
     */
    private function transition(
        ItemRepresentation $item,
        string $status,
        string $note,
        ?bool $isPublic,
        string $contributor
    ): array {
        $statusPropertyId = $this->writer->propertyId(WorkflowStatus::STATUS_TERM);
        if (null === $statusPropertyId) {
            // Sin la property no hay dónde escribir el estado: no dejar el
            // item a medias (mismo criterio que CurationWriter::eventValue()).
            return ['updated' => false, 'error' => 'missing_property'];
        }
        $clear = [$statusPropertyId];
        $statusValue = $this->literal($statusPropertyId, $status);
        $statusValue['@annotation'] = $this->writer->annotation(
            $contributor,
            $this->writer->stamp(),
            sprintf(self::PROVENANCE, $status),
            '',
            false
        );
        $data = [WorkflowStatus::STATUS_TERM => [$statusValue]];
        $notePropertyId = $this->writer->propertyId(WorkflowStatus::NOTE_TERM);
        if (null !== $notePropertyId) {
            $clear[] = $notePropertyId;
            $data[WorkflowStatus::NOTE_TERM] = '' === $note ? [] : [$this->literal($notePropertyId, $note)];
        }
        if (null !== $isPublic) {
            $data['o:is_public'] = $isPublic;
        }
        $this->guard->run(fn () => $this->writer->commit((int) $item->id(), $clear, $data));
        return ['updated' => true, 'status' => $status];
    }

    /** @return array{type:string, property_id:int, '@value':string, is_public:false} */
    private function literal(int $propertyId, string $value): array
    {
        return [
            'type' => 'literal',
            'property_id' => $propertyId,
            '@value' => $value,
            'is_public' => false,
        ];
    }

    /** @return array{updated:false, error:string} */
    private static function invalid(): array
    {
        return ['updated' => false, 'error' => 'invalid_transition'];
    }
}
