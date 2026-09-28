<?php

declare(strict_types=1);

namespace OERManager\Test\Job;

use OERManager\Job\GovernanceBatchJob;
use OERManager\Service\Ai\ProposalStore;
use OERManager\Service\GovernanceService;
use PHPUnit\Framework\TestCase;

final class GovernanceBatchJobTest extends TestCase
{
    public function testAppliesEveryIdWithTheBatchOptionsAndRecordsTheOutcome(): void
    {
        $dir = sys_get_temp_dir() . '/oer_batch_job_' . bin2hex(random_bytes(6));
        $store = new ProposalStore($dir);
        $governance = $this->createMock(GovernanceService::class);
        $calls = [];
        $governance->method('apply')->willReturnCallback(
            function (...$args) use (&$calls): array {
                $calls[] = $args;
                return ['updated' => true, 'skipped' => []];
            }
        );
        $entityManager = new class {
            public int $clears = 0;

            public function clear(): void
            {
                $this->clears++;
            }
        };
        $logger = $this->createMock(\Laminas\Log\LoggerInterface::class);
        $services = $this->createMock(\Laminas\ServiceManager\ServiceLocatorInterface::class);
        $services->method('get')->willReturnMap([
            [GovernanceService::class, $governance],
            [ProposalStore::class, $store],
            ['Omeka\EntityManager', $entityManager],
            ['Omeka\Logger', $logger],
        ]);

        $job = new class extends GovernanceBatchJob {
            public array $args = [];

            public function getArg($name, $default = null)
            {
                return $this->args[$name] ?? $default;
            }
        };
        $job->args = [
            'ids' => [3, 4],
            'raw' => ['dcterms:creator' => ['Ana']],
            'mode' => 'fill',
            'contributor' => 'curator@example.org',
        ];
        $job->job = new \Omeka\Entity\Job();
        $job->serviceLocator = $services;

        $job->perform();

        $this->assertSame(
            [3, ['dcterms:creator' => ['Ana']], 'curator@example.org', null, true, 'batch-1', false],
            $calls[0]
        );
        $state = $store->read(1);
        $this->assertSame('completed', $state['status']);
        $this->assertSame('batch-1', $state['batch']);
        $this->assertSame(2, $state['tallies']['written']);

        array_map('unlink', glob($dir . '/*'));
        rmdir($dir);
    }
}
