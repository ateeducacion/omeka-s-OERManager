<?php

declare(strict_types=1);

namespace OERManager\Test\Service\Workflow;

use OERManager\Service\Workflow\WorkflowService;
use OERManager\Service\Workflow\WorkflowStatus;
use OERManager\Test\Support\RepresentationFactory;
use Omeka\Api\Manager;
use PHPUnit\Framework\TestCase;

final class WorkflowServiceTest extends TestCase
{
    use RepresentationFactory;

    public function testProposeRejectPublishPreserveOtherPropertiesAndClearOldNotes(): void
    {
        $api = $this->createMock(Manager::class);
        $api->expects($this->exactly(2))->method('search')->willReturnCallback(function ($resource, $query) {
            return $this->response([$this->item($query['term'] === WorkflowStatus::STATUS_TERM ? 5 : 6)]);
        });
        $writes = [];
        $api->method('update')->willReturnCallback(function ($resource, $id, $data, $files, $options) use (&$writes) {
            $this->assertSame('items', $resource);
            $this->assertSame(1, $id);
            $this->assertSame(['isPartial' => true, 'collectionAction' => 'append'], $options);
            $this->assertSame([5, 6], $data['clear_property_values']);
            $writes[] = $data;
        });
        $service = new WorkflowService($api);
        $draft = $this->item(1);
        $proposed = $this->item(1, '', [WorkflowStatus::STATUS_TERM => [$this->value(WorkflowStatus::PROPOSED)]]);
        $this->assertTrue($service->propose($draft)['updated']);
        $this->assertSame([], $writes[0][WorkflowStatus::NOTE_TERM]);
        $this->assertTrue($service->reject($proposed, ' Fix metadata ')['updated']);
        $this->assertSame('Fix metadata', $writes[1][WorkflowStatus::NOTE_TERM][0]['@value']);
        $this->assertTrue($service->publish($proposed)['updated']);
        $this->assertTrue($writes[2]['o:is_public']);
        $this->assertSame([], $writes[2][WorkflowStatus::STATUS_TERM]);
    }

    public function testInvalidTransitionsAndMissingPropertiesDoNotWrite(): void
    {
        $api = $this->createMock(Manager::class);
        $api->method('search')->willReturn($this->response([]));
        $api->expects($this->never())->method('update');
        $service = new WorkflowService($api);
        $draft = $this->item(1, '', [WorkflowStatus::STATUS_TERM => [$this->value(' ')]]);
        $proposed = $this->item(1, '', [WorkflowStatus::STATUS_TERM => [$this->value(WorkflowStatus::PROPOSED)]]);
        $this->assertSame('invalid_transition', $service->reject($draft, '')['error']);
        $this->assertSame('invalid_transition', $service->publish($draft)['error']);
        $this->assertSame('invalid_transition', $service->propose($proposed)['error']);
        $this->assertSame('missing_property', $service->propose($draft)['error']);
        $this->assertSame('missing_property', $service->publish($proposed)['error']);
    }
}
