<?php

declare(strict_types=1);

namespace OERManager\Service\Ai;

/**
 * TASK-011: the stored AI proposal by item id. Finds the `Item` entity, lets
 * StoredProposalWriter change it and flushes only when something changed.
 * A direct entity write does not move `o:modified` and skips template
 * validation (the TASK-076 resync pattern). Not final: the controllers' tests
 * stand it in.
 */
class StoredProposals
{
    public const ITEM = 'Omeka\Entity\Item';

    private \Closure $now;

    /** @param object $entityManager Doctrine EntityManager (duck-typed: find(), flush()) */
    public function __construct(
        private object $entityManager,
        private StoredProposalWriter $writer,
        ?\Closure $now = null
    ) {
        $this->now = $now ?? static fn (): \DateTimeImmutable => new \DateTimeImmutable();
    }

    /** @param array<string,mixed> $payload ProposeRunner::run() */
    public function store(int $itemId, array $payload, string $model): bool
    {
        $item = $this->entityManager->find(self::ITEM, $itemId);
        if (null === $item) {
            return false;
        }
        $proposal = StoredProposal::fromPayload($payload, $model, ($this->now)(), $item->getModified());
        if (!$this->writer->replace($item, $proposal)) {
            return false;
        }
        $this->entityManager->flush();
        return true;
    }

    public function remove(int $itemId): bool
    {
        $item = $this->entityManager->find(self::ITEM, $itemId);
        if (null === $item || !$this->writer->remove($item)) {
            return false;
        }
        $this->entityManager->flush();
        return true;
    }

    /** The native ACL on the entity: in a Job, the identity is the Job's owner. */
    public function mayUpdate(int $itemId, object $acl): bool
    {
        $item = $this->entityManager->find(self::ITEM, $itemId);
        return null !== $item && (bool) $acl->userIsAllowed($item, 'update');
    }
}
