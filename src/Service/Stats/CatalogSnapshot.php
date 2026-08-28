<?php

declare(strict_types=1);

namespace OERManager\Service\Stats;

use OERManager\Service\ComputedFilter;
use Omeka\Api\Manager as ApiManager;

/**
 * Trae hasta ComputedFilter::HARD_CAP items lrmi:LearningResource en una sola
 * llamada (spec §4) — mismo patrón que la rama computada de
 * IndexController::indexAction(). Sin acoplamiento con MasterViewQuery a
 * propósito (spec §2.3): Estadísticas siempre mira el catálogo completo, no
 * los filtros activos de la vista maestra.
 */
final class CatalogSnapshot
{
    public const LEARNING_RESOURCE_CLASS_TERM = 'lrmi:LearningResource';

    private ApiManager $api;
    private bool $classIdResolved = false;
    private ?int $learningResourceClassId = null;

    public function __construct(ApiManager $api)
    {
        $this->api = $api;
    }

    /** @return array{items: \Omeka\Api\Representation\ItemRepresentation[], truncated: bool} */
    public function fetch(): array
    {
        $response = $this->api->search('items', [
            'resource_class_id' => $this->resolveLearningResourceClassId(),
            'page' => 1,
            'per_page' => ComputedFilter::HARD_CAP,
        ]);
        return [
            'items' => $response->getContent(),
            'truncated' => $response->getTotalResults() > ComputedFilter::HARD_CAP,
        ];
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
