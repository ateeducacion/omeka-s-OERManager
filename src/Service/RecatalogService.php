<?php

namespace OERManager\Service;

use Omeka\Api\Manager as ApiManager;
use Omeka\Api\Representation\ItemRepresentation;

/**
 * Escritura del alineamiento curricular y los ejes temáticos como resource
 * values RDF (RF-004/RF-005, ADR-0004), con preview del diff, validación de
 * destino y auditoría reversible vía value annotations dcterms (ADR-0002).
 *
 * Patrón obligatorio (skill recatalogador): preview + confirmación + auditoría.
 * Properties resueltas SIEMPRE por término, nunca por property_id hardcodeado.
 * Proyecto (schema:isPartOf) queda fuera: es acción de gestor aparte (ADR-0004).
 */
class RecatalogService
{
    /** Properties que el re-catalogador puede escribir (ADR-0004). */
    public const ALIGNMENT_TERMS = [
        'lrmi:educationalLevel',
        'schema:about',
        'lrmi:teaches',
        'lrmi:assesses',
        'dcterms:relation',
    ];

    /** @var array<string,int|null> caché term => property_id */
    private array $propertyIds = [];

    private ApiManager $api;

    public function __construct(ApiManager $api)
    {
        $this->api = $api;
    }

    /**
     * Diff entre el alineamiento actual y el propuesto, sin escribir nada.
     *
     * @param array<string,array<int|string>> $proposed term => ids de item-término
     * @return array<string,array{current:int[],next:int[],added:int[],removed:int[],invalid:int[]}>
     */
    public function preview(int $itemId, array $proposed): array
    {
        $item = $this->api->read('items', $itemId)->getContent();
        $diff = [];
        foreach (self::ALIGNMENT_TERMS as $term) {
            if (!array_key_exists($term, $proposed)) {
                continue;
            }
            $current = $this->currentTargetIds($item, $term);
            $next = $this->normalizeIds($proposed[$term]);
            $diff[$term] = [
                'current' => $current,
                'next' => $next,
                'added' => array_values(array_diff($next, $current)),
                'removed' => array_values(array_diff($current, $next)),
                'invalid' => $this->invalidTargets($next),
            ];
        }
        return $diff;
    }

    /**
     * Escribe el alineamiento propuesto (cardinalidad múltiple, RF-004) con
     * auditoría dcterms sobre cada valor. Lanza si algún destino no existe.
     *
     * @param array<string,array<int|string>> $proposed
     * @return array{updated:bool,properties:string[]}
     */
    public function apply(int $itemId, array $proposed, string $contributor): array
    {
        $now = (new \DateTimeImmutable())->format('c');
        $data = [];
        $clear = [];
        $properties = [];
        foreach (self::ALIGNMENT_TERMS as $term) {
            if (!array_key_exists($term, $proposed)) {
                continue;
            }
            $propertyId = $this->propertyId($term);
            if (null === $propertyId) {
                continue;
            }
            $ids = $this->normalizeIds($proposed[$term]);
            $invalid = $this->invalidTargets($ids);
            if ($invalid) {
                throw new \RuntimeException(sprintf(
                    'Destinos inexistentes o no-item en %s: %s',
                    $term,
                    implode(', ', $invalid)
                ));
            }
            // Limpiar SOLO esta property (clear_property_values) y anexar sus
            // nuevos valores. Vaciar una dimensión = limpiarla sin anexar.
            $clear[] = $propertyId;
            $properties[] = $term;
            if ($ids) {
                $data[$term] = $this->buildValues($propertyId, $ids, $contributor, $now, $term);
            }
        }
        if (!$clear) {
            return ['updated' => false, 'properties' => []];
        }
        // El partial de Omeka reemplaza el set COMPLETO de values del item
        // (ValueHydrator recorre la colección plana y borra lo no reutilizado).
        // Para tocar SOLO las properties editadas: limpiar esas properties con
        // clear_property_values y anexar (collectionAction=append) los nuevos
        // valores; en modo append Omeka no reutiliza ni borra el resto, así que
        // título, descripción, licencia, proyecto, etc. quedan intactos.
        $data['clear_property_values'] = $clear;
        $this->api->update(
            'items',
            $itemId,
            $data,
            [],
            ['isPartial' => true, 'collectionAction' => 'append']
        );
        return ['updated' => true, 'properties' => $properties];
    }

    /**
     * @param int[] $ids
     * @return array<int,array<string,mixed>>
     */
    private function buildValues(int $propertyId, array $ids, string $contributor, string $when, string $term): array
    {
        $values = [];
        foreach ($ids as $targetId) {
            $values[] = [
                'type' => 'resource:item',
                'property_id' => $propertyId,
                'value_resource_id' => $targetId,
                '@annotation' => $this->annotation($contributor, $when, $term),
            ];
        }
        return $values;
    }

    /**
     * Auditoría RDF nativa (ADR-0002): quién/cuándo/qué sobre el valor curado.
     * El formato '@annotation' está verificado contra la instalación real
     * (2026-06-25): se escribe correctamente como value annotation.
     *
     * @return array<string,array<int,array<string,mixed>>>
     */
    private function annotation(string $contributor, string $when, string $term): array
    {
        $annotation = [];
        $map = [
            'dcterms:contributor' => $contributor,
            'dcterms:modified' => $when,
            'dcterms:provenance' => sprintf('OERManager re-catalogación de %s', $term),
        ];
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
     * @return int[]
     */
    private function currentTargetIds(ItemRepresentation $item, string $term): array
    {
        $ids = [];
        foreach ($item->value($term, ['all' => true, 'default' => []]) as $value) {
            $resource = $value->valueResource();
            if ($resource) {
                $ids[] = $resource->id();
            }
        }
        sort($ids);
        return $ids;
    }

    /**
     * @param array<int|string> $ids
     * @return int[]
     */
    private function normalizeIds(array $ids): array
    {
        $ids = array_map('intval', $ids);
        $ids = array_values(array_unique(array_filter($ids, static fn ($id) => $id > 0)));
        sort($ids);
        return $ids;
    }

    /**
     * @param int[] $ids
     * @return int[] ids que no existen como item
     */
    private function invalidTargets(array $ids): array
    {
        $invalid = [];
        foreach ($ids as $id) {
            try {
                $this->api->read('items', $id);
            } catch (\Exception $e) {
                $invalid[] = $id;
            }
        }
        return $invalid;
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
