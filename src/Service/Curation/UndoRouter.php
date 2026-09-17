<?php

declare(strict_types=1);

namespace OERManager\Service\Curation;

use OERManager\Service\CurationEvent;
use OERManager\Service\GovernanceService;
use OERManager\Service\RecatalogService;

/**
 * Routes "undo the last curation event" to the service that owns its scope
 * (TASK-028 rebanada 3b). One shared ledger (ADR-0020) mixes recataloguing and
 * governance events on the same item, but only the service that wrote an
 * event knows how to reverse it — RecatalogService::undo() replays alignment
 * dimensions, GovernanceService::undoEvent() replays the five governance
 * terms. CurationEvent::scopeOf() reads the marker each event's own payload
 * already carries (Task 1) to decide which one applies; the caller never has
 * to know.
 */
final class UndoRouter
{
    public function __construct(
        private RecatalogService $recatalog,
        private GovernanceService $governance
    ) {
    }

    /** @return array<string,mixed> */
    public function undo(int $itemId, string $contributor, bool $force = false): array
    {
        $event = $this->recatalog->lastEvent($itemId);
        if (null === $event) {
            return ['updated' => false, 'error' => 'no-event'];
        }
        return 'governance' === CurationEvent::scopeOf($event['payload'])
            ? $this->governance->undoEvent($itemId, $event, $contributor, $force)
            : $this->recatalog->undo($itemId, $contributor, $force);
    }
}
