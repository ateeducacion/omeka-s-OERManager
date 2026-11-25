<?php

declare(strict_types=1);

namespace OERManager\Test\Job;

use OERManager\Job\AiBatchProposeJob;
use OERManager\Service\Ai\ProgressReporter;
use OERManager\Service\Llm\LlmException;
use PHPUnit\Framework\TestCase;

final class AiBatchProposeJobTest extends TestCase
{
    private function progress(int $stopAt = PHP_INT_MAX): ProgressReporter
    {
        return new class ($stopAt) implements ProgressReporter {
            public array $reports = [];
            public function __construct(private int $stopAt)
            {
            }
            public function report(string $step, int $done, int $total): void
            {
                $this->reports[] = [$done, $total];
            }
            public function shouldStop(): bool
            {
                return end($this->reports)[0] >= $this->stopAt;
            }
        };
    }

    private function runBatch(array $ids, callable $propose, ?callable $eligible = null, ?callable $store = null, ?ProgressReporter $progress = null, array &$stored = [], array &$logged = []): array
    {
        return AiBatchProposeJob::run(
            $ids,
            $eligible ?? static fn (int $id): bool => true,
            $propose,
            $store ?? static function (int $id, array $payload) use (&$stored): bool {
                $stored[] = $id;
                return true;
            },
            $progress ?? $this->progress(),
            static function (string $message) use (&$logged): void {
                $logged[] = $message;
            }
        );
    }

    public function testEveryEligibleReaIsProposedAndStored(): void
    {
        $stored = [];
        $progress = $this->progress();
        $tallies = $this->runBatch([1, 2, 3], static fn (int $id): array => ['alignment' => []], static fn (int $id): bool => 2 !== $id, null, $progress, $stored);
        $this->assertSame(['done' => 3, 'total' => 3, 'proposed' => 2, 'skipped' => 1, 'failed' => [], 'stopped' => false, 'aborted' => false], $tallies);
        $this->assertSame([1, 3], $stored);
        $this->assertSame([0, 3], $progress->reports[0]);
        $this->assertSame([3, 3], end($progress->reports));
    }

    public function testAReaThatIsNoLongerEligibleIsSkippedWithoutCallingTheLlm(): void
    {
        $called = [];
        $this->runBatch([1, 2], static function (int $id) use (&$called): array {
            $called[] = $id;
            return [];
        }, static fn (int $id): bool => 1 === $id);
        $this->assertSame([1], $called);
    }

    public function testAFailureOnOneReaDoesNotStopTheBatch(): void
    {
        $logged = [];
        $stored = [];
        $tallies = $this->runBatch([1, 2, 3], static function (int $id): array {
            if (2 === $id) {
                throw new \RuntimeException('boom');
            }
            return [];
        }, null, null, null, $stored, $logged);
        $this->assertSame(2, $tallies['proposed']);
        $this->assertSame([['id' => 2, 'code' => 'unexpected']], $tallies['failed']);
        $this->assertSame(['OERManager ai batch item 2: boom'], $logged);
    }

    public function testAStoreThatWritesNothingIsAFailure(): void
    {
        $tallies = $this->runBatch([1], static fn (): array => [], null, static fn (): bool => false);
        $this->assertSame([['id' => 1, 'code' => 'not_stored']], $tallies['failed']);
    }

    public function testFiveLlmErrorsInARowAbort(): void
    {
        $calls = 0;
        $tallies = $this->runBatch(range(1, 8), static function () use (&$calls): array {
            $calls++;
            throw new LlmException('quota');
        });
        $this->assertTrue($tallies['aborted']);
        $this->assertSame(5, $calls);
        $this->assertSame(5, $tallies['done']);
        $this->assertSame('llm', $tallies['failed'][0]['code']);
    }

    public function testASuccessResetsTheStreak(): void
    {
        $tallies = $this->runBatch(range(1, 9), static function (int $id): array {
            if (5 !== $id) {
                throw new LlmException('slow');
            }
            return [];
        });
        $this->assertFalse($tallies['aborted']);
        $this->assertSame(9, $tallies['done']);
        $this->assertSame(1, $tallies['proposed']);
    }

    public function testStopIsHonouredBetweenReas(): void
    {
        $tallies = $this->runBatch([1, 2, 3, 4], static fn (): array => [], null, null, $this->progress(2));
        $this->assertTrue($tallies['stopped']);
        $this->assertSame(2, $tallies['done']);
    }
}
