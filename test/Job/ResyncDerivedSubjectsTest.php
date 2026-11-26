<?php

declare(strict_types=1);

namespace OERManager\Test\Job;

use OERManager\Job\ResyncDerivedSubjects;
use Laminas\ServiceManager\ServiceLocatorInterface;
use OERManager\Service\Curriculum\DerivedSubjectWriter;
use OERManager\Service\MasterViewQuery;
use Omeka\Api\Manager;
use Omeka\Api\Response;
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
            static function (int $id) use (&$updated): bool {
                $updated[] = $id;
                return true;
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
            static function (int $id): bool {
                if (1 === $id) {
                    throw new \RuntimeException('denied');
                }
                return true;
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
            static fn (int $id): bool => true,
            static fn (): bool => true
        );
        $this->assertSame(['examined' => 0, 'changed' => 0, 'failed' => 0], $result);
    }

    /** An update that wrote nothing is examined but not counted as changed. */
    public function testAnUpdateThatWritesNothingIsNotCounted(): void
    {
        $result = ResyncDerivedSubjects::run(
            static fn (): array => [1],
            static fn (int $id): object => (object) ['id' => $id],
            $this->writer([1 => []], [1 => [5]]),
            static fn (int $id): bool => false,
            static fn (): bool => false
        );
        $this->assertSame(['examined' => 1, 'changed' => 0, 'failed' => 0], $result);
    }

    /**
     * Final review of TASK-076: the job writes the derived values on the
     * managed entity and flushes, without the API, so a REA that fails its
     * template validation is still repaired and a refused update cannot
     * half-write. It flushes only when the writer changed something.
     */
    public function testWriteAppliesTheWriterAndFlushesOnlyOnChange(): void
    {
        $entityManager = new class {
            public int $flushes = 0;
            public function find($class, $id)
            {
                return 9 === $id ? null : (object) ['id' => $id, 'class' => $class];
            }
            public function flush()
            {
                $this->flushes++;
            }
        };
        $writer = $this->createMock(DerivedSubjectWriter::class);
        $writer->method('apply')->willReturnCallback(static fn (object $e): bool => 1 === $e->id);

        $this->assertTrue(ResyncDerivedSubjects::write($entityManager, $writer, 1));
        $this->assertSame(1, $entityManager->flushes);
        $this->assertFalse(ResyncDerivedSubjects::write($entityManager, $writer, 2));
        $this->assertFalse(ResyncDerivedSubjects::write($entityManager, $writer, 9));
        $this->assertSame(1, $entityManager->flushes);
    }

    /**
     * perform() wires run() to the real services: the REA ids from an id-only
     * search on the class, the managed entities, the direct write, and one
     * summary line in the log.
     *
     * @return array{0: object, 1: list<array>, 2: object}
     */
    private function perform(?int $classId): array
    {
        $query = $this->createMock(MasterViewQuery::class);
        $query->method('buildSearchParams')->willReturn(null === $classId ? [] : ['resource_class_id' => $classId]);
        $api = $this->createMock(Manager::class);
        $searches = [];
        $api->method('search')->willReturnCallback(static function ($resource, $params) use (&$searches) {
            $searches[] = [$resource, $params];
            return new Response(['1', '2', '3']);
        });
        $entityManager = new class {
            public int $flushes = 0;
            public function find($class, $id)
            {
                return 3 === $id ? null : (object) ['id' => $id];
            }
            public function flush()
            {
                $this->flushes++;
            }
        };
        $writer = $this->writer([1 => [10], 2 => []], [1 => [10], 2 => [20]]);
        $writer->method('apply')->willReturnCallback(static fn (object $e): bool => 2 === $e->id);
        $logger = new class {
            public array $lines = [];
            public function err($m)
            {
                $this->lines[] = ['err', $m];
            }
            public function warn($m)
            {
                $this->lines[] = ['warn', $m];
            }
            public function info($m)
            {
                $this->lines[] = ['info', $m];
            }
        };
        $services = $this->createMock(ServiceLocatorInterface::class);
        $services->method('get')->willReturnMap([
            ['Omeka\\ApiManager', $api],
            ['Omeka\\EntityManager', $entityManager],
            ['Omeka\\Logger', $logger],
            [MasterViewQuery::class, $query],
            [DerivedSubjectWriter::class, $writer],
        ]);
        $job = $this->getMockBuilder(ResyncDerivedSubjects::class)->disableOriginalConstructor()
            ->onlyMethods(['shouldStop', 'getServiceLocator'])->getMock();
        $job->method('shouldStop')->willReturn(false);
        $job->method('getServiceLocator')->willReturn($services);
        $job->perform();
        return [$entityManager, $searches, $logger];
    }

    public function testPerformWritesTheDifferingReaAndLogsASummary(): void
    {
        [$entityManager, $searches, $logger] = $this->perform(7);
        $this->assertSame([['items', ['resource_class_id' => 7, 'return_scalar' => 'id']]], $searches);
        $this->assertSame(1, $entityManager->flushes, 'only REA 2 differs');
        $this->assertSame(
            [['info', 'OERManager resync derived subjects: 2 REA examined, 1 changed, 0 failed.']],
            $logger->lines
        );
    }

    public function testPerformWithoutTheReaClassDoesNothing(): void
    {
        [$entityManager, $searches, $logger] = $this->perform(null);
        $this->assertSame([], $searches);
        $this->assertSame(0, $entityManager->flushes);
        $this->assertSame('warn', $logger->lines[0][0]);
    }
}
