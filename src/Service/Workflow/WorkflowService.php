<?php

declare(strict_types=1);

namespace OERManager\Service\Workflow;

use Omeka\Api\Manager as ApiManager;
use Omeka\Api\Representation\ItemRepresentation;

/**
 * Lee y escribe el estado del flujo autor→curador (RF-016, ADR-0018) sobre un
 * item. Resuelve properties por término, nunca por id hardcodeado — mismo
 * patrón que `RecatalogService::propertyId()`.
 */
final class WorkflowService
{
    private ApiManager $api;

    /** @var array<string, int|null> */
    private array $propertyIds = [];

    public function __construct(ApiManager $api)
    {
        $this->api = $api;
    }

    public function statusOf(ItemRepresentation $item): ?string
    {
        $value = $item->value(WorkflowStatus::STATUS_TERM);
        if (null === $value) {
            return null;
        }
        $status = trim((string) $value->value());
        return '' === $status ? null : $status;
    }

    /**
     * @return array{updated:bool, status?:string, error?:string}
     */
    public function propose(ItemRepresentation $item): array
    {
        if (!WorkflowStatus::canPropose($this->statusOf($item))) {
            return ['updated' => false, 'error' => 'invalid_transition'];
        }
        // '' (no null): SIEMPRE limpia curation:note, para que el motivo de
        // un rechazo previo no sobreviva a una nueva propuesta (spec §4).
        return $this->writeStatus((int) $item->id(), WorkflowStatus::PROPOSED, '');
    }

    /** @return array{updated:bool, status?:string, error?:string} */
    public function reject(ItemRepresentation $item, string $reason): array
    {
        if (!WorkflowStatus::canReject($this->statusOf($item))) {
            return ['updated' => false, 'error' => 'invalid_transition'];
        }
        return $this->writeStatus((int) $item->id(), WorkflowStatus::REJECTED, trim($reason));
    }

    /**
     * ⚠️ TRAMPA CRÍTICA (skill `recatalogador`, incidente 2026-06-25):
     * `$api->update(..., ['isPartial' => true])` con **valores** de propiedades
     * NO es por-propiedad — `ValueHydrator` recorre la colección PLANA de
     * TODOS los valores del item y borra los no reutilizados. Pasar solo
     * `curation:status`/`curation:note` en `$data` sin más borraría título,
     * descripción, alineamiento curricular y todo lo demás. Patrón correcto
     * (el mismo que ya usa `RecatalogService`): limpiar SOLO las properties
     * que se tocan vía `clear_property_values` y anexar con
     * `'collectionAction' => 'append'`, para que Omeka no reutilice/borre el
     * resto de la colección.
     *
     * @return array{updated:bool, status?:string, error?:string}
     */
    public function publish(ItemRepresentation $item): array
    {
        if (!WorkflowStatus::canPublish($this->statusOf($item))) {
            return ['updated' => false, 'error' => 'invalid_transition'];
        }
        $itemId = (int) $item->id();
        $statusPropertyId = $this->propertyId(WorkflowStatus::STATUS_TERM);
        if (null === $statusPropertyId) {
            // Sin la property no hay dónde limpiar el estado: no dejar el
            // item a medias (mismo criterio que RecatalogService::eventValue()).
            return ['updated' => false, 'error' => 'missing_property'];
        }
        $clear = [$statusPropertyId];
        $data = [
            'o:is_public' => true,
            WorkflowStatus::STATUS_TERM => [],
        ];
        $notePropertyId = $this->propertyId(WorkflowStatus::NOTE_TERM);
        if (null !== $notePropertyId) {
            $clear[] = $notePropertyId;
            $data[WorkflowStatus::NOTE_TERM] = [];
        }
        $data['clear_property_values'] = $clear;
        $this->api->update('items', $itemId, $data, [], ['isPartial' => true, 'collectionAction' => 'append']);
        return ['updated' => true];
    }

    /**
     * Mismo patrón `clear_property_values` + `collectionAction=append` que
     * `publish()` — ver el aviso de arriba. Sin esto, cada propose/reject
     * borraría el resto de las properties del item.
     *
     * @param string|null $note null = no tocar `curation:note` (no usado hoy:
     *   propose() SIEMPRE pasa '' para limpiar un motivo de un rechazo
     *   previo); '' = limpiarla.
     */
    private function writeStatus(int $itemId, string $status, ?string $note): array
    {
        $statusPropertyId = $this->propertyId(WorkflowStatus::STATUS_TERM);
        if (null === $statusPropertyId) {
            return ['updated' => false, 'error' => 'missing_property'];
        }
        $clear = [$statusPropertyId];
        $data = [
            WorkflowStatus::STATUS_TERM => [$this->literal($statusPropertyId, $status)],
        ];
        if (null !== $note) {
            $notePropertyId = $this->propertyId(WorkflowStatus::NOTE_TERM);
            if (null !== $notePropertyId) {
                $clear[] = $notePropertyId;
                $data[WorkflowStatus::NOTE_TERM] = '' === $note ? [] : [$this->literal($notePropertyId, $note)];
            }
        }
        $data['clear_property_values'] = $clear;
        $this->api->update('items', $itemId, $data, [], ['isPartial' => true, 'collectionAction' => 'append']);
        return ['updated' => true, 'status' => $status];
    }

    /** @return array{type:string, property_id:int, '@value':string} */
    private function literal(int $propertyId, string $value): array
    {
        return [
            'type' => 'literal',
            'property_id' => $propertyId,
            '@value' => $value,
        ];
    }

    private function propertyId(string $term): ?int
    {
        if (array_key_exists($term, $this->propertyIds)) {
            return $this->propertyIds[$term];
        }
        $content = $this->api->search('properties', ['term' => $term])->getContent();
        return $this->propertyIds[$term] = $content ? $content[0]->id() : null;
    }
}
