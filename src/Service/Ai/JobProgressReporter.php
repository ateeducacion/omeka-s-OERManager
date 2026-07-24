<?php

namespace OERManager\Service\Ai;

use Omeka\Job\AbstractJob;

/**
 * ProgressReporter de producción (TASK-020): publica el progreso en el ProposalStore
 * para que el polling lo lea, y consulta la parada por la señal nativa del Job
 * (AbstractJob::shouldStop() relee el estado de BD, así que ve una cancelación
 * puesta por el proceso web). El jobId se pasa explícito (el Job ya lo tiene a mano)
 * para no depender de accesores no públicos de AbstractJob.
 */
final class JobProgressReporter implements ProgressReporter
{
    public function __construct(
        private ProposalStore $store,
        private AbstractJob $job,
        private int $jobId
    ) {
    }

    public function report(string $step, int $done, int $total): void
    {
        $this->store->write($this->jobId, [
            'status' => 'in_progress',
            'step' => $step,
            'done' => $done,
            'total' => $total,
        ]);
    }

    public function shouldStop(): bool
    {
        return $this->job->shouldStop();
    }
}
