<?php

declare(strict_types=1);

namespace OERManager\Job;

use OERManager\Service\Ai\ProposalStore;
use OERManager\Service\Governance\BatchJobLookup;
use OERManager\Service\Governance\BatchProgressReporter;
use OERManager\Service\Governance\BatchUndoRunner;
use OERManager\Service\GovernanceService;
use OERManager\Service\RecatalogService;
use Omeka\Job\AbstractJob;

/**
 * Undo of a whole governance batch (TASK-028 slice 5a). Thin glue: the loop and
 * the per-REA rule live in BatchUndoRunner; the write is
 * GovernanceService::undoEvent(), tagged `batch-<this job id>`. The ids are
 * read from the original GovernanceBatchJob's args, never from the request.
 * Runs as its owner, so the native edit ACL decides REA by REA. Does not clear
 * the EntityManager, for the reason GovernanceBatchJob gives.
 */
class GovernanceBatchUndoJob extends AbstractJob
{
    /**
     * The original batch's plan ids, or null when they cannot be trusted:
     * missing Job, another class, or args whose `ids` is not a non-empty list
     * of positive integers.
     *
     * @param array{class:string, args?:array}|null $original
     * @return list<int>|null
     */
    public static function planIds(?array $original): ?array
    {
        if (null === $original || GovernanceBatchJob::class !== ($original['class'] ?? null)) {
            return null;
        }
        $raw = $original['args']['ids'] ?? null;
        if (!is_array($raw) || [] === $raw) {
            return null;
        }
        $ids = [];
        foreach ($raw as $id) {
            if (!is_int($id) && !(is_string($id) && ctype_digit($id))) {
                return null;
            }
            if ((int) $id <= 0) {
                return null;
            }
            $ids[] = (int) $id;
        }
        return $ids;
    }

    public function perform(): void
    {
        $services = $this->getServiceLocator();
        /** @var GovernanceService $governance */
        $governance = $services->get(GovernanceService::class);
        /** @var RecatalogService $recatalog */
        $recatalog = $services->get(RecatalogService::class);
        /** @var ProposalStore $store */
        $store = $services->get(ProposalStore::class);
        /** @var BatchJobLookup $lookup */
        $lookup = $services->get(BatchJobLookup::class);
        $logger = $services->get('Omeka\Logger');

        $jobId = (int) $this->job->getId();
        $tag = 'batch-' . $jobId;
        $batchJobId = (int) $this->getArg('batchJobId', 0);
        $contributor = (string) $this->getArg('contributor', 'unknown');
        $progress = new BatchProgressReporter(
            $store,
            fn (): bool => $this->shouldStop(),
            $jobId,
            BatchProgressReporter::UNDO_KIND
        );

        $ids = $batchJobId > 0 ? self::planIds($lookup->find($batchJobId)) : null;
        if (null === $ids) {
            $progress->finish('error', ['code' => 'plan_unreadable'], $tag);
            return;
        }

        try {
            $tallies = (new BatchUndoRunner())->run(
                $ids,
                'batch-' . $batchJobId,
                static fn (int $id): array => $recatalog->events($id),
                static fn (int $id, array $event): array => $governance->undoEvent(
                    $id,
                    $event,
                    $contributor,
                    false,
                    $tag
                ),
                $progress,
                static function (string $message) use ($logger): void {
                    $logger->err($message);
                }
            );
            $progress->finish($tallies['stopped'] ? 'stopped' : 'completed', $tallies, $tag);
        } catch (\Throwable $e) {
            $logger->err('OERManager governance batch undo job ' . $jobId . ': ' . $e->getMessage());
            $progress->finish('error', ['code' => 'unexpected'], $tag);
        }
    }
}
