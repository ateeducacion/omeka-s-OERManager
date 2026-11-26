<?php

declare(strict_types=1);

namespace OERManager\Job;

use OERManager\Service\Ai\BatchProposeEligibility;
use OERManager\Service\Ai\ProgressReporter;
use OERManager\Service\Ai\ProposalStore;
use OERManager\Service\Ai\ProposeRunner;
use OERManager\Service\Ai\StoredProposals;
use OERManager\Service\Governance\BatchProgressReporter;
use OERManager\Service\Llm\LlmException;
use OERManager\Service\Llm\LlmSettings;
use Omeka\Job\AbstractJob;

/**
 * TASK-011: the batch AI proposal. For each REA still in the queue without a
 * proposal, and that the owner may update, runs the single proposal's
 * pipeline (ProposeRunner) and stores the result in the REA (StoredProposals).
 * It never applies a proposal (ADR-0007 addendum 1).
 *
 * The loop is `run()`, pure and host-tested; `perform()` only wires it. No
 * EntityManager::clear(): it would detach the authenticated owner (see
 * GovernanceBatchJob), and memory stays small per REA.
 */
class AiBatchProposeJob extends AbstractJob
{
    public const KIND = 'ai-batch';
    /** LLM errors in a row that abort: a bad key, quota or outage would fail every remaining REA. */
    public const ABORT_AFTER = 5;

    public function perform(): void
    {
        $services = $this->getServiceLocator();
        /** @var ProposeRunner $runner */
        $runner = $services->get(ProposeRunner::class);
        /** @var StoredProposals $proposals */
        $proposals = $services->get(StoredProposals::class);
        /** @var BatchProposeEligibility $eligibility */
        $eligibility = $services->get(BatchProposeEligibility::class);
        $acl = $services->get('Omeka\Acl');
        $logger = $services->get('Omeka\Logger');
        $model = (string) $services->get('Omeka\Settings')->get(LlmSettings::MODEL, '');

        $jobId = (int) $this->job->getId();
        $batch = 'ai-batch-' . $jobId;
        $ids = array_values(array_filter(
            array_map('intval', (array) $this->getArg('ids', [])),
            static fn (int $id): bool => $id > 0
        ));
        $progress = new BatchProgressReporter(
            $services->get(ProposalStore::class),
            fn (): bool => $this->shouldStop(),
            $jobId,
            self::KIND
        );
        try {
            $tallies = self::run(
                $ids,
                static fn (int $id): bool => $eligibility->stillEligible($id) && $proposals->mayUpdate($id, $acl),
                static fn (int $id): array => $runner->run($id),
                static fn (int $id, array $payload): bool => $proposals->store($id, $payload, $model),
                $progress,
                static function (string $message) use ($logger): void {
                    $logger->err($message);
                }
            );
            $status = $tallies['aborted'] ? 'error' : ($tallies['stopped'] ? 'stopped' : 'completed');
            $progress->finish($status, $tallies, $batch);
        } catch (\Throwable $e) {
            $logger->err('OERManager ai batch job ' . $jobId . ': ' . $e->getMessage());
            $progress->finish('error', ['code' => 'unexpected'], $batch);
        }
    }

    /**
     * @param list<int> $ids
     * @param callable(int):bool $eligible re-checked per REA: the queue may have moved since the preview
     * @param callable(int):array $propose ProposeRunner::run()
     * @param callable(int, array):bool $store
     * @param callable(string):void|null $log
     * @return array{done:int,total:int,proposed:int,skipped:int,failed:list<array{id:int,code:string}>,stopped:bool,aborted:bool}
     */
    public static function run(
        array $ids,
        callable $eligible,
        callable $propose,
        callable $store,
        ProgressReporter $progress,
        ?callable $log = null
    ): array {
        $total = count($ids);
        $tallies = ['done' => 0, 'total' => $total, 'proposed' => 0, 'skipped' => 0,
            'failed' => [], 'stopped' => false, 'aborted' => false];
        $streak = 0;
        $progress->report('batch', 0, $total);
        foreach ($ids as $id) {
            if ($progress->shouldStop()) {
                $tallies['stopped'] = true;
                break;
            }
            try {
                if (!$eligible($id)) {
                    $tallies['skipped']++;
                } elseif ($store($id, $propose($id))) {
                    $tallies['proposed']++;
                    $streak = 0;
                } else {
                    $tallies['failed'][] = ['id' => $id, 'code' => 'not_stored'];
                }
            } catch (LlmException $e) {
                $streak++;
                $tallies['failed'][] = ['id' => $id, 'code' => 'llm'];
                self::log($log, $id, $e);
            } catch (\Throwable $e) {
                $tallies['failed'][] = ['id' => $id, 'code' => 'unexpected'];
                self::log($log, $id, $e);
            }
            $tallies['done']++;
            $progress->report('batch', $tallies['done'], $total);
            if ($streak >= self::ABORT_AFTER) {
                $tallies['aborted'] = true;
                break;
            }
        }
        return $tallies;
    }

    private static function log(?callable $log, int $id, \Throwable $e): void
    {
        if (null !== $log) {
            $log('OERManager ai batch item ' . $id . ': ' . $e->getMessage());
        }
    }
}
