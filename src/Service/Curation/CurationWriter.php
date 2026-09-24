<?php

namespace OERManager\Service\Curation;

use OERManager\Service\CurationEvent;
use Omeka\Api\Manager as ApiManager;

/**
 * Low-level RDF write mechanics for curation (ADR-0002/ADR-0015), extracted
 * from RecatalogService (TASK-028 slice 3b) so Governance can reuse them
 * instead of duplicating the value-annotation and event-value formats.
 *
 * Pure mechanics only: no dimension knowledge, no validation, no diffing.
 * Callers own their own value annotation contents (`annotation()` takes the
 * provenance text as an argument) and the shared timestamp.
 */
class CurationWriter
{
    /** Tope de longitud de la justificación anotada (defensa en profundidad). */
    private const MAX_REASON_CHARS = 200;

    /** @var array<string,int|null> caché term => property_id */
    private array $propertyIds = [];

    private ApiManager $api;

    public function __construct(ApiManager $api)
    {
        $this->api = $api;
    }

    /** One microsecond-precision stamp shared by the event and every annotation (ADR-0015). */
    public function stamp(): string
    {
        return (new \DateTimeImmutable())->format('Y-m-d\TH:i:s.uP');
    }

    public function propertyId(string $term): ?int
    {
        if (array_key_exists($term, $this->propertyIds)) {
            return $this->propertyIds[$term];
        }
        $content = $this->api->search('properties', ['term' => $term])->getContent();
        return $this->propertyIds[$term] = $content ? $content[0]->id() : null;
    }

    /**
     * Literales de una value annotation, en el formato que espera ValueHydrator.
     * Las properties que la instalación no tenga se omiten en silencio.
     *
     * @param array<string,string> $map term => literal
     * @return array<string,array<int,array<string,mixed>>>
     */
    public function annotationValues(array $map): array
    {
        $annotation = [];
        foreach ($map as $annTerm => $literal) {
            $annPropertyId = $this->propertyId($annTerm);
            if (null === $annPropertyId) {
                continue;
            }
            $annotation[$annTerm] = [[
                'type' => 'literal',
                'property_id' => $annPropertyId,
                '@value' => $literal,
            ]];
        }
        return $annotation;
    }

    /**
     * Auditoría RDF nativa (ADR-0002): quién/cuándo/qué sobre el valor curado.
     * El formato '@annotation' está verificado contra la instalación real
     * (2026-06-25): se escribe correctamente como value annotation. Si hay
     * justificación de la IA (TASK-023), se añade como dcterms:description (el
     * «porqué», distinto del «qué» de dcterms:provenance), acotada en longitud.
     *
     * $provenance llega ya construido por el llamador (p. ej. RecatalogService
     * usa 'OERManager re-catalogación de %s'): esta clase no conoce el texto de
     * ningún dominio concreto, solo el formato de la anotación.
     *
     * @return array<string,array<int,array<string,mixed>>>
     */
    public function annotation(string $contributor, string $when, string $provenance, string $description = ''): array
    {
        $map = [
            'dcterms:contributor' => $contributor,
            'dcterms:modified' => $when,
            'dcterms:provenance' => $provenance,
        ];
        if ('' !== $description) {
            $map['dcterms:description'] = mb_substr($description, 0, self::MAX_REASON_CHARS);
        }
        return $this->annotationValues($map);
    }

    /**
     * Valor de evento sobre el propio item (ADR-0015): resumen legible en el
     * valor y payload exacto en su anotación. PRIVADO a propósito — es un
     * registro de máquina y no debe salir en la ficha pública del REA.
     *
     * @param array<string,mixed> $payload
     * @return array<string,mixed>|null null si la instalación no tiene las
     *   properties necesarias: preferible no dejar traza a dejarla incompleta
     *   (un payload perdido haría que el deshacer restaurase un estado falso).
     */
    public function eventValue(array $payload, string $contributor, string $when): ?array
    {
        $propertyId = $this->propertyId('dcterms:provenance');
        $annotation = $this->annotationValues([
            'dcterms:contributor' => $contributor,
            'dcterms:modified' => $when,
            'dcterms:provenance' => CurationEvent::MARKER,
            'dcterms:replaces' => CurationEvent::encode($payload),
        ]);
        if (null === $propertyId || !isset($annotation['dcterms:replaces'])) {
            return null;
        }
        return [
            'type' => 'literal',
            'property_id' => $propertyId,
            'is_public' => false,
            '@value' => CurationEvent::summary($payload),
            '@annotation' => $annotation,
        ];
    }

    /**
     * Targeted clear plus append. `isPartial` is not a per-property merge: in
     * append mode Omeka neither reuses nor deletes the values of properties
     * absent from $data, so title, description and alignment survive.
     *
     * @param list<int> $clearPropertyIds
     * @param array<string,mixed> $data
     */
    public function commit(int $itemId, array $clearPropertyIds, array $data): void
    {
        $data['clear_property_values'] = $clearPropertyIds;
        $this->api->update('items', $itemId, $data, [], ['isPartial' => true, 'collectionAction' => 'append']);
    }
}
