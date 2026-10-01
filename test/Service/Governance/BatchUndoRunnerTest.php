<?php

declare(strict_types=1);

namespace OERManager\Test\Service\Governance;

use OERManager\Service\Ai\ProgressReporter;
use OERManager\Service\CurationEvent;
use OERManager\Service\Governance\BatchUndoRunner;
use Omeka\Api\Exception\NotFoundException;
use Omeka\Permissions\Exception\PermissionDeniedException;
use PHPUnit\Framework\TestCase;

final class BatchUndoRunnerTest extends TestCase
{
    private const B = 'batch-7';

    private function write(string $when, ?string $batch = self::B): array
    {
        $payload = ['v' => 2, 'op' => CurationEvent::OP_GOVERNANCE, 'undoOf' => null, 'terms' => []];
        if (null !== $batch) {
            $payload['batch'] = $batch;
        }
        return ['when' => $when, 'payload' => $payload];
    }

    private function undo(string $when, string $undoOf, ?string $batch = null): array
    {
        $payload = ['v' => 2, 'op' => CurationEvent::OP_UNDO, 'undoOf' => $undoOf, 'terms' => []];
        if (null !== $batch) {
            $payload['batch'] = $batch;
        }
        return ['when' => $when, 'payload' => $payload];
    }

    private function progress(int $stopAfter = PHP_INT_MAX): ProgressReporter
    {
        return new class ($stopAfter) implements ProgressReporter {
            public array $reports = [];
            private int $checks = 0;

            public function __construct(private int $stopAfter)
            {
            }

            public function report(string $step, int $done, int $total): void
            {
                $this->reports[] = [$done, $total];
            }

            public function shouldStop(): bool
            {
                return ++$this->checks > $this->stopAfter;
            }
        };
    }

    public function testClassifiesFromTheLastTwoEvents(): void
    {
        $this->assertSame(BatchUndoRunner::UNDO, BatchUndoRunner::classify([$this->write('t2'), $this->write('t1', null)], self::B));
        // Undone by hand (per-item undo, no tag) or by an earlier batch undo (tagged).
        $this->assertSame(BatchUndoRunner::ALREADY_UNDONE, BatchUndoRunner::classify([$this->undo('t3', 't2'), $this->write('t2')], self::B));
        $this->assertSame(BatchUndoRunner::ALREADY_UNDONE, BatchUndoRunner::classify([$this->undo('t3', 't2', 'batch-9'), $this->write('t2')], self::B));
        // Touched after the batch: a later write, or an undo of something else.
        $this->assertSame(BatchUndoRunner::MODIFIED_LATER, BatchUndoRunner::classify([$this->write('t3', null), $this->write('t2')], self::B));
        $this->assertSame(BatchUndoRunner::MODIFIED_LATER, BatchUndoRunner::classify([$this->undo('t4', 't3'), $this->write('t3', null), $this->write('t2')], self::B));
        // Another batch, or nothing at all.
        $this->assertSame(BatchUndoRunner::NOT_IN_BATCH, BatchUndoRunner::classify([$this->write('t2', 'batch-8')], self::B));
        $this->assertSame(BatchUndoRunner::NOT_IN_BATCH, BatchUndoRunner::classify([], self::B));
    }

    public function testTalliesEveryOutcomeAndUndoesOnlyTheBatchEvent(): void
    {
        $history = [
            1 => [$this->write('t2')],
            2 => [$this->write('t2')],
            3 => [$this->undo('t3', 't2'), $this->write('t2')],
            4 => [$this->write('t3', null), $this->write('t2')],
            5 => [],
            6 => new PermissionDeniedException('private'),
            7 => new NotFoundException('gone'),
            8 => [$this->write('t2')],
            9 => [$this->write('t2')],
            10 => [$this->write('t2')],
        ];
        $answers = [
            1 => ['updated' => true],
            2 => ['updated' => false, 'error' => 'stale', 'terms' => ['dcterms:creator']],
            8 => ['updated' => false, 'errors' => ['dcterms:license' => 'not-http-uri']],
            9 => new \LogicException('secret detail'),
            10 => ['updated' => false, 'unchanged' => true],
        ];
        $undone = [];
        $logged = [];

        $result = (new BatchUndoRunner())->run(
            array_keys($history),
            self::B,
            function (int $id) use ($history): array {
                if ($history[$id] instanceof \Throwable) {
                    throw $history[$id];
                }
                return $history[$id];
            },
            function (int $id, array $event) use ($answers, &$undone): array {
                $undone[] = [$id, $event['when']];
                if ($answers[$id] instanceof \Throwable) {
                    throw $answers[$id];
                }
                return $answers[$id];
            },
            $this->progress(),
            function (string $message) use (&$logged): void {
                $logged[] = $message;
            }
        );

        $this->assertSame([[1, 't2'], [2, 't2'], [8, 't2'], [9, 't2'], [10, 't2']], $undone);
        $this->assertSame(1, $result['undone']);
        $this->assertSame(2, $result['modified_later']);
        $this->assertSame(1, $result['already_undone']);
        $this->assertSame(1, $result['not_in_batch']);
        $this->assertSame([
            ['id' => 6, 'code' => 'denied'],
            ['id' => 7, 'code' => 'not_found'],
            ['id' => 8, 'code' => 'invalid'],
            ['id' => 9, 'code' => 'unexpected'],
            ['id' => 10, 'code' => 'unexpected'],
        ], $result['failed']);
        $this->assertSame([
            ['id' => 2, 'code' => 'modified_later'],
            ['id' => 4, 'code' => 'modified_later'],
            ['id' => 6, 'code' => 'denied'],
            ['id' => 7, 'code' => 'not_found'],
            ['id' => 8, 'code' => 'invalid'],
            ['id' => 9, 'code' => 'unexpected'],
            ['id' => 10, 'code' => 'unexpected'],
        ], $result['review']);
        $this->assertSame(10, $result['done']);
        $this->assertFalse($result['stopped']);
        $this->assertCount(1, $logged);
        $this->assertStringContainsString('item 9', $logged[0]);
    }

    public function testReportsEvery25AndStopsBetweenItems(): void
    {
        $progress = $this->progress();
        (new BatchUndoRunner())->run(
            range(1, 60),
            self::B,
            fn (int $id): array => [],
            fn (int $id, array $event): array => ['updated' => true],
            $progress
        );
        $this->assertSame([[0, 60], [25, 60], [50, 60], [60, 60]], $progress->reports);

        $calls = 0;
        $stopping = $this->progress(3);
        $result = (new BatchUndoRunner())->run(
            range(1, 10),
            self::B,
            fn (int $id): array => [$this->write('t2')],
            function (int $id, array $event) use (&$calls): array {
                $calls++;
                return ['updated' => true];
            },
            $stopping
        );
        $this->assertTrue($result['stopped']);
        $this->assertSame(3, $result['done']);
        $this->assertSame(3, $calls);
    }
}
