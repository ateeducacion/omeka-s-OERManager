<?php

declare(strict_types=1);

namespace OERManager\Service\Governance;

/**
 * Reads the Job entity behind a batch (TASK-028 slice 4) without the API.
 * In Omeka 4.2 `api()->read('jobs')` is denied to editor and reviewer — the
 * curators the batch is for — and allowed to site_admin for every user's job,
 * so ownership is decided here from the entity, not by the job ACL. The AI
 * propose status and cancel actions (IndexController) use it for the same reason.
 */
class BatchJobLookup
{
    /** @param object $entityManager Doctrine EntityManager (duck-typed: only find() is used) */
    public function __construct(private object $entityManager)
    {
    }

    /** @return array{class:string, ownerId:?int, status:string}|null */
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
        ];
    }
}
