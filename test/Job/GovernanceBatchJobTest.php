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
        $logger = $this->createMock(\Laminas\Log\LoggerInterface::class);
        $services = $this->createMock(\Laminas\ServiceManager\ServiceLocatorInterface::class);
        $services->method('get')->willReturnMap([
            [GovernanceService::class, $governance],
            [ProposalStore::class, $store],
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

    public function testReplaceModePassesOnlyEmptyFalse(): void
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
        $logger = $this->createMock(\Laminas\Log\LoggerInterface::class);
        $services = $this->createMock(\Laminas\ServiceManager\ServiceLocatorInterface::class);
        $services->method('get')->willReturnMap([
            [GovernanceService::class, $governance],
            [ProposalStore::class, $store],
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
            'ids' => [5],
            'raw' => ['dcterms:license' => ['CC0']],
            'mode' => 'replace',
            'contributor' => 'admin@example.org',
        ];
        $job->job = new \Omeka\Entity\Job();
        $job->serviceLocator = $services;

        $job->perform();

        // When mode is 'replace', onlyEmpty should be false (5th parameter)
        $this->assertSame(false, $calls[0][4]);
        $state = $store->read(1);
        $this->assertSame('completed', $state['status']);

        array_map('unlink', glob($dir . '/*'));
        rmdir($dir);
    }

    public function testCatchesThrowableAndRecordsErrorState(): void
    {
        $dir = sys_get_temp_dir() . '/oer_batch_job_' . bin2hex(random_bytes(6));
        $store = new ProposalStore($dir);
        $governance = $this->createMock(GovernanceService::class);
        $governance->method('apply')->willReturn(['updated' => true, 'skipped' => []]);
        $logger = $this->createMock(\Laminas\Log\LoggerInterface::class);
        $logger->expects($this->once())->method('err')->with(
            $this->stringContains('OERManager governance batch job 1:')
        );

        $services = $this->createMock(\Laminas\ServiceManager\ServiceLocatorInterface::class);
        $services->method('get')->willReturnMap([
            [GovernanceService::class, $governance],
            [ProposalStore::class, $store],
            ['Omeka\Logger', $logger],
        ]);

        $job = new class extends GovernanceBatchJob {
            public array $args = [];
            public bool $shouldStopThrow = false;

            public function getArg($name, $default = null)
            {
                return $this->args[$name] ?? $default;
            }

            public function shouldStop()
            {
                if ($this->shouldStopThrow) {
                    throw new \RuntimeException('shouldStop error during batch');
                }
                return false;
            }
        };
        $job->args = [
            'ids' => [10],
            'raw' => ['dcterms:creator' => ['Test']],
            'mode' => 'fill',
            'contributor' => 'test@example.org',
        ];
        $job->job = new \Omeka\Entity\Job();
        $job->serviceLocator = $services;
        $job->shouldStopThrow = true;

        $job->perform();

        // After error, check that the error state was eventually recorded
        $state = $store->read(1);
        $this->assertSame('error', $state['status']);
        $this->assertSame('unexpected', $state['tallies']['code']);

        array_map('unlink', glob($dir . '/*'));
        rmdir($dir);
    }
}
