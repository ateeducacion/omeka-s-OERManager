<?php

declare(strict_types=1);

namespace OERManager\Test\Service;

use OERManager\Service\IntegrityChecker;
use OERManager\Service\IntegrityResult;
use OERManager\Service\MasterViewQuery;
use OERManager\Service\Stats\CatalogSnapshot;
use OERManager\Service\Stats\DimensionFacts;
use OERManager\Service\Governance\CurricularPairs;
use OERManager\Test\Support\RepresentationFactory;
use Omeka\Api\Manager;
use PHPUnit\Framework\TestCase;

final class ApiProjectionTest extends TestCase
{
    use RepresentationFactory;

    public function testCatalogSnapshotCachesClassAndReportsTruncation(): void
    {
        $api = $this->createMock(Manager::class);
        $item = $this->item(1);
        $calls = [];
        $api->method('search')->willReturnCallback(function ($resource, $query) use (&$calls, $item) {
            $calls[] = $resource;
            if ($resource === 'resource_classes') {
                $this->assertSame('lrmi:LearningResource', $query['term']);
                return $this->response([$this->item(7)]);
            }
            $this->assertSame(7, $query['resource_class_id']);
            $this->assertSame(1, $query['page']);
            return $this->response([$item], $query['per_page'] + 1);
        });
        $snapshot = new CatalogSnapshot($api);
        $this->assertSame(['items' => [$item], 'truncated' => true], $snapshot->fetch());
        $snapshot->fetch();
        $this->assertSame(['resource_classes', 'items', 'items'], $calls);
    }

    public function testMissingClassNeverFetchesAllItems(): void
    {
        $api = $this->createMock(Manager::class);
        $api->expects($this->once())->method('search')->with('resource_classes')->willReturn($this->response([]));
        $snapshot = new CatalogSnapshot($api);
        $this->assertSame(['items' => [], 'truncated' => false], $snapshot->fetch());
        $snapshot->fetch();
    }

    public function testMasterQueryPreservesFilterOperatorsAndCachesClass(): void
    {
        $api = $this->createMock(Manager::class);
        $api->expects($this->once())->method('search')->with('resource_classes')
            ->willReturn($this->response([$this->item(7)]));
        $query = new MasterViewQuery($api);
        $result = $query->buildSearchParams(['page' => 2, 'sort_by' => 'title', 'sort_order' => 'desc',
            'title' => 'Test',
            'visibility' => 'private', 'stage' => '2', 'subject' => '3', 'project' => '4', 'axis' => '5',
            'licence' => 'CC BY', 'proposed' => '1', 'resource_type' => 'Video', 'missing' => ['licence', 'invalid', 2],
            'alignment' => 'none']);
        $this->assertSame(7, $result['resource_class_id']);
        $this->assertSame(2, $result['page']);
        $this->assertFalse($result['is_public']);
        $this->assertSame('in', $result['property'][0]['type']);
        $this->assertSame('res', $result['property'][1]['type']);
        $this->assertSame('eq', $result['property'][5]['type']);
        $this->assertSame('nex', $result['property'][8]['type']);
        $this->assertCount(4, $query->buildSearchParams(['alignment' => 'complete'])['property']);
        $partial = $query->buildSearchParams(['alignment' => 'partial']);
        $this->assertSame('or', $partial['property'][1]['joiner']);
        $this->assertSame(['resource_class_id' => 7], $query->buildSearchParams([]));
    }

    public function testFactsSkipBrokenLinksAndNormalizeLicense(): void
    {
        $target = $this->item(7, 'Course');
        $item = $this->item(1, 'REA', ['lrmi:educationalLevel' => [$this->value('', $target, 'resource:item'),
            $this->value('bad')],
            'dcterms:license' => [$this->value('CC BY', null, 'uri', 'https://example.org/license')]]);
        $facts = new DimensionFacts();
        $result = $facts->extract([$item, $this->item(2), $this->item(
            3,
            '',
            ['dcterms:license' => [$this->value('')]]
        )]);
        $this->assertSame([7], $result[1]['etapa']);
        $this->assertSame('CC BY', $result[1]['licencia']);
        $this->assertNull($result[2]['licencia']);
        $this->assertNull($result[3]['licencia']);
        $this->assertSame([7], $facts->distinctResourceIds($result));
    }

    public function testCurricularPairsDistinguishLiteralsBrokenLinksAndOrphanCourses(): void
    {
        $course = $this->item(1, 'Course');
        $subject = $this->item(2, 'Math', ['lrmi:educationalAlignment' => [$this->value(
            '',
            $course,
            'resource:item'
        )]]);
        $item = $this->item(9, 'REA', [
            'schema:about' => [$this->value('Legacy'), $this->value('', null, 'resource:item'), $this->value(
                '',
                $subject,
                'resource:item'
            ), $this->value('', $this->item(3, 'No course'), 'resource:item')],
            'lrmi:educationalLevel' => [$this->value('', $course, 'resource:item'), $this->value('Orphan literal'),
                $this->value('', $this->item(4, 'Other'), 'resource:item')],
        ]);
        $result = CurricularPairs::of($item);
        $this->assertSame(['Orphan literal', 'Other'], $result['orphanCourses']);
        $this->assertSame(['subject' => 'Legacy', 'course' => '', 'isLiteral' => true], $result['pairs'][0]);
        $this->assertSame('Course', $result['pairs'][1]['course']);
        $this->assertSame('', $result['pairs'][2]['course']);
    }

    public function testIntegrityResultOrdersSeverityAndFiltersIssues(): void
    {
        $warning = ['severity' => 'warning', 'code' => 'missing', 'field' => 'title', 'message' => 'Missing'];
        $error = ['severity' => 'error', 'code' => 'broken', 'field' => 'about', 'message' => 'Broken'];
        $this->assertTrue((new IntegrityResult())->isOk());
        $this->assertSame('warning', (new IntegrityResult([$warning]))->getStatus());
        $result = new IntegrityResult([$warning, $error]);
        $this->assertSame('error', $result->getStatus());
        $this->assertFalse($result->isOk());
        $this->assertSame([$warning, $error], $result->getIssues());
        $this->assertSame([$error], $result->getIssuesBySeverity('error'));
    }

    public function testIntegrityProjectionRespectsRequiredTemplateAndOptionalLinkChecks(): void
    {
        $template = $this->createMock(\Omeka\Api\Representation\ResourceTemplateRepresentation::class);
        $property = $this->createMock(\Omeka\Api\Representation\PropertyRepresentation::class);
        $property->method('term')->willReturn('dcterms:title');
        $required = $this->createMock(\Omeka\Api\Representation\ResourceTemplatePropertyRepresentation::class);
        $required->method('isRequired')->willReturn(true);
        $required->method('property')->willReturn($property);
        $template->method('resourceTemplateProperties')->willReturn([$required]);
        $broken = $this->value('', null, 'resource:item');
        $item = $this->item(1, '', ['schema:about' => [$broken], 'dcterms:license' => [$this->value(
            '',
            null,
            'uri',
            'https://license'
        )]]);
        $item->method('resourceTemplate')->willReturn($template);
        $checker = new IntegrityChecker();
        $result = $checker->check($item);
        $this->assertSame('error', $result->getStatus());
        $this->assertNotSame('error', $checker->check($item, false)->getStatus());
        $this->assertInstanceOf(IntegrityResult::class, $checker->check($this->item(2)));
    }
}
