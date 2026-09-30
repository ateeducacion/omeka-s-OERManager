<?php

declare(strict_types=1);

namespace OERManager\Test\Service\Governance;

use OERManager\Service\Ai\ProgressReporter;
use OERManager\Service\Governance\GovernanceBatchRunner;
use Omeka\Api\Exception\NotFoundException;
use Omeka\Permissions\Exception\PermissionDeniedException;
use PHPUnit\Framework\TestCase;

final class GovernanceBatchRunnerTest extends TestCase
{
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

    public function testTalliesEveryOutcomeWithoutAborting(): void
    {
        $outcomes = [
            1 => ['updated' => true, 'skipped' => []],
            2 => ['updated' => false, 'unchanged' => true, 'skipped' => ['dcterms:license']],
            3 => ['updated' => false, 'unchanged' => true, 'skipped' => []],
            4 => new PermissionDeniedException('private'),
            5 => new NotFoundException('gone'),
            6 => new \LogicException('secret detail'),
            7 => ['updated' => false, 'errors' => ['dcterms:license' => 'not-http-uri']],
        ];
        $logged = [];

        $result = (new GovernanceBatchRunner())->run(
            array_keys($outcomes),
            function (int $id) use ($outcomes): array {
                if ($outcomes[$id] instanceof \Throwable) {
                    throw $outcomes[$id];
                }
                return $outcomes[$id];
            },
            $this->progress(),
            null,
            function (string $message) use (&$logged): void {
                $logged[] = $message;
            }
        );

        $this->assertSame(1, $result['written']);
        $this->assertSame(1, $result['skipped']);
        $this->assertSame(1, $result['unchanged']);
        $this->assertSame([
            ['id' => 4, 'code' => 'denied'],
            ['id' => 5, 'code' => 'not_found'],
            ['id' => 6, 'code' => 'unexpected'],
            ['id' => 7, 'code' => 'invalid'],
        ], $result['failed']);
        $this->assertSame(7, $result['done']);
        $this->assertFalse($result['stopped']);
        $this->assertCount(1, $logged);
        $this->assertStringContainsString('secret detail', $logged[0]);
    }

    public function testReportsProgressFlushesAndStopsBetweenItems(): void
    {
        $progress = $this->progress(60);
        $flushes = 0;
        $applied = 0;

        $result = (new GovernanceBatchRunner())->run(
            range(1, 100),
            function () use (&$applied): array {
                $applied++;
                return ['updated' => true, 'skipped' => []];
            },
            $progress,
            function () use (&$flushes): void {
                $flushes++;
            }
        );

        $this->assertTrue($result['stopped']);
        $this->assertSame(60, $applied);
        $this->assertSame(60, $result['done']);
        $this->assertSame(100, $result['total']);
        $this->assertSame(1, $flushes, 'flush after item 50');
        $this->assertSame([[0, 100], [25, 100], [50, 100], [60, 100]], $progress->reports);
    }
}
