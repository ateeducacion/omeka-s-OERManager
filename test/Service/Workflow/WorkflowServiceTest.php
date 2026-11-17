<?php

declare(strict_types=1);

namespace OERManager\Test\Service\Workflow;

use OERManager\Service\Curation\CurationWriter;
use OERManager\Service\Workflow\WorkflowService;
use OERManager\Service\Workflow\WorkflowStatus;
use OERManager\Test\Support\RepresentationFactory;
use Omeka\Api\Manager;
use PHPUnit\Framework\TestCase;

final class WorkflowServiceTest extends TestCase
{
    use RepresentationFactory;

    private const PROPERTY_IDS = [
        WorkflowStatus::STATUS_TERM => 5,
        WorkflowStatus::NOTE_TERM => 6,
        'dcterms:contributor' => 7,
        'dcterms:modified' => 8,
        'dcterms:provenance' => 9,
    ];

    /** @var list<array<string,mixed>> */
    private array $writes = [];

    private function service(array $propertyIds = self::PROPERTY_IDS): WorkflowService
    {
        $api = $this->createMock(Manager::class);
        $api->method('search')->willReturnCallback(function ($resource, $query) use ($propertyIds) {
            $this->assertSame('properties', $resource);
            $id = $propertyIds[$query['term']] ?? null;
            return $this->response(null === $id ? [] : [$this->item($id)]);
        });
        $api->method('update')->willReturnCallback(function ($resource, $id, $data, $files, $options) {
            $this->assertSame('items', $resource);
            $this->assertSame(1, $id);
            // WorkflowService's isPartial trap: targeted clear + append, never a bare isPartial.
            $this->assertSame(['isPartial' => true, 'collectionAction' => 'append'], $options);
            $this->writes[] = $data;
        });
        return new WorkflowService($api, new CurationWriter($api));
    }

    private function rea(?string $status, bool $isPublic = false)
    {
        $values = null === $status ? [] : [WorkflowStatus::STATUS_TERM => [$this->value($status)]];
        $item = $this->item(1, '', $values);
        $item->method('isPublic')->willReturn($isPublic);
        return $item;
    }

    /** @return array{status:?string, note:?string, public:?bool, annotation:array} */
    private function written(int $index): array
    {
        $data = $this->writes[$index];
        $this->assertSame([5, 6], $data['clear_property_values'], 'only status and note are cleared');
        $status = $data[WorkflowStatus::STATUS_TERM][0];
        $this->assertSame(5, $status['property_id']);
        return [
            'status' => $status['@value'],
            'note' => $data[WorkflowStatus::NOTE_TERM][0]['@value'] ?? null,
            'public' => $data['o:is_public'] ?? null,
            'annotation' => $status['@annotation'],
        ];
    }

    public function testEveryTransitionWritesItsStateNoteVisibilityAndAnnotation(): void
    {
        $service = $this->service();
        $this->assertSame(['updated' => true, 'status' => 'Propuesto'], $service->propose($this->rea(null), 'Ana'));
        $this->assertTrue($service->reject($this->rea('Propuesto'), ' Fix metadata ', 'Cu')['updated']);
        $this->assertTrue($service->publish($this->rea('Propuesto'), 'Cu')['updated']);
        $this->assertTrue($service->unpublish($this->rea('Publicado', true), 'Wrong licence', 'Cu')['updated']);
        $this->assertTrue($service->discard($this->rea('Rechazado'), 'Duplicate', 'Cu')['updated']);
        $this->assertTrue($service->reopen($this->rea('Descartado'), 'Cu')['updated']);

        $expected = [
            ['Propuesto', null, null],
            ['Rechazado', 'Fix metadata', null],
            ['Publicado', null, true],
            ['Propuesto', 'Wrong licence', false],
            ['Descartado', 'Duplicate', false],
            ['Propuesto', null, null],
        ];
        foreach ($expected as $index => [$status, $note, $public]) {
            $written = $this->written($index);
            $this->assertSame($status, $written['status'], "write $index");
            $this->assertSame($note, $written['note'], "write $index");
            $this->assertSame($public, $written['public'], "write $index");
        }
        // Propose and reopen clear an old reason: the note is cleared and nothing appended.
        $this->assertSame([], $this->writes[0][WorkflowStatus::NOTE_TERM]);
        $this->assertSame([], $this->writes[5][WorkflowStatus::NOTE_TERM]);

        // ADR-0002: who and when on the status value, same format and precision as the re-cataloguer.
        $annotation = $this->written(2)['annotation'];
        $this->assertSame('Cu', $annotation['dcterms:contributor'][0]['@value']);
        $this->assertSame(7, $annotation['dcterms:contributor'][0]['property_id']);
        $this->assertMatchesRegularExpression(
            '/^\d{4}-\d\d-\d\dT\d\d:\d\d:\d\d\.\d{6}[+-]\d\d:\d\d$/',
            $annotation['dcterms:modified'][0]['@value']
        );
        $this->assertSame('OERManager flujo: Publicado', $annotation['dcterms:provenance'][0]['@value']);
        $this->assertSame('Ana', $this->written(0)['annotation']['dcterms:contributor'][0]['@value']);
    }

    public function testPublicOutOfFlowReaCanBeRegularisedEitherWay(): void
    {
        $service = $this->service();
        $this->assertTrue($service->publish($this->rea(null, true), 'Cu')['updated']);
        $this->assertTrue($service->unpublish($this->rea(null, true), 'Back to queue', 'Cu')['updated']);
        $this->assertSame('Publicado', $this->written(0)['status']);
        $this->assertSame('Propuesto', $this->written(1)['status']);
        $this->assertFalse($this->written(1)['public']);
    }

    public function testInvalidTransitionsAndMissingPropertiesDoNotWrite(): void
    {
        $service = $this->service();
        $this->assertSame('invalid_transition', $service->reject($this->rea(' '), 'x', 'Cu')['error']);
        $this->assertSame('invalid_transition', $service->publish($this->rea(null), 'Cu')['error']);
        $this->assertSame('invalid_transition', $service->publish($this->rea('Descartado', true), 'Cu')['error']);
        $this->assertSame('invalid_transition', $service->propose($this->rea('Propuesto'), 'Cu')['error']);
        $this->assertSame('invalid_transition', $service->discard($this->rea('Publicado', true), 'x', 'Cu')['error']);
        $this->assertSame('invalid_transition', $service->reopen($this->rea('Propuesto'), 'Cu')['error']);
        $this->assertSame('invalid_transition', $service->unpublish($this->rea('Propuesto'), 'x', 'Cu')['error']);
        $this->assertSame([], $this->writes);

        $bare = $this->service([]);
        $this->assertSame('missing_property', $bare->propose($this->rea(null), 'Cu')['error']);
        $this->assertSame('missing_property', $bare->publish($this->rea('Propuesto'), 'Cu')['error']);
        $this->assertSame([], $this->writes);
    }

    public function testStatusIsReadNormalised(): void
    {
        $service = $this->service();
        $this->assertNull($service->statusOf($this->rea(null)));
        $this->assertSame('Publicado', $service->statusOf($this->rea(' Publicado ')));
    }
}
