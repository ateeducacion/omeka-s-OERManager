<?php

declare(strict_types=1);

namespace OERManager\Service\Ai;

use OERManager\Service\IdConstraintResolver;
use OERManager\Service\IdConstraints;
use Omeka\Api\Manager as ApiManager;

/**
 * TASK-011: which REA of a selection a batch proposes. Only REA in the queue
 * (IdConstraints::queueBranches) without a stored proposal enter; the rest is
 * skipped and counted. Id-only searches: no REA is read whole (NFR-004).
 */
class BatchProposeEligibility
{
    public function __construct(
        private ApiManager $api,
        private IdConstraintResolver $resolver
    ) {
    }

    /**
     * @param list<int> $ids
     * @return array{enter:list<int>, out_of_queue:list<int>, has_proposal:list<int>}
     */
    public function split(array $ids): array
    {
        $split = ['enter' => [], 'out_of_queue' => [], 'has_proposal' => []];
        if ([] === $ids) {
            return $split;
        }
        $queue = array_flip($this->resolver->resolve(['status' => IdConstraints::STATUS_QUEUE]) ?? []);
        $inQueue = [];
        foreach ($ids as $id) {
            if (isset($queue[$id])) {
                $inQueue[] = $id;
            } else {
                $split['out_of_queue'][] = $id;
            }
        }
        if ([] === $inQueue) {
            return $split;
        }
        $marked = array_flip(array_map('intval', (array) $this->api->search(
            'items',
            ['id' => $inQueue, 'property' => [StoredProposal::searchRow('sw')]],
            ['returnScalar' => 'id']
        )->getContent()));
        foreach ($inQueue as $id) {
            $split[isset($marked[$id]) ? 'has_proposal' : 'enter'][] = $id;
        }
        return $split;
    }

    public function stillEligible(int $id): bool
    {
        return [$id] === $this->split([$id])['enter'];
    }
}
