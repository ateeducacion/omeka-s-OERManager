<?php

declare(strict_types=1);

namespace OERManager\Service\Governance;

use OERManager\Service\Ai\ProgressReporter;
use OERManager\Service\Ai\ProposalStore;

/**
 * Batch job state in the same private store the AI propose uses (TASK-020),
 * tagged with `kind` (`governance-batch` or, for the batch undo of slice 5a,
 * `governance-batch-undo`) so the status endpoint never serves another job's state
 * as a batch.
 */
final class BatchProgressReporter implements ProgressReporter
{
    public const KIND = 'governance-batch';
    public const UNDO_KIND = 'governance-batch-undo';

    /** @param callable():bool $shouldStop */
    public function __construct(
        private ProposalStore $store,
        private $shouldStop,
        private int $jobId,
        private string $kind = self::KIND
    ) {
    }

    public function report(string $step, int $done, int $total): void
    {
        $this->store->write($this->jobId, [
            'kind' => $this->kind,
            'status' => 'in_progress',
            'done' => $done,
            'total' => $total,
        ]);
    }

    public function shouldStop(): bool
    {
        return (bool) ($this->shouldStop)();
    }

    /** @param array<string,mixed> $tallies */
    public function finish(string $status, array $tallies, string $batch): void
    {
        $this->store->write($this->jobId, [
            'kind' => $this->kind,
            'status' => $status,
            'batch' => $batch,
            'tallies' => $tallies,
        ]);
    }
}
