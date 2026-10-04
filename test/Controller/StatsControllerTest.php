<?php

namespace OERManager\Test\Controller;

use OERManager\Controller\Admin\StatsController;
use OERManager\Service\IntegrityChecker;
use OERManager\Service\IntegrityResult;
use OERManager\Service\Stats\CatalogSnapshot;
use OERManager\Service\Stats\CompletenessAggregator;
use OERManager\Service\Stats\CurriculumOutline;
use OERManager\Service\Stats\DimensionFacts;
use OERManager\Service\Stats\DimensionCounter;
use OERManager\Service\Stats\DimensionCrosser;
use OERManager\Service\Stats\CsvExport;
use Omeka\Api\Manager;
use Omeka\Api\Response;
use Omeka\Api\Representation\ItemRepresentation;
use Omeka\Api\Representation\ValueRepresentation;
use Omeka\Settings\Settings;
use PHPUnit\Framework\TestCase;

class StatsControllerTest extends TestCase
{
    /**
     * One REA per entry: [id, year]. Each REA links to the same term (id 7,
     * «Course») in every resource dimension and carries licence «CC BY».
     *
     * @param list<array{0:int, 1:int}> $reas
     */
    private function controller(array $reas = [[1, 2026]], int $total = 20000): StatsController
    {
        $term = $this->createMock(ItemRepresentation::class);
        $term->method('id')->willReturn(7);
        $term->method('displayTitle')->willReturn('Course');
        $value = $this->createMock(ValueRepresentation::class);
        $value->method('valueResource')->willReturn($term);
        $value->method('value')->willReturn('CC BY');

        $items = [];
        foreach ($reas as [$id, $year]) {
            $item = $this->createMock(ItemRepresentation::class);
            $item->method('id')->willReturn($id);
            $item->method('created')->willReturn(new \DateTime("$year-05-01"));
            $item->method('value')->willReturnCallback(
                static fn ($term, $options = []) => isset($options['all']) ? [$value] : $value
            );
            $items[] = $item;
        }

        $api = $this->createMock(Manager::class);
        $api->method('search')->willReturnCallback(
            static fn (string $resource, array $query) => match (true) {
                'resource_classes' === $resource => new Response([$term], 1),
                isset($query['resource_class_id']) => new Response($items, $total),
                default => new Response([$term], 1),
            }
        );
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
            new CurriculumOutline($api, new Settings())
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

    public function testDashboardIncludesEveryBlock(): void
    {
        $view = $this->controller()->indexAction();
        $data = $view->getVariable('statsData');
        $this->assertSame(['eje', 'proyecto', 'licencia'], array_keys($data['counts']));
        $this->assertSame('CC BY', $data['counts']['licencia'][0]['label']);
        $this->assertCount(20, $data['cross']);
        $this->assertSame(['rows', 'columns', 'cells'], array_keys($data['cross']['materia/etapa']));
        $this->assertSame([[1]], $data['cross']['materia/etapa']['cells']);
        // No type settings: the term is not a known course, so it lands in «Sin etapa».
        $this->assertSame('Sin etapa', $data['stageCounts'][0]['label']);
        $this->assertSame('Course', $data['stageCounts'][0]['courses'][0]['label']);
        $this->assertSame(1, $data['completeness']['ok']);
        $this->assertTrue($view->getVariable('truncated'));
        $this->assertFalse($view->getVariable('hasUniverse'));
        $this->assertSame([['year' => 2026, 'count' => 1]], $view->getVariable('years'));

        $empty = $this->controller([])->indexAction()->getVariable('statsData');
        $this->assertSame([], $empty['stageCounts']);
        $this->assertSame([], $empty['coverage']['rows']);
    }

    public function testYearFilterNarrowsEveryBlock(): void
    {
        $controller = $this->controller([[1, 2025], [2, 2026], [3, 2026]], 3);
        $controller->plugins['params']->query = ['year' => '2026'];
        $view = $controller->indexAction();
        $this->assertSame(2026, $view->getVariable('year'));
        $this->assertSame(2, $view->getVariable('total'));
        $this->assertFalse($view->getVariable('truncated'));
        $data = $view->getVariable('statsData');
        $this->assertSame(2, $data['completeness']['total']);
        $this->assertSame(2, $data['counts']['licencia'][0]['count']);
        $this->assertSame(2, $data['stageCounts'][0]['total']);
        // The choice list still offers every year.
        $this->assertSame(
            [['year' => 2026, 'count' => 2], ['year' => 2025, 'count' => 1]],
            $view->getVariable('years')
        );

        foreach (
            [['type' => 'completeness'], ['dimension' => 'licencia'], ['dimension' => 'etapa'],
            ['dimension' => 'materia'], ['type' => 'coverage'],
            ['dimension1' => 'licencia', 'dimension2' => 'eje']] as $query
        ) {
            $controller->plugins['params']->query = $query + ['year' => '2025'];
            $response = $controller->exportAction();
            $this->assertStringContainsString('-2025.csv', $response->headers['Content-Disposition']);
            $this->assertStringNotContainsString(',2', $response->getContent(), json_encode($query));
        }
    }

    public function testCsvExportsAndInvalidDimensions(): void
    {
        $controller = $this->controller();
        $expectedHeaders = [
            'type=completeness' => 'estado,conteo',
            'dimension=etapa' => 'Etapa,Curso,conteo',
            'dimension=materia' => 'Etapa,Curso,Materia,conteo',
            'type=coverage' => 'Etapa,Curso,Materia,conteo',
            'dimension=licencia' => 'Licencia,conteo',
            'dimension1=etapa&dimension2=licencia' => 'Curso,Licencia,conteo',
        ];
        foreach ($expectedHeaders as $query => $header) {
            parse_str($query, $params);
            $controller->plugins['params']->query = $params;
            $response = $controller->exportAction();
            $this->assertSame('text/csv; charset=utf-8', $response->headers['Content-Type']);
            $this->assertStringStartsWith($header . "\n", $response->getContent(), $query);
            $this->assertStringContainsString('# NOTA', $response->getContent());
        }
        foreach (
            [[], ['dimension' => 'invalid'], ['dimension1' => 'etapa', 'dimension2' => 'etapa'],
            ['dimension1' => 'etapa', 'dimension2' => 'nope']] as $query
        ) {
            $controller->plugins['params']->query = $query;
            $this->assertSame('unknown_dimension', $controller->exportAction()->getVariable('error'));
            $this->assertSame(404, $controller->getResponse()->getStatusCode());
        }
    }

    public function testMateriaCsvFollowsTheStageAndCourseFilter(): void
    {
        $controller = $this->controller();
        $controller->plugins['params']->query = ['dimension' => 'materia', 'course' => '999'];
        $this->assertStringNotContainsString('Course', $controller->exportAction()->getContent());
        $controller->plugins['params']->query = ['dimension' => 'materia', 'course' => 'x;1'];
        $this->assertStringContainsString('Course', $controller->exportAction()->getContent());
    }
}
