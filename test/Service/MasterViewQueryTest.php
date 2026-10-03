<?php

declare(strict_types=1);

namespace OERManager\Test\Service;

use OERManager\Service\CurriculumSearch;
use OERManager\Service\MasterViewQuery;
use Omeka\Api\Manager as ApiManager;
use Omeka\Api\Response;
use PHPUnit\Framework\TestCase;

/**
 * TASK-045: filtro por colección (RF-018) y contrato entre la búsqueda
 * avanzada y el endpoint de autocompletado.
 */
final class MasterViewQueryTest extends TestCase
{
    public function testItemSetFilterIsPassedToTheCoreSearchParameter(): void
    {
        $query = new MasterViewQuery($this->apiResolvingClass());

        $this->assertSame(12, $query->buildSearchParams(['item_set_id' => '12'])['item_set_id']);
        $this->assertArrayNotHasKey('item_set_id', $query->buildSearchParams(['item_set_id' => '']));
        $this->assertArrayNotHasKey('item_set_id', $query->buildSearchParams(['item_set_id' => '0']));
        $this->assertArrayNotHasKey('item_set_id', $query->buildSearchParams(['item_set_id' => 'abc']));
    }

    public function testOnlyItemSetsWithLearningResourcesAreOffered(): void
    {
        $api = $this->createMock(ApiManager::class);
        $itemSets = [$this->itemSet(3, 'Primaria'), $this->itemSet(4, 'Vacía'), $this->itemSet(5, 'Bachillerato')];
        $counts = [3 => 7, 4 => 0, 5 => 1];
        $api->method('search')->willReturnCallback(
            function (string $resource, array $params) use ($itemSets, $counts) {
                if ('resource_classes' === $resource) {
                    return $this->response([$this->withId(9)], 1);
                }
                if ('item_sets' === $resource) {
                    return $this->response($itemSets, count($itemSets));
                }
                // Un REA basta para saber que la colección cuenta: sin traer filas.
                $this->assertSame(1, $params['limit']);
                $this->assertSame(9, $params['resource_class_id']);
                return $this->response([], $counts[$params['item_set_id']]);
            }
        );

        $this->assertSame(
            [3 => 'Primaria', 5 => 'Bachillerato'],
            (new MasterViewQuery($api))->learningResourceItemSets()
        );
    }

    public function testTheCandidateListIsBoundedByTheItemSetCap(): void
    {
        $api = $this->createMock(ApiManager::class);
        $api->method('search')->willReturnCallback(
            function (string $resource, array $params) {
                if ('resource_classes' === $resource) {
                    return $this->response([$this->withId(9)], 1);
                }
                if ('item_sets' === $resource) {
                    $this->assertSame(MasterViewQuery::MAX_ITEM_SETS, $params['limit']);
                }
                return $this->response([], 0);
            }
        );

        $this->assertSame([], (new MasterViewQuery($api))->learningResourceItemSets());
    }

    /**
     * La búsqueda avanzada rotula cada campo con la clave del filtro (stage,
     * subject…), pero el endpoint search-terms solo entiende dimensiones del
     * currículo. Si no coinciden devuelve [] y el desplegable no aparece nunca.
     */
    public function testEverySearchableFilterMapsToADimensionTheEndpointUnderstands(): void
    {
        $understood = array_merge(array_keys(CurriculumSearch::TYPE_SETTINGS), ['etapa', 'dcterms:relation']);

        foreach (MasterViewQuery::SEARCHABLE_FILTER_DIMENSIONS as $filter => $dimension) {
            $this->assertContains($dimension, $understood, "filter $filter");
            $this->assertSame(
                MasterViewQuery::RESOURCE_FILTERS[$filter],
                $dimension,
                "filter $filter must search the property it filters on"
            );
        }
    }

    private function apiResolvingClass(): ApiManager
    {
        $api = $this->createMock(ApiManager::class);
        $api->method('search')->willReturn($this->response([$this->withId(9)], 1));
        return $api;
    }

    private function response(array $content, int $total): Response
    {
        return new Response($content, $total);
    }

    private function withId(int $id): object
    {
        return new class ($id) {
            public function __construct(private int $id)
            {
            }

            public function id(): int
            {
                return $this->id;
            }
        };
    }

    private function itemSet(int $id, string $title): object
    {
        return new class ($id, $title) {
            public function __construct(private int $id, private string $title)
            {
            }

            public function id(): int
            {
                return $this->id;
            }

            public function displayTitle(): string
            {
                return $this->title;
            }
        };
    }
}
