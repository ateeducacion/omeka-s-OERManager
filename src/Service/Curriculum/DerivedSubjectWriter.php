<?php

declare(strict_types=1);

namespace OERManager\Service\Curriculum;

use OERManager\Service\MasterViewQuery;
use OERManager\Service\Workflow\StatusGuard;

/**
 * TASK-076: keeps `lrmi:educationalAlignment` of a REA equal to the stage
 * subjects derived from its `schema:about` (SubjectDerivation). Runs on the
 * hydrated entity (`api.hydrate.post`), so whatever the save path (native
 * form, REST API, batch edit with append/remove/clear, re-cataloguer, undo,
 * import), the derived set follows the final `schema:about`, and the change
 * is persisted by the same flush (`Resource::$values` is `orphanRemoval` and
 * `cascade persist`). Not a curation dimension: no annotations, no curation
 * event (ADR-0002, ADR-0015).
 *
 * Entities are duck-typed so the host tests can use doubles. Not final so
 * the resync job's test can stand it in.
 */
class DerivedSubjectWriter
{
    public const TERM = 'lrmi:educationalAlignment';
    public const SOURCE_TERM = 'schema:about';

    /**
     * @param \Closure():?object $alignmentProperty the managed `lrmi:educationalAlignment` Property, or null
     * @param \Closure():object $newValue a new `Omeka\Entity\Value`
     */
    public function __construct(
        private readonly \Closure $alignmentProperty,
        private readonly \Closure $newValue
    ) {
    }

    public function isRea(object $entity): bool
    {
        return MasterViewQuery::LEARNING_RESOURCE_CLASS_TERM === StatusGuard::term($entity->getResourceClass());
    }

    /** @return list<int> */
    public function derivedIds(object $entity): array
    {
        return SubjectDerivation::ids($this->derive($entity)[0]);
    }

    /** @return list<?int> one entry per stored value; null when it is not an item link */
    public function storedIds(object $entity): array
    {
        $ids = [];
        foreach ($this->stored($entity) as $value) {
            $target = $value->getValueResource();
            $ids[] = null === $target ? null : (int) $target->getId();
        }
        return $ids;
    }

    public function apply(object $entity): bool
    {
        if (!$this->isRea($entity)) {
            return false;
        }
        [$derived, $targets] = $this->derive($entity);
        if (SubjectDerivation::matches($this->storedIds($entity), SubjectDerivation::ids($derived))) {
            return false;
        }
        $property = ($this->alignmentProperty)();
        if (null === $property) {
            return false;
        }
        $values = $entity->getValues();
        foreach ($this->stored($entity) as $value) {
            $values->removeElement($value);
        }
        foreach ($derived as $subject) {
            $value = ($this->newValue)();
            $value->setResource($entity);
            $value->setProperty($property);
            $value->setType('resource:item');
            $value->setValueResource($targets[$subject['id']]);
            $value->setIsPublic(true);
            $values->add($value);
        }
        return true;
    }

    /** @return list<object> the entity's stored values of TERM */
    private function stored(object $entity): array
    {
        $stored = [];
        foreach ($entity->getValues() as $value) {
            if (self::TERM === StatusGuard::term($value->getProperty())) {
                $stored[] = $value;
            }
        }
        return $stored;
    }

    /**
     * @return array{0: list<array{id:int, title:string}>, 1: array<int, object>}
     *         derived subjects, and their entities by id
     */
    private function derive(object $entity): array
    {
        $about = [];
        $targets = [];
        foreach ($entity->getValues() as $value) {
            if (self::SOURCE_TERM !== StatusGuard::term($value->getProperty())) {
                continue;
            }
            $target = $value->getValueResource();
            if (null === $target || !method_exists($target, 'getResourceClass')) {
                $about[] = ['class' => null, 'subjects' => []];
                continue;
            }
            $subjects = [];
            foreach ($target->getValues() as $edge) {
                if (CurModel::EDGE_MATERIA !== StatusGuard::term($edge->getProperty())) {
                    continue;
                }
                // The derived value is public: only follow what is already
                // public, the edge and the subject item (security review).
                $subject = $edge->getValueResource();
                if (
                    !$edge->isPublic()
                    || null === $subject
                    || !method_exists($subject, 'getResourceClass')
                    || !$subject->isPublic()
                ) {
                    continue;
                }
                $targets[(int) $subject->getId()] = $subject;
                $subjects[] = [
                    'id' => (int) $subject->getId(),
                    'class' => StatusGuard::term($subject->getResourceClass()),
                    'title' => (string) $subject->getTitle(),
                ];
            }
            $about[] = ['class' => StatusGuard::term($target->getResourceClass()), 'subjects' => $subjects];
        }
        return [SubjectDerivation::derive($about), $targets];
    }
}
