<?php

declare(strict_types=1);

namespace OERManager\Test\Job;

use OERManager\Job\GovernanceBatchJob;
use OERManager\Job\GovernanceBatchUndoJob;
use OERManager\Service\Ai\ProposalStore;
use OERManager\Service\CurationEvent;
use OERManager\Service\Governance\BatchJobLookup;
use OERManager\Service\GovernanceService;
use OERManager\Service\RecatalogService;
use PHPUnit\Framework\TestCase;

final class GovernanceBatchUndoJobTest extends TestCase
{
    private string $dir;
    private ProposalStore $store;
    private $governance;
    private $recatalog;
    private $lookup;
    private array $undoCalls = [];

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/oer_undo_job_' . bin2hex(random_bytes(6));
        $this->store = new ProposalStore($this->dir);
        $this->governance = $this->createMock(GovernanceService::class);
        $this->governance->method('undoEvent')->willReturnCallback(function (...$args): array {
            $this->undoCalls[] = $args;
            return ['updated' => true];
        });
        $this->recatalog = $this->createMock(RecatalogService::class);
        $this->recatalog->method('events')->willReturn([[
            'when' => 't2',
            'payload' => ['v' => 2, 'op' => CurationEvent::OP_GOVERNANCE, 'batch' => 'batch-7', 'terms' => []],
        ]]);
        $this->lookup = $this->createMock(BatchJobLookup::class);
    }

    protected function tearDown(): void
    {
        array_map('unlink', glob($this->dir . '/*') ?: []);
        @rmdir($this->dir);
    }

    private function job(array $args): GovernanceBatchUndoJob
    {
        $services = $this->createMock(\Laminas\ServiceManager\ServiceLocatorInterface::class);
        $services->method('get')->willReturnMap([
            [GovernanceService::class, $this->governance],
            [RecatalogService::class, $this->recatalog],
            [ProposalStore::class, $this->store],
            [BatchJobLookup::class, $this->lookup],
            ['Omeka\Logger', $this->createMock(\Laminas\Log\LoggerInterface::class)],
        ]);
        $job = new class extends GovernanceBatchUndoJob {
            public array $args = [];

            public function getArg($name, $default = null)
            {
                return $this->args[$name] ?? $default;
            }
        };
        $job->args = $args;
        $job->job = new \Omeka\Entity\Job();
        $job->serviceLocator = $services;
        return $job;
    }

    public function testUndoesThePlanOfTheOriginalBatchWithItsOwnTag(): void
    {
        $this->lookup->method('find')->with(7)->willReturn([
            'class' => GovernanceBatchJob::class, 'ownerId' => 3, 'status' => 'completed',
            'args' => ['ids' => [4, '5'], 'raw' => [], 'mode' => 'replace'],
        ]);

        $this->job(['batchJobId' => 7, 'contributor' => 'Curator'])->perform();

        $this->assertSame([4, 5], array_column($this->undoCalls, 0));
        $this->assertSame('Curator', $this->undoCalls[0][2]);
        $this->assertFalse($this->undoCalls[0][3]);
        $this->assertSame('batch-1', $this->undoCalls[0][4]);
        $state = $this->store->read(1);
        $this->assertSame('governance-batch-undo', $state['kind']);
        $this->assertSame('completed', $state['status']);
        $this->assertSame('batch-1', $state['batch']);
        $this->assertSame(2, $state['tallies']['undone']);
    }

    public function testAnUnreadablePlanWritesNothing(): void
    {
        $cases = [
            null,
            ['class' => 'Omeka\Job\BatchUpdate', 'ownerId' => 3, 'status' => 'completed', 'args' => ['ids' => [4]]],
            ['class' => GovernanceBatchJob::class, 'ownerId' => 3, 'status' => 'completed', 'args' => ['ids' => 'x']],
            ['class' => GovernanceBatchJob::class, 'ownerId' => 3, 'status' => 'completed', 'args' => ['ids' => []]],
            ['class' => GovernanceBatchJob::class, 'ownerId' => 3, 'status' => 'completed', 'args' => ['ids' => [4, 0]]],
            ['class' => GovernanceBatchJob::class, 'ownerId' => 3, 'status' => 'completed', 'args' => ['ids' => [4, 'a']]],
            ['class' => GovernanceBatchJob::class, 'ownerId' => 3, 'status' => 'completed', 'args' => []],
        ];
        foreach ($cases as $i => $original) {
            $this->assertNull(GovernanceBatchUndoJob::planIds($original), "case $i");
        }
        $this->assertSame([4, 5], GovernanceBatchUndoJob::planIds(
            ['class' => GovernanceBatchJob::class, 'ownerId' => 3, 'status' => 'completed', 'args' => ['ids' => [4, '5']]]
        ));

        $this->lookup->method('find')->willReturn(null);
        $this->job(['batchJobId' => 7, 'contributor' => 'Curator'])->perform();

        $this->assertSame([], $this->undoCalls);
        $this->assertSame(
            ['kind' => 'governance-batch-undo', 'status' => 'error', 'batch' => 'batch-1', 'tallies' => ['code' => 'plan_unreadable']],
            $this->store->read(1)
        );
    }

    public function testAMissingBatchIdIsUnreadable(): void
    {
        $this->lookup->expects($this->never())->method('find');

        $this->job(['contributor' => 'Curator'])->perform();

        $this->assertSame('plan_unreadable', $this->store->read(1)['tallies']['code']);
    }
}
