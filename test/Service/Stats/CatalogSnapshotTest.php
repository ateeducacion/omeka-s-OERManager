<?php

declare(strict_types=1);

namespace OERManager\Test\Service\Stats;

use OERManager\Service\Stats\CatalogSnapshot;
use OERManager\Test\Support\RepresentationFactory;
use Omeka\Api\Manager;
use PHPUnit\Framework\TestCase;

/** TASK-067 (ADR-0023 §2): Descartado is not a REA for the statistics. */
final class CatalogSnapshotTest extends TestCase
{
    use RepresentationFactory;

    public function testDiscardedReaAreLeftOut(): void
    {
        $api = $this->createMock(Manager::class);
        $api->method('search')->willReturnCallback(function ($resource, $query) {
            if ('resource_classes' === $resource) {
                return $this->response([$this->item(9)]);
            }
            $this->assertSame(
                [['property' => 'curation:status', 'type' => 'neq', 'text' => 'Descartado']],
                $query['property']
            );
            return $this->response([$this->item(1)], 1);
        });
        $this->assertCount(1, (new CatalogSnapshot($api))->fetch()['items']);
    }
}
