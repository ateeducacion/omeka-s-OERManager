<?php

declare(strict_types=1);

namespace OERManager\Test\Service;

use OERManager\Service\CurationEvent;
use OERManager\Service\CurriculumSearch;
use OERManager\Service\Curation\CurationWriter;
use OERManager\Service\RecatalogService;
use OERManager\Test\Support\RepresentationFactory;
use Omeka\Api\Manager;
use Omeka\Api\Representation\ValueAnnotationRepresentation;
use Omeka\Settings\Settings;
use PHPUnit\Framework\TestCase;

final class RecatalogServiceTest extends TestCase
{
    use RepresentationFactory;

    private $api;
    private $settings;
    private $service;
    private array $items;
    private array $missingProperties = [];
    private array $writes = [];

    protected function setUp(): void
    {
        $this->api = $this->createMock(Manager::class);
        $this->settings = $this->createMock(Settings::class);
        $this->service = new RecatalogService($this->api, $this->settings, new CurationWriter($this->api));
        $this->items = [1 => $this->item(1), 2 => $this->item(2, 'Target'), 3 => $this->item(3, 'Other')];
        $this->api->method('read')->willReturnCallback(function ($resource, $id) {
            if (!isset($this->items[$id])) {
                throw new \RuntimeException('Missing target');
            }
            return $this->response($this->items[$id]);
        });
        $this->api->method('search')->willReturnCallback(function ($resource, $query) {
            return $this->response(in_array(
                $query['term'],
                $this->missingProperties,
                true
            ) ? [] : [$this->item($this->propertyId($query['term']))]);
        });
        $this->api->method('update')->willReturnCallback(function ($resource, $id, $data, $files, $options) {
            $this->assertSame('items', $resource);
            $this->assertSame(1, $id);
            $this->assertSame(['isPartial' => true, 'collectionAction' => 'append'], $options);
            $this->writes[] = $data;
        });
    }

    private function propertyId(string $term): int
    {
        return array_search($term, ['lrmi:educationalLevel', 'schema:about', 'lrmi:teaches', 'lrmi:assesses',
            'dcterms:relation', 'dcterms:contributor', 'dcterms:modified', 'dcterms:provenance',
                'dcterms:replaces', 'dcterms:description'], true) + 1;
    }

    private function annotation(array $values): ValueAnnotationRepresentation
    {
        $annotation = $this->createMock(ValueAnnotationRepresentation::class);
        $annotation->method('value')
            ->willReturnCallback(fn ($term) => isset($values[$term]) ? $this->value($values[$term]) : null);
        return $annotation;
    }

    private function event(array $payload, string $when, string $marker = CurationEvent::MARKER)
    {
        $value = $this->value('Changed alignment');
        $value->method('valueAnnotation')->willReturn($this->annotation(['dcterms:provenance' => $marker,
            'dcterms:replaces' => CurationEvent::encode($payload), 'dcterms:modified' => $when,
                'dcterms:contributor' => 'Curator']));
        return $value;
    }

    public function testPreviewNormalizesIdsAndQualifiesTitlesWithoutWriting(): void
    {
        $course = $this->item(10, 'Course');
        $this->items[2] = $this->item(2, 'Target', ['lrmi:educationalLevel' => [$this->value(
            '',
            $course,
            'resource:item'
        )]]);
        $linked = $this->value('', $this->items[2], 'resource:item');
        $linked->method('valueAnnotation')->willReturn($this->annotation(['dcterms:description' => 'Original reason']));
        $this->items[1] = $this->item(1, '', ['lrmi:teaches' => [$linked, $this->value('legacy')]]);
        $diff = $this->service->preview(1, ['lrmi:teaches' => ['3', 3, 0, -1, 99]]);
        $this->assertSame([2], $diff['lrmi:teaches']['current']);
        $this->assertSame([3, 99], $diff['lrmi:teaches']['next']);
        $this->assertSame([99], $diff['lrmi:teaches']['invalid']);
        $this->assertSame('Target (Course)', $diff['lrmi:teaches']['titles'][2]);
        $this->assertSame([], $this->writes);
    }

    public function testApplyClearsOnlySelectedPropertyAndAppendsPrivateAuditWithBoundedReason(): void
    {
        $result = $this->service->apply(
            1,
            ['lrmi:teaches' => [2]],
            'Curator',
            ['lrmi:teaches' => [2 => str_repeat('x', 250)]]
        );
        $this->assertTrue($result['updated']);
        $data = $this->writes[0];
        $this->assertSame([$this->propertyId('lrmi:teaches')], $data['clear_property_values']);
        $value = $data['lrmi:teaches'][0];
        $this->assertSame(2, $value['value_resource_id']);
        $this->assertSame(200, mb_strlen($value['@annotation']['dcterms:description'][0]['@value']));
        $event = $data['dcterms:provenance'][0];
        $this->assertFalse($event['is_public']);
        $this->assertSame(
            $value['@annotation']['dcterms:modified'][0]['@value'],
            $event['@annotation']['dcterms:modified'][0]['@value']
        );
        $payload = CurationEvent::decode($event['@annotation']['dcterms:replaces'][0]['@value']);
        $this->assertSame([], $payload['terms']['lrmi:teaches']['before']);
        $this->assertSame([2], $payload['terms']['lrmi:teaches']['after']);
    }

    public function testClearingEntireDimensionAndMissingAuditPropertyRemainPartial(): void
    {
        $this->items[1] = $this->item(1, '', ['schema:about' => [$this->value('', $this->items[2], 'resource:item')]]);
        $this->missingProperties = ['dcterms:replaces'];
        $this->assertTrue($this->service->apply(1, ['schema:about' => []], 'Curator')['updated']);
        $this->assertArrayNotHasKey('schema:about', $this->writes[0]);
        $this->assertArrayNotHasKey('dcterms:provenance', $this->writes[0]);
    }

    public function testNoopAndUnconfiguredPropertiesDoNotWrite(): void
    {
        $this->assertFalse($this->service->apply(1, ['schema:isPartOf' => [2]], 'Curator')['updated']);
        $this->assertTrue($this->service->apply(1, ['lrmi:teaches' => []], 'Curator')['unchanged']);
        $this->missingProperties = ['schema:about'];
        $this->assertFalse($this->service->apply(1, ['schema:about' => [2]], 'Curator')['updated']);
        $this->assertSame([], $this->writes);
    }

    public function testWrongDimensionAndUnknownTargetsAreRejectedBeforeWrite(): void
    {
        $this->settings->method('get')->willReturn('Knowledge');
        $this->expectException(\RuntimeException::class);
        $this->service->apply(1, ['lrmi:teaches' => [2, 99]], 'Curator');
    }

    public function testDimensionTypeAndAxisMembershipAreValidated(): void
    {
        $this->settings->method('get')
            ->willReturnCallback(static fn ($key) => $key === CurriculumSearch::AXIS_SETTING ? 8 : 'Knowledge');
        $axisSet = $this->item(8, 'Axis set');
        $this->items[2] = $this->item(2, 'Target', ['dcterms:type' => [$this->value('Knowledge')],
            'schema:inDefinedTermSet' => [$this->value('', $axisSet, 'resource:item')]]);
        $this->assertSame([], $this->service->preview(1, ['lrmi:teaches' => [2]])['lrmi:teaches']['invalid']);
        $axes = $this->service->preview(1, ['dcterms:relation' => [2, 3]])['dcterms:relation'];
        $this->assertSame([3], $axes['invalid']);
        $this->assertSame('Target', $axes['titles'][2]);
    }

    public function testHistorySortsEventsIgnoresForeignAnnotationsAndToleratesDeletedTargets(): void
    {
        $payload = CurationEvent::build(['lrmi:teaches' => ['before' => [99], 'after' => [2], 'why' => []]]);
        $foreign = $this->event($payload, '2030', 'foreign');
        $invalid = $this->value('Invalid');
        $invalid->method('valueAnnotation')
            ->willReturn($this->annotation([
                'dcterms:provenance' => CurationEvent::MARKER,
                'dcterms:replaces' => '{}',
            ]));
        $this->items[1] = $this->item(1, '', ['dcterms:provenance' => [$this->value('Plain'), $foreign, $invalid,
            $this->event($payload, '2026-01-01'), $this->event($payload, '2026-02-01')]]);
        $this->assertSame('2026-02-01', $this->service->lastEvent(1)['when']);
        $this->assertCount(2, $this->service->history(1));
    }

    public function testUndoRejectsStaleStateAndForcedUndoDropsDeletedTargets(): void
    {
        $payload = CurationEvent::build(['lrmi:teaches' => ['before' => [2, 99], 'after' => [3],
            'why' => [2 => 'Restored reason']]]);
        $this->items[1] = $this->item(1, '', ['dcterms:provenance' => [$this->event($payload, '2026-01-01')]]);
        $this->assertSame('stale', $this->service->undo(1, 'Curator')['error']);
        $result = $this->service->undo(1, 'Curator', true);
        $this->assertTrue($result['updated']);
        $this->assertSame([99], $result['dropped']);
        $this->assertSame('2026-01-01', $result['undoneAt']);
        $this->assertSame(
            'Restored reason',
            $this->writes[0]['lrmi:teaches'][0]['@annotation']['dcterms:description'][0]['@value']
        );
    }

    public function testNoHistoryHasNoUndoOrLastEvent(): void
    {
        $this->assertSame([], $this->service->history(1));
        $this->assertNull($this->service->lastEvent(1));
        $this->assertSame('no-event', $this->service->undo(1, 'Curator')['error']);
    }
}
