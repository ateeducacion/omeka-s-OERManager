<?php

declare(strict_types=1);

namespace OERManager\Service\Governance;

use OERManager\Service\Ai\ProgressReporter;
use OERManager\Service\CurationEvent;
use Omeka\Api\Exception\NotFoundException;
use Omeka\Permissions\Exception\PermissionDeniedException;

/**
 * The batch undo loop (TASK-028 slice 5a), pure: reading an item's events and
 * undoing one are injected. An REA is undone only when the batch's event is
 * still its last one; the stale check inside the injected undo covers values
 * changed without an event. Anything touched after the batch is skipped and
 * reported, never overwritten (owner decision D2). One REA's failure never
 * aborts the undo; cancel is honoured between REA.
 */
final class BatchUndoRunner
{
    public const PROGRESS_EVERY = 25;

    public const UNDO = 'undo';
    public const ALREADY_UNDONE = 'already_undone';
    public const MODIFIED_LATER = 'modified_later';
    public const NOT_IN_BATCH = 'not_in_batch';

    /**
     * What to do with one REA, from its events (newest first).
     *
     * @param list<array<string,mixed>> $events
     */
    public static function classify(array $events, string $batch): string
    {
        $last = $events[0] ?? null;
        if (null === $last) {
            return self::NOT_IN_BATCH;
        }
        $payload = (array) ($last['payload'] ?? []);
        if (self::isBatchWrite($payload, $batch)) {
            return self::UNDO;
        }
        $previous = $events[1] ?? null;
        if (
            CurationEvent::OP_UNDO === ($payload['op'] ?? null)
            && null !== $previous
            && ($payload['undoOf'] ?? null) === ($previous['when'] ?? null)
            && self::isBatchWrite((array) ($previous['payload'] ?? []), $batch)
        ) {
            return self::ALREADY_UNDONE;
        }
        foreach ($events as $event) {
            if (self::isBatchWrite((array) ($event['payload'] ?? []), $batch)) {
                return self::MODIFIED_LATER;
            }
        }
        return self::NOT_IN_BATCH;
    }

    /** @param array<string,mixed> $payload */
    private static function isBatchWrite(array $payload, string $batch): bool
    {
        return CurationEvent::OP_GOVERNANCE === ($payload['op'] ?? null) && $batch === ($payload['batch'] ?? null);
    }

    /**
     * @param list<int> $ids
     * @param callable(int):array $events
     * @param callable(int, array):array $undo
     * @param callable(string):void|null $log
     * @return array<string,mixed>
     */
    public function run(
        array $ids,
        string $batch,
        callable $events,
        callable $undo,
        ProgressReporter $progress,
        ?callable $log = null
    ): array {
        $total = count($ids);
        $tallies = [
            'undone' => 0,
            'modified_later' => 0,
            'not_in_batch' => 0,
            'already_undone' => 0,
            'failed' => [],
            'review' => [],
            'done' => 0,
            'total' => $total,
            'stopped' => false,
        ];
        $progress->report('undo', 0, $total);

        foreach ($ids as $id) {
            if ($progress->shouldStop()) {
                $tallies['stopped'] = true;
                break;
            }
            $tallies = $this->tally($tallies, $id, $batch, $events, $undo, $log);
            $tallies['done']++;
            if (0 === $tallies['done'] % self::PROGRESS_EVERY) {
                $progress->report('undo', $tallies['done'], $total);
            }
        }

        if (0 !== $tallies['done'] % self::PROGRESS_EVERY) {
            $progress->report('undo', $tallies['done'], $total);
        }
        return $tallies;
    }

    /** @param array<string,mixed> $tallies */
    private function tally(
        array $tallies,
        int $id,
        string $batch,
        callable $events,
        callable $undo,
        ?callable $log
    ): array {
        try {
            $history = $events($id);
            $verdict = self::classify($history, $batch);
            if (self::UNDO !== $verdict) {
                return $this->count($tallies, $id, $verdict);
            }
            $result = $undo($id, $history[0]);
        } catch (PermissionDeniedException $e) {
            return $this->fail($tallies, $id, 'denied');
        } catch (NotFoundException $e) {
            return $this->fail($tallies, $id, 'not_found');
        } catch (\Throwable $e) {
            if (null !== $log) {
                $log('OERManager governance batch undo item ' . $id . ': ' . $e->getMessage());
            }
            return $this->fail($tallies, $id, 'unexpected');
        }

        if ('stale' === ($result['error'] ?? null)) {
            return $this->count($tallies, $id, self::MODIFIED_LATER);
        }
        if (!empty($result['errors'])) {
            return $this->fail($tallies, $id, 'invalid');
        }
        if (true === ($result['updated'] ?? false)) {
            $tallies['undone']++;
            return $tallies;
        }
        // Another undo of the same REA (a per-item undo, or a previous run
        // of this same batch undo) got there first: not a failure, already
        // undone (F6).
        if (true === ($result['unchanged'] ?? false)) {
            return $this->count($tallies, $id, self::ALREADY_UNDONE);
        }
        return $this->fail($tallies, $id, 'unexpected');
    }

    /** @param array<string,mixed> $tallies */
    private function count(array $tallies, int $id, string $verdict): array
    {
        $tallies[$verdict]++;
        if (self::MODIFIED_LATER === $verdict) {
            $tallies['review'][] = ['id' => $id, 'code' => $verdict];
        }
        return $tallies;
    }

    /** @param array<string,mixed> $tallies */
    private function fail(array $tallies, int $id, string $code): array
    {
        $tallies['failed'][] = ['id' => $id, 'code' => $code];
        $tallies['review'][] = ['id' => $id, 'code' => $code];
        return $tallies;
    }
}
