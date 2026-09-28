<?php

declare(strict_types=1);

namespace OERManager\Service\Governance;

use OERManager\Service\Ai\ProgressReporter;
use Omeka\Api\Exception\NotFoundException;
use Omeka\Permissions\Exception\PermissionDeniedException;

/**
 * The batch loop (TASK-028 slice 4), pure: the per-item write, the progress
 * channel, the memory flush and the logger are injected, so the job that wires
 * them stays thin. One item's failure never aborts the batch; cancel is honoured
 * between items, never inside one.
 */
final class GovernanceBatchRunner
{
    public const PROGRESS_EVERY = 25;
    public const FLUSH_EVERY = 50;

    /**
     * @param list<int> $ids
     * @param callable(int):array $apply
     * @param callable():void|null $flush
     * @param callable(string):void|null $log
     * @return array<string,mixed>
     */
    public function run(
        array $ids,
        callable $apply,
        ProgressReporter $progress,
        ?callable $flush = null,
        ?callable $log = null
    ): array {
        $total = count($ids);
        $tallies = [
            'written' => 0,
            'skipped' => 0,
            'unchanged' => 0,
            'failed' => [],
            'done' => 0,
            'total' => $total,
            'stopped' => false,
        ];
        $progress->report('batch', 0, $total);

        foreach ($ids as $id) {
            if ($progress->shouldStop()) {
                $tallies['stopped'] = true;
                break;
            }
            $tallies = $this->tally($tallies, $id, $apply, $log);
            $tallies['done']++;
            if (null !== $flush && 0 === $tallies['done'] % self::FLUSH_EVERY) {
                $flush();
            }
            if (0 === $tallies['done'] % self::PROGRESS_EVERY) {
                $progress->report('batch', $tallies['done'], $total);
            }
        }

        if (0 !== $tallies['done'] % self::PROGRESS_EVERY) {
            $progress->report('batch', $tallies['done'], $total);
        }
        return $tallies;
    }

    /** @param array<string,mixed> $tallies */
    private function tally(array $tallies, int $id, callable $apply, ?callable $log): array
    {
        try {
            $result = $apply($id);
        } catch (PermissionDeniedException $e) {
            $tallies['failed'][] = ['id' => $id, 'code' => 'denied'];
            return $tallies;
        } catch (NotFoundException $e) {
            $tallies['failed'][] = ['id' => $id, 'code' => 'not_found'];
            return $tallies;
        } catch (\Throwable $e) {
            if (null !== $log) {
                $log('OERManager governance batch item ' . $id . ': ' . $e->getMessage());
            }
            $tallies['failed'][] = ['id' => $id, 'code' => 'unexpected'];
            return $tallies;
        }

        if (!empty($result['errors'])) {
            $tallies['failed'][] = ['id' => $id, 'code' => 'invalid'];
        } elseif (true === ($result['updated'] ?? false)) {
            $tallies['written']++;
        } elseif ([] !== ($result['skipped'] ?? [])) {
            $tallies['skipped']++;
        } else {
            $tallies['unchanged']++;
        }
        return $tallies;
    }
}
