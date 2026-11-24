<?php

declare(strict_types=1);

namespace OERManager\Test\Job;

use OERManager\Job\ResyncDerivedSubjects;
use OERManager\Service\Curriculum\DerivedSubjectWriter;
use PHPUnit\Framework\TestCase;

final class ResyncDerivedSubjectsTest extends TestCase
{
    /** Writer double: stored and derived ids per entity id. */
    private function writer(array $stored, array $derived): DerivedSubjectWriter
    {
        $writer = $this->createMock(DerivedSubjectWriter::class);
        $writer->method('storedIds')->willReturnCallback(static fn (object $e): array => $stored[$e->id]);
        $writer->method('derivedIds')->willReturnCallback(static fn (object $e): array => $derived[$e->id]);
        return $writer;
    }

    public function testOnlyDifferingReaAreUpdated(): void
    {
        $updated = [];
        $result = ResyncDerivedSubjects::run(
            static fn (): array => [1, 2, 3, 4],
            static fn (int $id): ?object => 4 === $id ? null : (object) ['id' => $id],
            $this->writer([1 => [10], 2 => [], 3 => [10, null]], [1 => [10], 2 => [20], 3 => [10]]),
            static function (int $id) use (&$updated): void {
                $updated[] = $id;
            },
            static fn (): bool => false
        );
        $this->assertSame([2, 3], $updated);
        $this->assertSame(['examined' => 3, 'changed' => 2, 'failed' => 0], $result);
    }

    public function testAFailedUpdateIsCountedReportedAndTheRestGoOn(): void
    {
        $errors = [];
        $result = ResyncDerivedSubjects::run(
            static fn (): array => [1, 2],
            static fn (int $id): object => (object) ['id' => $id],
            $this->writer([1 => [], 2 => []], [1 => [5], 2 => [6]]),
            static function (int $id): void {
                if (1 === $id) {
                    throw new \RuntimeException('denied');
                }
            },
            static fn (): bool => false,
            static function (int $id, \Throwable $e) use (&$errors): void {
                $errors[] = [$id, $e->getMessage()];
            }
        );
        $this->assertSame(['examined' => 2, 'changed' => 1, 'failed' => 1], $result);
        $this->assertSame([[1, 'denied']], $errors);
    }

    public function testAStopRequestEndsTheRun(): void
    {
        $result = ResyncDerivedSubjects::run(
            static fn (): array => [1, 2],
            static fn (int $id): object => (object) ['id' => $id],
            $this->writer([1 => [], 2 => []], [1 => [5], 2 => [6]]),
            static function (int $id): void {
            },
            static fn (): bool => true
        );
        $this->assertSame(['examined' => 0, 'changed' => 0, 'failed' => 0], $result);
    }
}
