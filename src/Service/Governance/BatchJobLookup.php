<?php

declare(strict_types=1);

namespace OERManager\Service\Governance;

use OERManager\Job\GovernanceBatchJob;
use OERManager\Job\GovernanceBatchUndoJob;

/**
 * Reads the Job entities behind batches (TASK-028 slices 4 and 5a) without the
 * API. In Omeka 4.2 `api()->read('jobs')` is denied to editor and reviewer —
 * the curators the batch is for — and allowed to site_admin for every user's
 * job, so ownership is decided by the callers from the entity, not by the job
 * ACL. The AI propose status and cancel actions (IndexController) use it for
 * the same reason.
 */
class BatchJobLookup
{
    public const FINISHED = ['completed', 'stopped', 'error'];
    /** Undo Jobs read to answer «is this batch undone?»; older ones count as none. */
    public const UNDO_SCAN = 200;
    /** An undo still «running» after this many seconds was killed (Omeka never marks it). */
    public const STALE_AFTER = 3600;

    private \Closure $now;

    /** @param object $entityManager Doctrine EntityManager (duck-typed: find() and getRepository()->findBy()) */
    public function __construct(private object $entityManager, ?\Closure $now = null)
    {
        $this->now = $now ?? static fn (): \DateTimeImmutable => new \DateTimeImmutable();
    }

    /** @return array{class:string, ownerId:?int, status:string, args:array}|null */
    public function find(int $jobId): ?array
    {
        $job = $this->entityManager->find('Omeka\Entity\Job', $jobId);
        if (null === $job) {
            return null;
        }
        $owner = $job->getOwner();
        return [
            'class' => (string) $job->getClass(),
            'ownerId' => null === $owner ? null : (int) $owner->getId(),
            'status' => (string) $job->getStatus(),
            'args' => (array) ($job->getArgs() ?? []),
        ];
    }

    /**
     * The most recent finished governance batches, newest first: the owner's,
     * or everyone's when $ownerId is null (a site_admin). Two bounded queries,
     * never a catalogue scan.
     *
     * @return list<array<string,mixed>>
     */
    public function recent(?int $ownerId, int $limit = 20): array
    {
        $criteria = ['class' => GovernanceBatchJob::class, 'status' => self::FINISHED];
        if (null !== $ownerId) {
            $criteria['owner'] = $ownerId;
        }
        $jobs = $this->entityManager->getRepository('Omeka\Entity\Job')->findBy($criteria, ['id' => 'DESC'], $limit);
        $undo = $this->latestUndoByBatch();
        $rows = [];
        foreach ($jobs as $job) {
            $args = (array) ($job->getArgs() ?? []);
            $owner = $job->getOwner();
            $id = (int) $job->getId();
            $rows[] = [
                'jobId' => $id,
                'ownerId' => null === $owner ? null : (int) $owner->getId(),
                'ownerName' => null === $owner ? null : (string) $owner->getName(),
                'started' => self::iso($job->getStarted()),
                'ended' => self::iso($job->getEnded()),
                'terms' => array_values(array_map('strval', array_keys((array) ($args['raw'] ?? [])))),
                'mode' => (string) ($args['mode'] ?? ''),
                'planned' => count((array) ($args['ids'] ?? [])),
                'status' => (string) $job->getStatus(),
                'undo' => $undo[$id] ?? ['state' => 'none', 'jobId' => null],
            ];
        }
        return $rows;
    }

    /** @return array{state:string, jobId:?int} */
    public function undoState(int $batchJobId): array
    {
        return $this->latestUndoByBatch()[$batchJobId] ?? ['state' => 'none', 'jobId' => null];
    }

    /** @return array<int, array{state:string, jobId:int}> latest undo Job per batch id */
    private function latestUndoByBatch(): array
    {
        $jobs = $this->entityManager->getRepository('Omeka\Entity\Job')
            ->findBy(['class' => GovernanceBatchUndoJob::class], ['id' => 'DESC'], self::UNDO_SCAN);
        $latest = [];
        foreach ($jobs as $job) {
            $batchJobId = ((array) ($job->getArgs() ?? []))['batchJobId'] ?? null;
            if (!is_int($batchJobId) || $batchJobId <= 0 || isset($latest[$batchJobId])) {
                continue;
            }
            $latest[$batchJobId] = ['state' => $this->stateOf($job), 'jobId' => (int) $job->getId()];
        }
        return $latest;
    }

    private function stateOf(object $job): string
    {
        $status = (string) $job->getStatus();
        if ('completed' === $status) {
            return 'done';
        }
        if (in_array($status, ['stopped', 'error'], true)) {
            return 'partial';
        }
        $started = $job->getStarted();
        if (null !== $started && ($this->now)()->getTimestamp() - $started->getTimestamp() > self::STALE_AFTER) {
            return 'partial';
        }
        return 'running';
    }

    private static function iso(?\DateTimeInterface $date): ?string
    {
        return $date?->format(DATE_ATOM);
    }
}
