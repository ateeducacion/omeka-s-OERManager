<?php

declare(strict_types=1);

namespace OERManager\Test\Service;

use OERManager\Service\IdConstraintResolver;
use OERManager\Service\IdConstraints;
use OERManager\Service\MasterViewQuery;
use OERManager\Service\ReaTemplateFields;
use Omeka\Api\Manager;
use Omeka\Api\Response;
use PHPUnit\Framework\TestCase;

/** TASK-070: each branch is an id-only search; groups intersect. */
final class IdConstraintResolverTest extends TestCase
{
    /** @var list<array<string,mixed>> */
    private array $searches = [];

    /** @param list<array<int,int>> $answers content of each id-only search, in order */
    private function resolver(array $answers, array $required = [], ?int $classId = 9): IdConstraintResolver
    {
        $api = $this->createMock(Manager::class);
        $api->method('search')->willReturnCallback(function ($resource, $query) use (&$answers) {
            $this->assertSame('items', $resource);
            $this->searches[] = $query;
            return new Response(array_shift($answers) ?? []);
        });
        $masterViewQuery = $this->createMock(MasterViewQuery::class);
        $masterViewQuery->method('buildSearchParams')->willReturn(['resource_class_id' => $classId]);
        $fields = $this->createMock(ReaTemplateFields::class);
        $fields->method('requiredTerms')->willReturn($required);
        return new IdConstraintResolver($api, $masterViewQuery, $fields);
    }

    public function testNoGroupMeansNoConstraintAndNoSearch(): void
    {
        $this->assertNull($this->resolver([])->resolve(['status' => 'proposed', 'title' => 'x']));
        $this->assertSame([], $this->searches);
    }

    public function testBranchSearchesCarryOnlyTheirOwnRows(): void
    {
        $ids = $this->resolver([[4 => 4, 2 => 2], [7 => 7]])
            ->resolve(['status' => 'queue', 'missing' => ['licence'], 'title' => 'x', 'item_set_id' => 3]);

        $this->assertSame([2, 4, 7], $ids);
        $this->assertCount(2, $this->searches);
        [$proposed, $outOfFlow] = IdConstraints::queueBranches();
        $this->assertSame(['resource_class_id' => 9, 'return_scalar' => 'id'] + $proposed, $this->searches[0]);
        $this->assertSame(['resource_class_id' => 9, 'return_scalar' => 'id'] + $outOfFlow, $this->searches[1]);
    }

    public function testGroupsIntersect(): void
    {
        $ids = $this->resolver(
            [[1 => 1, 2 => 2], [3 => 3], [2 => 2], [3 => 3, 8 => 8]],
            ['dcterms:title', 'dcterms:license']
        )->resolve(['status' => 'queue', 'missing' => ['required']]);

        $this->assertSame([2, 3], $ids);
        $this->assertSame([['property' => 'dcterms:license', 'type' => 'nex']], $this->searches[3]['property']);
    }

    public function testAnEmptyUnionIsAnEmptyListNotNull(): void
    {
        $this->assertSame([], $this->resolver([[], []])->resolve(['status' => 'queue']));
    }

    public function testNoLearningResourceClassMeansNoIds(): void
    {
        $this->assertSame([], $this->resolver([], [], null)->resolve(['status' => 'queue']));
        $this->assertSame([], $this->searches);
    }

    public function testRequiredFieldsAvailability(): void
    {
        $this->assertFalse($this->resolver([])->hasRequiredFields());
        $this->assertTrue($this->resolver([], ['dcterms:title'])->hasRequiredFields());
    }
}
