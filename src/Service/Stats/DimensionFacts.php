<?php

declare(strict_types=1);

namespace OERManager\Service\Stats;

use Omeka\Api\Representation\ItemRepresentation;
use OERManager\Service\Governance\IntegrityPolicy;
use OERManager\Service\Governance\ValueText;

/**
 * Extrae los "hechos" de las 5 dimensiones de RF-007/ADR-0004 de un item, en
 * la forma plana que consumen DimensionCounter/DimensionCrosser (puros). Es
 * la frontera puerto/adaptador: la única pieza de Stats que toca
 * ItemRepresentation — todo lo que sigue trabaja sobre arrays.
 */
final class DimensionFacts
{
    /**
     * Dimensiones resource:item (ADR-0004). La licencia no es un enlace a
     * item y se trata aparte.
     */
    public const DIMENSION_TERMS = [
        'etapa' => 'lrmi:educationalLevel',
        'materia' => 'schema:about',
        'eje' => 'dcterms:relation',
        'proyecto' => 'schema:isPartOf',
    ];

    /** ADR-0019: una sola fuente para el término de licencia. */
    public const LICENCE_TERM = IntegrityPolicy::LICENSE_TERM;

    /**
     * @param ItemRepresentation[] $items
     * @return array<int, array{etapa:int[],materia:int[],eje:int[],proyecto:int[],licencia:?string}>
     */
    public function extract(array $items): array
    {
        $facts = [];
        foreach ($items as $item) {
            $row = [];
            foreach (self::DIMENSION_TERMS as $key => $term) {
                $ids = [];
                foreach ($item->value($term, ['all' => true, 'default' => []]) as $value) {
                    $resource = $value->valueResource();
                    // Un literal en una property de enlace (D-3, ADR-0016) no
                    // resuelve a término: se omite del conteo en vez de romper.
                    if (null !== $resource) {
                        $ids[] = (int) $resource->id();
                    }
                }
                $row[$key] = $ids;
            }
            // Se agrupa por el texto mostrable: con un CustomVocab de URIs la
            // etiqueta es la del vocabulario; sin etiqueta, la URI (ADR-0019 §6).
            $licenceValue = $item->value(self::LICENCE_TERM);
            $row['licencia'] = null !== $licenceValue
                ? ValueText::of($licenceValue->value(), $licenceValue->uri())
                : null;
            if ('' === $row['licencia']) {
                $row['licencia'] = null;
            }
            $facts[(int) $item->id()] = $row;
        }
        return $facts;
    }

    /**
     * Ids de término distintos usados en los hechos (etapa/materia/eje/proyecto),
     * para resolver sus títulos en UNA sola llamada batch (spec §4).
     *
     * @param array<int, array<string, int[]|string|null>> $facts
     * @return int[]
     */
    public function distinctResourceIds(array $facts): array
    {
        $ids = [];
        foreach ($facts as $row) {
            foreach (array_keys(self::DIMENSION_TERMS) as $key) {
                foreach ((array) ($row[$key] ?? []) as $id) {
                    $ids[(int) $id] = true;
                }
            }
        }
        return array_map('intval', array_keys($ids));
    }
}
