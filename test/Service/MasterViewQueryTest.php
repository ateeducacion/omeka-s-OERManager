<?php

declare(strict_types=1);

namespace OERManager\Test\Service;

use OERManager\Service\Curriculum\CurModel;
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

    /** TASK-067: one «Estado» filter; out_of_flow = public without Publicado; proposed=1 still works. */
    public function testStatusFilter(): void
    {
        $query = new MasterViewQuery($this->apiResolvingClass());
        $row = static fn (string $type, string $text = '') => array_filter(
            ['property' => 'curation:status', 'type' => $type, 'text' => $text],
            static fn ($v) => '' !== $v
        );
        foreach (['proposed' => 'Propuesto', 'rejected' => 'Rechazado', 'discarded' => 'Descartado',
            'published' => 'Publicado'] as $key => $literal) {
            $this->assertSame([$row('eq', $literal)], $query->buildSearchParams(['status' => $key])['property']);
        }
        $this->assertSame([$row('nex')], $query->buildSearchParams(['status' => 'draft'])['property']);
        $outOfFlow = $query->buildSearchParams(['status' => 'out_of_flow']);
        $this->assertTrue($outOfFlow['is_public']);
        $this->assertSame([$row('neq', 'Publicado')], $outOfFlow['property']);
        $this->assertSame([$row('eq', 'Propuesto')], $query->buildSearchParams(['proposed' => '1'])['property']);
        $this->assertArrayNotHasKey('property', $query->buildSearchParams(['status' => 'bogus']));
        $this->assertArrayNotHasKey('property', $query->buildSearchParams(['status' => ['x']]));
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
        $understood = array_merge(CurModel::dimensions(), ['etapa', 'dcterms:relation']);

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

    /** TASK-070: «Creado hace más de» 7 / 30 / 90 days, on the catalogue entry date. */
    public function testAgeFilterSetsCreatedBefore(): void
    {
        $today = static fn (): \DateTimeImmutable => new \DateTimeImmutable('2026-11-24');
        $query = new MasterViewQuery($this->apiResolvingClass(), $today);

        $this->assertSame('2026-11-17', $query->buildSearchParams(['age' => '7'])['created_before']);
        $this->assertSame('2026-10-25', $query->buildSearchParams(['age' => '30'])['created_before']);
        $this->assertSame('2026-08-26', $query->buildSearchParams(['age' => '90'])['created_before']);
        foreach (['5', 'abc', '', ['7']] as $invalid) {
            $this->assertArrayNotHasKey('created_before', $query->buildSearchParams(['age' => $invalid]));
        }
    }

    /** TASK-070: `status=queue` and `missing[]=required` are resolved by IdConstraintResolver, not here. */
    public function testQueueAndRequiredAreNotNativeParameters(): void
    {
        $params = (new MasterViewQuery($this->apiResolvingClass()))
            ->buildSearchParams(['status' => 'queue', 'missing' => ['required']]);

        $this->assertArrayNotHasKey('property', $params);
        $this->assertArrayNotHasKey('is_public', $params);
    }
}
