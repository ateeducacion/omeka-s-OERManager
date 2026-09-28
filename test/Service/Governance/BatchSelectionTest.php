<?php

declare(strict_types=1);

namespace OERManager\Test\Service\Governance;

use OERManager\Service\ComputedFilter;
use OERManager\Service\Governance\BatchSelection;
use OERManager\Service\Governance\BatchSelectionException;
use OERManager\Service\IntegrityChecker;
use OERManager\Service\IntegrityResult;
use OERManager\Service\MasterViewQuery;
use OERManager\Test\Support\RepresentationFactory;
use Omeka\Api\Manager;
use PHPUnit\Framework\TestCase;

final class BatchSelectionTest extends TestCase
{
    use RepresentationFactory;

    private $api;
    private $query;
    private $checker;
    private array $searches = [];
    /** @var callable */
    private $respond;

    protected function setUp(): void
    {
        $this->api = $this->createMock(Manager::class);
        $this->query = $this->createMock(MasterViewQuery::class);
        $this->checker = $this->createMock(IntegrityChecker::class);
        $this->query->method('buildSearchParams')->willReturnCallback(
            static fn (array $q) => ['resource_class_id' => 9] + $q
        );
        $this->respond = fn (array $params, array $options) => $this->response([4, 2], 2);
        $this->api->method('search')->willReturnCallback(function ($resource, $params, $options = []) {
            $this->searches[] = [$params, $options];
            return ($this->respond)($params, $options);
        });
    }

    private function selection(): BatchSelection
    {
        return new BatchSelection($this->api, $this->query, $this->checker);
    }

    private function reason(callable $call): string
    {
        try {
            $call();
        } catch (BatchSelectionException $e) {
            return $e->reason;
        }
        $this->fail('expected a BatchSelectionException');
    }

    public function testExplicitIdsAreCleanedAndConstrainedToTheReaClass(): void
    {
        $ids = $this->selection()->resolveIds(['4', 4, 0, -1, 'x', 2]);

        $this->assertSame([2, 4], $ids);
        [$params, $options] = $this->searches[0];
        $this->assertSame(9, $params['resource_class_id']);
        $this->assertSame([2, 4], $params['id']);
        $this->assertSame(['returnScalar' => 'id'], $options);
    }

    public function testMatchingRebuildsTheQueryWithoutPaginationOrSorting(): void
    {
        $this->selection()->resolveMatching(['page' => 3, 'sort_by' => 'title', 'title' => 'x']);

        [$params, $options] = $this->searches[0];
        $this->assertArrayNotHasKey('page', $params);
        $this->assertArrayNotHasKey('sort_by', $params);
        $this->assertSame('x', $params['title']);
        $this->assertSame(['returnScalar' => 'id'], $options);
    }

    public function testUnresolvedClassEmptySetAndOverCapAreRefused(): void
    {
        $this->query = $this->createMock(MasterViewQuery::class);
        $this->query->method('buildSearchParams')->willReturn(['resource_class_id' => null]);
        $this->assertSame('class_unresolved', $this->reason(fn () => $this->selection()->resolveMatching([])));

        $this->setUp();
        $this->respond = fn () => $this->response([], 0);
        $this->assertSame('empty', $this->reason(fn () => $this->selection()->resolveIds([1])));
        $this->assertSame('empty', $this->reason(fn () => $this->selection()->resolveIds(['x'])));

        $this->respond = fn () => $this->response(range(1, BatchSelection::BATCH_MAX + 1));
        $this->assertSame('too_many', $this->reason(fn () => $this->selection()->resolveMatching([])));
    }

    public function testComputedFilterIsEvaluatedAndRefusedWhenTruncated(): void
    {
        $ok = $this->item(1);
        $warn = $this->item(2);
        $this->checker->method('check')->willReturnCallback(
            fn ($item) => new IntegrityResult($item === $warn ? [['severity' => 'warning']] : [])
        );
        $this->respond = fn ($params, $options) => $this->response([$ok, $warn], 2);

        $this->assertSame([2], $this->selection()->resolveMatching(['integrity' => 'warning']));
        $this->assertSame(ComputedFilter::HARD_CAP, $this->searches[0][0]['per_page']);

        $this->respond = fn ($params, $options) => $this->response([$ok, $warn], ComputedFilter::HARD_CAP + 1);
        $this->assertSame(
            'computed_truncated',
            $this->reason(fn () => $this->selection()->resolveMatching(['integrity' => 'warning']))
        );
    }

    public function testCountWithValueUsesAnExistsQueryOverTheIds(): void
    {
        $this->respond = fn () => $this->response([], 7);

        $this->assertSame(7, $this->selection()->countWithValue([1, 2, 3], 'dcterms:license'));
        $this->assertSame(0, $this->selection()->countWithValue([], 'dcterms:license'));

        [$params] = $this->searches[0];
        $this->assertSame([1, 2, 3], $params['id']);
        $this->assertSame([['joiner' => 'and', 'property' => 'dcterms:license', 'type' => 'ex']], $params['property']);
        $this->assertSame(1, $params['per_page']);
        $this->assertCount(1, $this->searches, 'an empty id list never queries');
    }
}
