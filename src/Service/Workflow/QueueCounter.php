<?php

declare(strict_types=1);

namespace OERManager\Service\Workflow;

use OERManager\Service\IdConstraints;
use OERManager\Service\MasterViewQuery;
use Omeka\Api\Manager as ApiManager;

/**
 * «Cola (N)» in the curator's navigation (TASK-067, ADR-0023 §3): REA in
 * Propuesto plus public REA outside the flow (status none of Propuesto,
 * Publicado, Descartado). Descartado never counts.
 *
 * Two COUNT-only searches (`limit => 0`: the core runs only the count query
 * and hydrates nothing). One query is not possible through the API: it cannot
 * OR a property condition with `is_public`. The queue's definition lives in
 * `IdConstraints::queueBranches()` (TASK-070), shared with the master view's
 * «En cola» filter.
 *
 * Not final so Module's navigation test can stand it in.
 */
class QueueCounter
{
    public function __construct(
        private readonly MasterViewQuery $masterViewQuery,
        private readonly ApiManager $api
    ) {
    }

    public function count(): int
    {
        $base = $this->masterViewQuery->buildSearchParams([]);
        if (null === ($base['resource_class_id'] ?? null)) {
            return 0;
        }
        $base['limit'] = 0;
        [$proposedBranch, $outOfFlowBranch] = IdConstraints::queueBranches();
        $proposed = $base + $proposedBranch;
        $outOfFlow = $base + $outOfFlowBranch;
        return (int) $this->api->search('items', $proposed)->getTotalResults()
            + (int) $this->api->search('items', $outOfFlow)->getTotalResults();
    }
}
