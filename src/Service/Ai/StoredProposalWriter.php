<?php

declare(strict_types=1);

namespace OERManager\Service\Ai;

use OERManager\Service\Workflow\StatusGuard;

/**
 * TASK-011: reads, replaces and removes the stored AI proposal on an `Item`
 * entity, touching only `curation:data` values that carry the marker. Works
 * on the entity, not the API: imported REA usually fail the template's
 * required fields, and a refused API update would lose the proposal. The
 * caller flushes. Entities are duck-typed so the host tests use doubles.
 */
class StoredProposalWriter
{
    /**
     * @param \Closure():?object $property the managed `curation:data` Property, or null when not installed
     * @param \Closure():object $newValue a new `Omeka\Entity\Value`
     */
    public function __construct(
        private readonly \Closure $property,
        private readonly \Closure $newValue
    ) {
    }

    /** @return array<string,mixed>|null the first readable proposal */
    public function read(object $entity): ?array
    {
        return StoredProposal::firstOf(array_map(
            static fn (object $value): string => (string) $value->getValue(),
            $this->marked($entity)
        ));
    }

    /** Any marker value, readable or not. */
    public function has(object $entity): bool
    {
        return [] !== $this->marked($entity);
    }

    /** @param array<string,mixed> $proposal */
    public function replace(object $entity, array $proposal): bool
    {
        $property = ($this->property)();
        if (null === $property) {
            return false;
        }
        $this->remove($entity);
        $value = ($this->newValue)();
        $value->setResource($entity);
        $value->setProperty($property);
        $value->setType('literal');
        $value->setValue(StoredProposal::encode($proposal));
        // A machine record, never shown on the public page (as ADR-0015 §2).
        $value->setIsPublic(false);
        $entity->getValues()->add($value);
        return true;
    }

    public function remove(object $entity): bool
    {
        $marked = $this->marked($entity);
        foreach ($marked as $value) {
            $entity->getValues()->removeElement($value);
        }
        return [] !== $marked;
    }

    /** @return list<object> */
    private function marked(object $entity): array
    {
        $marked = [];
        foreach ($entity->getValues() as $value) {
            if (
                StoredProposal::TERM === StatusGuard::term($value->getProperty())
                && StoredProposal::isProposal((string) $value->getValue())
            ) {
                $marked[] = $value;
            }
        }
        return $marked;
    }
}
