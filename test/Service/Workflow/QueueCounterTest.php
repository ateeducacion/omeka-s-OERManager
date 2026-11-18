<?php

declare(strict_types=1);

namespace OERManager\Test\Service\Workflow;

use OERManager\Service\MasterViewQuery;
use OERManager\Service\Workflow\QueueCounter;
use OERManager\Test\Support\RepresentationFactory;
use Omeka\Api\Manager;
use PHPUnit\Framework\TestCase;

/**
 * TASK-067 (ADR-0023 §3): «Cola (N)» = REA in Propuesto + public REA out of
 * the flow, without Descartado. COUNT-only searches (limit 0): no item is
 * hydrated.
 */
final class QueueCounterTest extends TestCase
{
    use RepresentationFactory;

    public function testCountsProposedAndPublicOutOfFlowWithoutHydrating(): void
    {
        $searches = [];
        $api = $this->createMock(Manager::class);
        $api->method('search')->willReturnCallback(function ($resource, $query) use (&$searches) {
            if ('resource_classes' === $resource) {
                return $this->response([$this->item(9)]);
            }
            $this->assertSame('items', $resource);
            $this->assertSame(0, $query['limit']);
            $this->assertSame(9, $query['resource_class_id']);
            $searches[] = $query;
            return $this->response([], isset($query['is_public']) ? 4 : 3);
        });
        $this->assertSame(7, (new QueueCounter(new MasterViewQuery($api), $api))->count());
        $this->assertCount(2, $searches);
        $this->assertSame([['property' => 'curation:status', 'type' => 'eq', 'text' => 'Propuesto']], $searches[0]['property']);
        $this->assertTrue($searches[1]['is_public']);
        $this->assertSame(['Propuesto', 'Publicado', 'Descartado'], array_column($searches[1]['property'], 'text'));
        $this->assertSame(['neq', 'neq', 'neq'], array_column($searches[1]['property'], 'type'));
    }

    public function testNoLearningResourceClassMeansZero(): void
    {
        $api = $this->createMock(Manager::class);
        $api->method('search')->willReturn($this->response([]));
        $this->assertSame(0, (new QueueCounter(new MasterViewQuery($api), $api))->count());
    }
}
