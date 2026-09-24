<?php

declare(strict_types=1);

namespace OERManager\Test\Service\Curation;

use OERManager\Service\Curation\UndoRouter;
use OERManager\Service\CurationEvent;
use OERManager\Service\GovernanceService;
use OERManager\Service\RecatalogService;
use PHPUnit\Framework\TestCase;

final class UndoRouterTest extends TestCase
{
    private $recatalog;
    private $governance;
    private UndoRouter $router;

    protected function setUp(): void
    {
        $this->recatalog = $this->createMock(RecatalogService::class);
        $this->governance = $this->createMock(GovernanceService::class);
        $this->router = new UndoRouter($this->recatalog, $this->governance);
    }

    public function testNoEventReturnsNoEventWithoutRoutingAnywhere(): void
    {
        $this->recatalog->method('lastEvent')->willReturn(null);
        $this->governance->expects($this->never())->method('undoEvent');

        $this->assertSame(['updated' => false, 'error' => 'no-event'], $this->router->undo(1, 'Curator'));
    }

    public function testGovernanceScopedEventRoutesToGovernanceService(): void
    {
        $payload = CurationEvent::buildTyped([
            'dcterms:license' => ['before' => [], 'after' => [['type' => 'uri', 'uri' => 'https://x/by/4.0/']]],
        ]);
        $event = ['when' => 'today', 'payload' => $payload];
        $this->recatalog->method('lastEvent')->willReturn($event);
        $this->recatalog->expects($this->never())->method('undo');
        $this->governance->expects($this->once())
            ->method('undoEvent')->with(1, $event, 'Curator', false)->willReturn(['updated' => true]);

        $this->assertSame(['updated' => true], $this->router->undo(1, 'Curator'));
    }

    public function testCurriculumScopedEventRoutesToRecatalogService(): void
    {
        $payload = CurationEvent::build(['lrmi:teaches' => ['before' => [], 'after' => [7]]]);
        $event = ['when' => 'today', 'payload' => $payload];
        $this->recatalog->method('lastEvent')->willReturn($event);
        $this->governance->expects($this->never())->method('undoEvent');
        $this->recatalog->expects($this->once())
            ->method('undo')->with(1, 'Curator', true)->willReturn(['updated' => true]);

        $this->assertSame(['updated' => true], $this->router->undo(1, 'Curator', true));
    }
}
