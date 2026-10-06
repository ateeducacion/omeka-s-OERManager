<?php

declare(strict_types=1);

namespace OERManager\Test\Service\Governance;

use OERManager\Service\Governance\BatchJobLookup;
use PHPUnit\Framework\TestCase;

final class BatchJobLookupTest extends TestCase
{
    private function entityManager(?object $job, array $jobs = []): object
    {
        return new class ($job, $jobs) {
            public array $asked = [];
            public array $queries = [];

            public function __construct(private ?object $job, private array $jobs)
            {
            }

            public function find(string $class, int $id): ?object
            {
                $this->asked[] = [$class, $id];
                return $this->job;
            }

            public function getRepository(string $class): object
            {
                $outer = $this;
                return new class ($outer) {
                    public function __construct(private object $outer)
                    {
                    }

                    public function findBy(array $criteria, ?array $order = null, ?int $limit = null): array
                    {
                        return $this->outer->query($criteria, $order, $limit);
                    }
                };
            }

            /** Filters the fake job table the way Doctrine would for these criteria. */
            public function query(array $criteria, ?array $order, ?int $limit): array
            {
                $this->queries[] = [$criteria, $order, $limit];
                $rows = array_filter($this->jobs, static function (object $job) use ($criteria): bool {
                    foreach ($criteria as $field => $wanted) {
                        $actual = match ($field) {
                            'class' => $job->getClass(),
                            'status' => $job->getStatus(),
                            'owner' => $job->getOwner()?->getId(),
                        };
                        if (is_array($wanted) ? !in_array($actual, $wanted, true) : $actual !== $wanted) {
                            return false;
                        }
                    }
                    return true;
                });
                usort($rows, static fn (object $a, object $b): int => $b->getId() <=> $a->getId());
                return array_slice($rows, 0, $limit);
            }
        };
    }

    private function job(
        string $class,
        ?int $ownerId,
        string $status,
        array $args = [],
        int $id = 1,
        ?string $started = null
    ): object {
        $owner = null === $ownerId ? null : new class ($ownerId) {
            public function __construct(private int $id)
            {
            }

            public function getId(): int
            {
                return $this->id;
            }

            public function getName(): string
            {
                return 'User ' . $this->id;
            }
        };
        return new class ($class, $owner, $status, $args, $id, $started) {
            public function __construct(
                private string $class,
                private ?object $owner,
                private string $status,
                private array $args,
                private int $id,
                private ?string $started
            ) {
            }

            public function getId(): int
            {
                return $this->id;
            }

            public function getClass(): string
            {
                return $this->class;
            }

            public function getOwner(): ?object
            {
                return $this->owner;
            }

            public function getStatus(): string
            {
                return $this->status;
            }

            public function getArgs(): ?array
            {
                return $this->args;
            }

            public function getStarted(): ?\DateTimeInterface
            {
                return null === $this->started ? null : new \DateTimeImmutable($this->started);
            }

            public function getEnded(): ?\DateTimeInterface
            {
                return null;
            }
        };
    }

    private const BATCH = \OERManager\Job\GovernanceBatchJob::class;
    private const UNDO = \OERManager\Job\GovernanceBatchUndoJob::class;

    public function testReadsClassOwnerAndStatusOfTheJobEntity(): void
    {
        $em = $this->entityManager($this->job('Some\Job', 7, 'in_progress', []));

        $info = (new BatchJobLookup($em))->find(12);

        $this->assertSame(
            ['class' => 'Some\Job', 'ownerId' => 7, 'status' => 'in_progress', 'args' => [], 'started' => null],
            $info
        );
        $this->assertSame([['Omeka\Entity\Job', 12]], $em->asked);
    }

    public function testAJobWithoutOwnerHasANullOwnerId(): void
    {
        $info = (new BatchJobLookup($this->entityManager($this->job('Some\Job', null, 'completed'))))->find(3);

        $this->assertNull($info['ownerId']);
    }

    public function testAMissingJobIsNull(): void
    {
        $this->assertNull((new BatchJobLookup($this->entityManager(null)))->find(3));
    }

    public function testFindReturnsTheArgs(): void
    {
        $em = $this->entityManager($this->job(self::BATCH, 7, 'completed', ['ids' => [1, 2]]));

        $this->assertSame(['ids' => [1, 2]], (new BatchJobLookup($em))->find(4)['args']);
    }

    public function testFindReturnsStarted(): void
    {
        $em = $this->entityManager($this->job(self::BATCH, 7, 'in_progress', [], 1, '2026-09-30T09:00:00+00:00'));
        $this->assertSame('2026-09-30T09:00:00+00:00', (new BatchJobLookup($em))->find(4)['started']);

        $em = $this->entityManager($this->job(self::BATCH, 7, 'in_progress', []));
        $this->assertNull((new BatchJobLookup($em))->find(4)['started']);
    }

    public function testHasDiedIsTrueOnlyForAnUnfinishedJobStaleForOverAnHour(): void
    {
        $now = new \DateTimeImmutable('2026-09-30T12:00:00+00:00');
        $lookup = new BatchJobLookup($this->entityManager(null), static fn (): \DateTimeImmutable => $now);

        $this->assertFalse($lookup->hasDied('completed', '2026-09-30T09:00:00+00:00'));
        $this->assertFalse($lookup->hasDied('in_progress', '2026-09-30T11:30:00+00:00'));
        $this->assertTrue($lookup->hasDied('in_progress', '2026-09-30T09:00:00+00:00'));
        $this->assertTrue($lookup->hasDied('starting', new \DateTimeImmutable('2026-09-30T09:00:00+00:00')));
        // The test fakes may leave `started` null; Job::prePersist always sets it in production.
        $this->assertFalse($lookup->hasDied('in_progress', null));
    }

    public function testRecentListsFinishedBatchesOfTheOwnerOrOfEveryone(): void
    {
        $args = ['ids' => [1, 2, 3], 'raw' => ['dcterms:license' => ['x'], 'dcterms:creator' => ['Ana']], 'mode' => 'replace'];
        $jobs = [
            $this->job(self::BATCH, 3, 'completed', $args, 10, '2026-09-29T17:42:00+00:00'),
            $this->job(self::BATCH, 3, 'in_progress', $args, 11),
            $this->job(self::BATCH, 9, 'stopped', $args, 12),
            $this->job('Omeka\Job\BatchUpdate', 3, 'completed', [], 13),
            $this->job(self::BATCH, 3, 'error', $args, 14),
        ];
        $lookup = new BatchJobLookup($this->entityManager(null, $jobs));

        $mine = $lookup->recent(3);
        $this->assertSame([14, 10], array_column($mine, 'jobId'));
        $this->assertSame([
            'jobId' => 10,
            'ownerId' => 3,
            'ownerName' => 'User 3',
            'started' => '2026-09-29T17:42:00+00:00',
            'ended' => null,
            'terms' => ['dcterms:license', 'dcterms:creator'],
            'mode' => 'replace',
            'planned' => 3,
            'status' => 'completed',
            'undo' => ['state' => 'none', 'jobId' => null],
        ], $mine[1]);

        $this->assertSame([14, 12, 10], array_column($lookup->recent(null), 'jobId'));
        $this->assertSame([14], array_column($lookup->recent(null, 1), 'jobId'));
    }

    public function testRecentListsADeadBatchButNotARecentlyStartedOne(): void
    {
        $now = new \DateTimeImmutable('2026-09-30T12:00:00+00:00');
        $args = ['ids' => [1, 2], 'raw' => [], 'mode' => 'fill'];
        $jobs = [
            // Killed over an hour ago: Omeka leaves it in_progress forever (F1).
            $this->job(self::BATCH, 3, 'in_progress', $args, 20, '2026-09-30T09:00:00+00:00'),
            // Started 10 minutes ago: still plausibly running, not dead.
            $this->job(self::BATCH, 3, 'in_progress', $args, 21, '2026-09-30T11:50:00+00:00'),
        ];
        $lookup = new BatchJobLookup($this->entityManager(null, $jobs), static fn (): \DateTimeImmutable => $now);

        $rows = $lookup->recent(3);

        $this->assertSame([20], array_column($rows, 'jobId'));
        $this->assertSame('died', $rows[0]['status']);
    }

    public function testUndoStateComesFromTheLatestUndoJobOfEachBatch(): void
    {
        $now = new \DateTimeImmutable('2026-09-30T12:00:00+00:00');
        $jobs = [
            $this->job(self::BATCH, 3, 'completed', ['ids' => [1]], 10),
            $this->job(self::UNDO, 3, 'stopped', ['batchJobId' => 10], 20),
            $this->job(self::UNDO, 3, 'completed', ['batchJobId' => 10], 21),
            $this->job(self::UNDO, 3, 'error', ['batchJobId' => 11], 22),
            $this->job(self::UNDO, 3, 'in_progress', ['batchJobId' => 12], 23, '2026-09-30T11:30:00+00:00'),
            // Killed long ago: Omeka leaves it in_progress forever (Review Focus 2).
            $this->job(self::UNDO, 3, 'in_progress', ['batchJobId' => 13], 24, '2026-09-30T09:00:00+00:00'),
            $this->job(self::UNDO, 3, 'starting', ['batchJobId' => 14], 25),
            $this->job(self::UNDO, 3, 'completed', ['batchJobId' => 'x'], 26),
        ];
        $lookup = new BatchJobLookup($this->entityManager(null, $jobs), static fn (): \DateTimeImmutable => $now);

        $this->assertSame(['state' => 'done', 'jobId' => 21], $lookup->undoState(10));
        $this->assertSame(['state' => 'partial', 'jobId' => 22], $lookup->undoState(11));
        $this->assertSame(['state' => 'running', 'jobId' => 23], $lookup->undoState(12));
        $this->assertSame(['state' => 'partial', 'jobId' => 24], $lookup->undoState(13));
        $this->assertSame(['state' => 'running', 'jobId' => 25], $lookup->undoState(14));
        $this->assertSame(['state' => 'none', 'jobId' => null], $lookup->undoState(99));
        $this->assertSame(['state' => 'done', 'jobId' => 21], $lookup->recent(3)[0]['undo']);
    }
}
