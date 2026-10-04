<?php

declare(strict_types=1);

namespace OERManager\Service\Stats;

use Omeka\Api\Manager as ApiManager;

/**
 * Reads the `lrmi:LearningResource` catalogue for the statistics. Since
 * TASK-047 (spec D13) it pages through the catalogue in chunks of CHUNK up to
 * STATS_CAP, instead of one page of ComputedFilter::HARD_CAP (2000), which
 * truncated a 3000+ REA catalogue. Sin acoplamiento con MasterViewQuery a
 * propósito (TASK-006 spec §2.3): Estadísticas siempre mira el catálogo
 * completo, no los filtros activos de la vista maestra.
 */
final class CatalogSnapshot
{
    public const LEARNING_RESOURCE_CLASS_TERM = 'lrmi:LearningResource';

    /** REA per search: bounds the PHP arrays held at once. */
    public const CHUNK = 500;

    /** Past this many REA the statistics are computed on the first STATS_CAP and say so (ADR-0013). */
    public const STATS_CAP = 10000;

    private ApiManager $api;
    private bool $classIdResolved = false;
    private ?int $learningResourceClassId = null;

    public function __construct(ApiManager $api)
    {
        $this->api = $api;
    }

    /**
     * Calls $onPage with each page of items, in id order, until the catalogue
     * or STATS_CAP is exhausted.
     *
     * @param callable(\Omeka\Api\Representation\ItemRepresentation[]): void $onPage
     * @return array{total:int, truncated:bool}
     */
    public function walk(callable $onPage): array
    {
        $classId = $this->resolveLearningResourceClassId();
        if (null === $classId) {
            // Sin esta guarda, el adaptador de Omeka trata un filtro null como
            // "sin filtro" y devolvería TODO el catálogo sin acotar a
            // lrmi:LearningResource — silenciosamente incorrecto.
            return ['total' => 0, 'truncated' => false];
        }

        $page = 1;
        $seen = 0;
        do {
            $response = $this->api->search('items', [
                'resource_class_id' => $classId,
                'sort_by' => 'id',
                'sort_order' => 'asc',
                'page' => $page,
                'per_page' => self::CHUNK,
            ]);
            $items = (array) $response->getContent();
            $total = (int) $response->getTotalResults();
            if ([] !== $items) {
                $onPage($items);
            }
            $seen += count($items);
            $page++;
        } while (count($items) === self::CHUNK && $seen < min($total, self::STATS_CAP));

        return ['total' => $total, 'truncated' => $total > self::STATS_CAP];
    }

    /**
     * Every item at once, for the container harness.
     *
     * @return array{items: \Omeka\Api\Representation\ItemRepresentation[], truncated: bool}
     */
    public function fetch(): array
    {
        $all = [];
        $result = $this->walk(static function (array $items) use (&$all): void {
            array_push($all, ...$items);
        });
        return ['items' => $all, 'truncated' => $result['truncated']];
    }

    private function resolveLearningResourceClassId(): ?int
    {
        if (!$this->classIdResolved) {
            $response = $this->api
                ->search('resource_classes', ['term' => self::LEARNING_RESOURCE_CLASS_TERM])
                ->getContent();
            $this->learningResourceClassId = $response ? $response[0]->id() : null;
            $this->classIdResolved = true;
        }
        return $this->learningResourceClassId;
    }
}
