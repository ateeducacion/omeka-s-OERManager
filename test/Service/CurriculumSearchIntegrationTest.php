<?php

declare(strict_types=1);

namespace OERManager\Test\Service;

use OERManager\Service\CurriculumSearch;
use OERManager\Test\Support\RepresentationFactory;
use Omeka\Api\Manager;
use Omeka\Settings\Settings;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class CurriculumSearchIntegrationTest extends TestCase
{
    use RepresentationFactory;

    private $api;
    private $settings;
    private $search;

    protected function setUp(): void
    {
        $this->api = $this->createMock(Manager::class);
        $this->settings = $this->createMock(Settings::class);
        $this->search = new CurriculumSearch($this->api, $this->settings);
    }

    public function testUnconfiguredAndInvalidDimensionsNeverSearch(): void
    {
        $this->api->expects($this->never())->method('search');
        $this->assertSame([], $this->search->searchEtapas(''));
        $this->assertSame([], $this->search->searchAxes(''));
        $this->assertSame([], $this->search->searchDimension('unknown', ''));
        $this->assertSame([], $this->search->searchDimension('schema:about', ''));
        $this->assertSame([], $this->search->searchSubjectFamilies(0));
        $this->assertSame([], $this->search->searchSubjectFamilies(1));
        $this->assertSame([], $this->search->searchLeaves('unknown', 1, 'Math'));
        $this->assertSame([], $this->search->searchLeaves('lrmi:teaches', 1, 'Math'));
    }

    public function testStageAndAxisQueriesAreBoundedAndUseScalarFilters(): void
    {
        $this->settings->method('get')
            ->willReturnCallback(static fn ($key) => $key === CurriculumSearch::AXIS_SETTING ? 9 : ' Framework ');
        $item = $this->item(5, 'Stage', ['dcterms:description' => [$this->value('Description')]]);
        $queries = [];
        $this->api->method('search')->willReturnCallback(function ($resource, $query) use (&$queries, $item) {
            $this->assertSame('items', $resource);
            $this->assertSame(1, $query['page']);
            $this->assertSame(3, $query['per_page']);
            $queries[] = $query;
            return $this->response([$item]);
        });
        $this->assertSame('Description', $this->search->searchEtapas(' Stage ', 3)[0]['description']);
        $this->assertSame(0, $this->search->searchAxes('', 3)[0]['parentId']);
        $this->assertSame('Framework', $queries[0]['property'][0]['text']);
        $this->assertSame('Stage', $queries[0]['property'][1]['text']);
        $this->assertSame('9', $queries[1]['property'][0]['text']);
    }

    public static function contexts(): array
    {
        return [
            ['lrmi:educationalLevel', ['etapa' => [1, 2]], 'schema:inDefinedTermSet'],
            ['schema:about', ['level' => [1, 2]], 'lrmi:educationalLevel'],
            ['lrmi:teaches', ['about' => [1, 2]], 'schema:inDefinedTermSet'],
            ['lrmi:assesses', ['level' => [1, 2]], 'lrmi:educationalAlignment'],
            ['lrmi:teaches', ['etapa' => [1, 2]], 'dcterms:isPartOf'],
        ];
    }

    #[DataProvider('contexts')]
    public function testMergesAllAncestorsWithoutLosingTypeRestriction(
        string $dimension,
        array $context,
        string $property
    ): void {
        $this->settings->method('get')->willReturn('Subject');
        $parent = $this->item(1, 'Course');
        $first = $this->item(10, 'Term 10', ['lrmi:educationalAlignment' => [$this->value(
            '',
            $parent,
            'resource:item'
        )]]);
        $second = $this->item(2, 'Term 2');
        $calls = 0;
        $this->api->expects($this->exactly(2))->method('search')->willReturnCallback(function (
            $resource,
            $query
        ) use (
            &$calls,
            $property,
            $first,
            $second
) {
            $this->assertSame('dcterms:type', $query['property'][0]['property']);
            $this->assertSame($property, $query['property'][1]['property']);
            $this->assertSame((string) ++$calls, $query['property'][1]['text']);
            return $this->response([$first, $second]);
        });
        $result = $this->search->searchDimension($dimension, '', $context, 1);
        $this->assertCount(1, $result);
        $this->assertSame(2, $result[0]['id']);
    }

    public function testUnscopedDimensionMapsParentAndLiteralMetadata(): void
    {
        $this->settings->method('get')->willReturn('Subject');
        $parent = $this->item(4, 'Course');
        $item = $this->item(10, 'Subject', ['lrmi:educationalLevel' => [$this->value('', $parent, 'resource:item')],
            'dcterms:subject' => [$this->value('', null, 'uri'), $this->value('Block')]]);
        $this->api->method('search')->willReturn($this->response([$item]));
        $result = $this->search->searchDimension('schema:about', '');
        $this->assertSame(4, $result[0]['parentId']);
        $this->assertSame('Course', $result[0]['parentTitle']);
        $this->assertSame('Block', $result[0]['block']);
        $this->search->searchDimension('lrmi:educationalLevel', '');
        $this->search->searchDimension('lrmi:teaches', '');
    }

    public function testFamiliesDeduplicateCanonicalNamesAndUseTitleFallback(): void
    {
        $this->settings->method('get')->willReturn('Subject');
        $math = $this->item(1, 'Math 1', ['schema:about' => [$this->value('Math')]]);
        $this->api->method('search')->willReturn($this->response([$math, $math, $this->item(2, ' Science '),
            $this->item(3)]));
        $this->assertSame([['name' => 'Math'], ['name' => 'Science']], $this->search->searchSubjectFamilies(9));
    }

    public function testLeavesResolveMatchingSubjectAndCourseWithFallback(): void
    {
        $this->settings->method('get')->willReturn('Knowledge');
        $course = $this->item(1, 'Course');
        $subject = $this->item(2, 'Math');
        $other = $this->item(3, 'Competence');
        $leaf = $this->item(8, 'Leaf', ['lrmi:educationalAlignment' => [$this->value('', $course, 'resource:item')],
            'schema:inDefinedTermSet' => [$this->value(), $this->value('', $other, 'resource:item'),
                $this->value('', $subject, 'resource:item')]]);
        $this->api->method('search')->willReturn($this->response([$leaf, $this->item(9)]));
        $result = $this->search->searchLeaves('lrmi:teaches', 5, 'Math');
        $this->assertSame(1, $result[0]['courseId']);
        $this->assertSame(2, $result[0]['subjectId']);
        $this->assertSame(0, $result[1]['subjectId']);
        $this->assertSame(3, $this->search->searchLeaves('lrmi:assesses', 5, 'Other')[0]['subjectId']);
    }
}
