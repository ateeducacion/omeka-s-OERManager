<?php

declare(strict_types=1);

namespace OERManager\Test\Service\Governance;

use OERManager\Service\Ai\ProposalStore;
use OERManager\Service\Governance\BatchProgressReporter;
use PHPUnit\Framework\TestCase;

final class BatchProgressReporterTest extends TestCase
{
    public function testWritesBatchShapedStateAndDelegatesStop(): void
    {
        $dir = sys_get_temp_dir() . '/oer_batch_state_' . bin2hex(random_bytes(6));
        $store = new ProposalStore($dir);
        $reporter = new BatchProgressReporter($store, static fn (): bool => true, 12);

        $reporter->report('batch', 25, 100);
        $this->assertSame(
            ['kind' => 'governance-batch', 'status' => 'in_progress', 'done' => 25, 'total' => 100],
            $store->read(12)
        );
        $this->assertTrue($reporter->shouldStop());

        $reporter->finish('completed', ['written' => 3], 'batch-12');
        $this->assertSame(
            ['kind' => 'governance-batch', 'status' => 'completed', 'batch' => 'batch-12', 'tallies' => ['written' => 3]],
            $store->read(12)
        );

        array_map('unlink', glob($dir . '/*'));
        rmdir($dir);
    }
}
