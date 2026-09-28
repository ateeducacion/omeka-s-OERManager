<?php

declare(strict_types=1);

namespace OERManager\Job;

use OERManager\Service\Ai\ProposalStore;
use OERManager\Service\Governance\BatchProgressReporter;
use OERManager\Service\Governance\BatchRequest;
use OERManager\Service\Governance\GovernanceBatchRunner;
use OERManager\Service\GovernanceService;
use Omeka\Job\AbstractJob;

/**
 * Batch assignment of licence and authorship (TASK-028 slice 4). Thin glue:
 * the loop lives in GovernanceBatchRunner, the per-item write in
 * GovernanceService::apply(). Runs as its owner, so the native edit ACL
 * decides item by item. Does not clear the EntityManager because the job and
 * owner entities must stay managed for shouldStop() and the dispatcher's
 * final status write.
 */
class GovernanceBatchJob extends AbstractJob
{
    public function perform(): void
    {
        $services = $this->getServiceLocator();
        /** @var GovernanceService $governance */
        $governance = $services->get(GovernanceService::class);
        /** @var ProposalStore $store */
        $store = $services->get(ProposalStore::class);
        $logger = $services->get('Omeka\Logger');

        $jobId = (int) $this->job->getId();
        $batch = 'batch-' . $jobId;
        $ids = array_map('intval', (array) $this->getArg('ids', []));
        $raw = (array) $this->getArg('raw', []);
        $onlyEmpty = BatchRequest::MODE_REPLACE !== (string) $this->getArg('mode', BatchRequest::MODE_FILL);
        $contributor = (string) $this->getArg('contributor', 'unknown');

        $progress = new BatchProgressReporter($store, fn (): bool => $this->shouldStop(), $jobId);
        try {
            $tallies = (new GovernanceBatchRunner())->run(
                $ids,
                static fn (int $id): array => $governance->apply(
                    $id,
                    $raw,
                    $contributor,
                    null,
                    $onlyEmpty,
                    $batch,
                    false
                ),
                $progress,
                null,
                static function (string $message) use ($logger): void {
                    $logger->err($message);
                }
            );
            $progress->finish($tallies['stopped'] ? 'stopped' : 'completed', $tallies, $batch);
        } catch (\Throwable $e) {
            $logger->err('OERManager governance batch job ' . $jobId . ': ' . $e->getMessage());
            $progress->finish('error', ['code' => 'unexpected'], $batch);
        }
    }
}
