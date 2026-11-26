<?php

declare(strict_types=1);

namespace OERManager\Test\Service\Ai;

use OERManager\Service\Ai\StoredProposal;
use OERManager\Service\Ai\StoredProposals;
use PHPUnit\Framework\TestCase;

final class StoredProposalsTest extends TestCase
{
    private function entityManager(?object $item): object
    {
        return new class ($item) {
            public int $flushes = 0;
            public array $asked = [];
            public function __construct(private ?object $item)
            {
            }
            public function find(string $class, int $id): ?object
            {
                $this->asked[] = [$class, $id];
                return $this->item;
            }
            public function flush(): void
            {
                $this->flushes++;
            }
        };
    }

    private function store(object $em): StoredProposals
    {
        return new StoredProposals(
            $em,
            StoredProposalWriterTest::writer(),
            static fn (): \DateTimeImmutable => new \DateTimeImmutable('2026-11-25T10:31:07+00:00')
        );
    }

    public function testStoreWritesTheProposalWithTheItemModificationDateAndFlushes(): void
    {
        $item = StoredProposalWriterTest::entity([], new \DateTimeImmutable('2026-11-20T08:00:00+00:00'));
        $em = $this->entityManager($item);
        $payload = ['alignment' => ['lrmi:teaches' => [['id' => 9, 'title' => 'S']]], 'justifications' => []];

        $this->assertTrue($this->store($em)->store(7, $payload, 'claude-test'));

        $this->assertSame([['Omeka\Entity\Item', 7]], $em->asked);
        $this->assertSame(1, $em->flushes);
        $stored = StoredProposal::decode($item->getValues()->items[0]->getValue());
        $this->assertSame('2026-11-20T08:00:00+00:00', $stored['item_modified']);
        $this->assertSame('2026-11-25T10:31:07+00:00', $stored['created']);
        $this->assertSame('claude-test', $stored['model']);
        $this->assertSame(['lrmi:teaches' => [9]], $stored['alignment']);
    }

    public function testAMissingItemIsNeitherWrittenNorFlushed(): void
    {
        $em = $this->entityManager(null);
        $this->assertFalse($this->store($em)->store(7, ['alignment' => []], 'm'));
        $this->assertFalse($this->store($em)->remove(7));
        $this->assertSame(0, $em->flushes);
    }

    public function testRemoveFlushesOnlyWhenSomethingWasRemoved(): void
    {
        $item = StoredProposalWriterTest::entity([]);
        $em = $this->entityManager($item);
        $this->assertFalse($this->store($em)->remove(7));
        $this->assertSame(0, $em->flushes);

        $this->store($em)->store(7, ['alignment' => []], 'm');
        $this->assertTrue($this->store($em)->remove(7));
        $this->assertSame(2, $em->flushes);
        $this->assertSame([], $item->getValues()->items);
    }

    public function testMayUpdateAsksTheAclAboutTheEntity(): void
    {
        $item = StoredProposalWriterTest::entity([]);
        $acl = new class {
            public array $asked = [];
            public bool $allow = true;
            public function userIsAllowed($resource, $privilege): bool
            {
                $this->asked[] = [$resource, $privilege];
                return $this->allow;
            }
        };
        $this->assertTrue($this->store($this->entityManager($item))->mayUpdate(7, $acl));
        $this->assertSame([[$item, 'update']], $acl->asked);
        $acl->allow = false;
        $this->assertFalse($this->store($this->entityManager($item))->mayUpdate(7, $acl));
        $this->assertFalse($this->store($this->entityManager(null))->mayUpdate(7, $acl));
    }
}
