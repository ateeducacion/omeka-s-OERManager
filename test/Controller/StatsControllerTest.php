<?php

namespace OERManager\Test\Controller;

use OERManager\Controller\Admin\StatsController;
use OERManager\Service\IntegrityChecker;
use OERManager\Service\IntegrityResult;
use OERManager\Service\Stats\CatalogSnapshot;
use OERManager\Service\Stats\CompletenessAggregator;
use OERManager\Service\Stats\DimensionFacts;
use OERManager\Service\Stats\DimensionCounter;
use OERManager\Service\Stats\DimensionCrosser;
use OERManager\Service\Stats\CsvExport;
use Omeka\Api\Manager;
use Omeka\Api\Response;
use Omeka\Api\Representation\ItemRepresentation;
use Omeka\Api\Representation\ValueRepresentation;
use PHPUnit\Framework\TestCase;

class StatsControllerTest extends TestCase
{
    private function controller(bool $empty = false): StatsController
    {
        $item = $this->createMock(ItemRepresentation::class);
        $item->method('id')->willReturn(7);
        $item->method('displayTitle')->willReturn('Course');
        $value = $this->createMock(ValueRepresentation::class);
        $value->method('valueResource')->willReturn($item);
        $value->method('value')->willReturn('CC BY');
        $item->method('value')->willReturnCallback(
            static fn ($term, $options = []) => isset($options['all']) ? [$value] : $value
        );
        $api = $this->createMock(Manager::class);
        $api->method('search')->willReturn(new Response($empty ? [] : [$item], 3000));
        $checker = $this->createMock(IntegrityChecker::class);
        $checker->method('check')->willReturn(new IntegrityResult());
        $controller = new StatsController(
            new CatalogSnapshot($api),
            new DimensionFacts(),
            new DimensionCounter(),
            new DimensionCrosser(),
            new CompletenessAggregator(),
            new CsvExport(),
            $checker,
            $api
        );
        $controller->plugins = ['response' => new \Laminas\Http\Response(), 'params' => new class {
            public array $query = [];
            public function fromQuery($key, $default = null)
            {
                return $this->query[$key] ?? $default;
            }
        }];
        return $controller;
    }

    public function testDashboardIncludesCountsCrossTablesAndCompleteness(): void
    {
        $view = $this->controller()->indexAction();
        $data = $view->getVariable('statsData');
        $this->assertCount(5, $data['counts']);
        $this->assertCount(20, $data['cross']);
        $this->assertSame('Course', $data['counts']['etapa'][0]['label']);
        $this->assertSame(1, $data['completeness']['ok']);
        $this->assertTrue($view->getVariable('truncated'));
        $this->assertSame([], $this->controller(true)->indexAction()->getVariable('statsData')['counts']['etapa']);
    }

    public function testCsvExportsAndInvalidDimensions(): void
    {
        $controller = $this->controller();
        foreach (
            [['type' => 'completeness'], ['dimension' => 'etapa'],
            ['dimension1' => 'etapa', 'dimension2' => 'licencia']] as $query
        ) {
            $controller->plugins['params']->query = $query;
            $response = $controller->exportAction();
            $this->assertSame('text/csv; charset=utf-8', $response->headers['Content-Type']);
            $this->assertStringContainsString('# NOTA', $response->getContent());
        }
        foreach ([[], ['dimension' => 'invalid'], ['dimension1' => 'etapa', 'dimension2' => 'etapa']] as $query) {
            $controller->plugins['params']->query = $query;
            $this->assertSame('unknown_dimension', $controller->exportAction()->getVariable('error'));
            $this->assertSame(404, $controller->getResponse()->getStatusCode());
        }
    }
}
