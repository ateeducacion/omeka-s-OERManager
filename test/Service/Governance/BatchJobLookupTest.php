<?php

declare(strict_types=1);

namespace OERManager\Test\Service\Governance;

use OERManager\Service\Governance\BatchJobLookup;
use PHPUnit\Framework\TestCase;

final class BatchJobLookupTest extends TestCase
{
    private function entityManager(?object $job): object
    {
        return new class ($job) {
            public array $asked = [];

            public function __construct(private ?object $job)
            {
            }

            public function find(string $class, int $id): ?object
            {
                $this->asked[] = [$class, $id];
                return $this->job;
            }
        };
    }

    private function job(string $class, ?int $ownerId, string $status): object
    {
        $owner = null === $ownerId ? null : new class ($ownerId) {
            public function __construct(private int $id)
            {
            }

            public function getId(): int
            {
                return $this->id;
            }
        };
        return new class ($class, $owner, $status) {
            public function __construct(private string $class, private ?object $owner, private string $status)
            {
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
        };
    }

    public function testReadsClassOwnerAndStatusOfTheJobEntity(): void
    {
        $em = $this->entityManager($this->job('Some\Job', 7, 'in_progress'));

        $info = (new BatchJobLookup($em))->find(12);

        $this->assertSame(['class' => 'Some\Job', 'ownerId' => 7, 'status' => 'in_progress'], $info);
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
}
