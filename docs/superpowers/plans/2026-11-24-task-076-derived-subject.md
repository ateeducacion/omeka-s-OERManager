# TASK-076 Derived Stage Subject Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Every REA carries, in `lrmi:educationalAlignment`, the stage subjects (`cur:Materia`) derived from its `schema:about` (`cur:MateriaCurso`), so the public search can facet by subject.

**Architecture:** A pure derivation (`SubjectDerivation`) and a Doctrine-side writer (`DerivedSubjectWriter`) run from an `api.hydrate.post` listener on `ItemAdapter`, rewriting the derived values on the entity before the same flush. A resync job, launched by a global administrator from the configuration page, back-fills and repairs REA after a curriculum release.

**Tech Stack:** Omeka S 4.2 module (Laminas MVC, Doctrine ORM), PHP 8.4, PHPUnit 11 on the host with stubs in `test/stubs/`, container harnesses in `test/container/`.

**Spec:** `docs/superpowers/specs/2026-11-24-task-076-subject-facet-design.md`

## Global Constraints

- Extend Omeka core, never patch it; no custom Doctrine tables (NFR-002).
- OERManager writes only `dcterms`, `lrmi`, `schema` (ADR-0022 §3); derived values go to `lrmi:educationalAlignment` only.
- The derived property is not a curation dimension: no value annotations (ADR-0002), no curation event (ADR-0015), not in `IntegrityPolicy::ALIGNMENT_TERMS`.
- Derived values: `resource:item`, public, no language, no annotation, in title order (then id).
- Only items of class `lrmi:LearningResource` are touched.
- Docs, code comments, PRs and branches in English; UI copy in Spanish.
- Do not change the Makefile. Run `make lint`, `make test`, `make test-js` on the host. Coverage gate ≥ 90 % in CI.
- Container harnesses write only to a development or disposable catalogue, never a live one (AGENTS.md).
- Commits: no `Co-Authored-By` trailer; name the model in a plain line of the body.

## Review Focus

1. A native batch edit that appends a `MateriaCurso` to many REA must leave each REA with the derived subjects of its final `schema:about`, not of the request (container harness, Task 5, checks 4–5).
2. A save where nothing changes must not touch the values (no removal and re-add, no new value ids); pinned in Task 2 (`testApplyLeavesMatchingValuesAlone`).
3. A `schema:about` target that is not a classed item (a deleted target, an item without class, a `MateriaCurso` without `cur:materia`) must not break the save; pinned in Task 2 (`testTargetsWithoutClassOrResourceContributeNothing`).
4. The resync job, run twice, must change nothing the second time and must not move `o:modified` of up-to-date REA; pinned in Task 3 (`testOnlyDifferingReaAreUpdated`) and the harness (Task 5, check 7).
5. The resync is a global-administrator action: a curator must not reach it, and a GET or a stale token must not dispatch the job; pinned in Task 4 (`ReaAclTest`, `testResyncSubjectsNeedsPostAndCsrf`).

---

## File structure

| File | Responsibility |
| --- | --- |
| Create `src/Service/Curriculum/SubjectDerivation.php` | Pure: from projected `schema:about` targets, the ordered distinct `cur:Materia`; `matches()` |
| Create `src/Service/Curriculum/DerivedSubjectWriter.php` | Reads entity values; applies the derived set to the entity's value collection |
| Modify `Module.php` | Listener `deriveSubject()` on `api.hydrate.post` |
| Modify `config/module.config.php` | Service factory for the writer |
| Create `src/Job/ResyncDerivedSubjects.php` | Back-fill / resync job |
| Modify `src/Controller/Admin/IndexController.php` | `resyncSubjectsAction()`; `configAction()` passes the CSRF hash |
| Modify `src/Permissions/ReaAcl.php` | `resync-subjects` in `ADMIN_ONLY` |
| Modify `view/oer-manager/admin/index/config.phtml` | The separate «Recalcular» form |
| Create `test/Service/Curriculum/SubjectDerivationTest.php`, `test/Service/Curriculum/DerivedSubjectWriterTest.php`, `test/Job/ResyncDerivedSubjectsTest.php` | Host tests |
| Modify `test/ModuleRuntimeTest.php`, `test/Permissions/ReaAclTest.php`, `test/Controller/IndexControllerTest.php` | Listener count, ACL, action |
| Create `test/container/subject-derivation-check.php` | Real save paths in the container |
| Modify `docs/decisions/0022-curriculo-modelo-cur.md`, `docs/backlog.md`, `docs/traceability.md`, `docs/project-memory.md` | Addendum and closure |

---

### Task 1: `SubjectDerivation` (pure)

**Files:**
- Create: `src/Service/Curriculum/SubjectDerivation.php`
- Test: `test/Service/Curriculum/SubjectDerivationTest.php`

**Interfaces:**
- Produces (all `public static`):
  - `SubjectDerivation::derive(array $about): list<array{id:int, title:string}>`, where `$about` is `list<array{class:?string, subjects:list<array{id:int, class:?string, title:string}>}>`: one entry per `schema:about` value; `class` is the target's class term or null; `subjects` are the target's `cur:materia` targets.
  - `SubjectDerivation::ids(list<array{id:int, title:string}> $derived): list<int>`
  - `SubjectDerivation::matches(list<?int> $stored, list<int> $derived): bool`

- [ ] **Step 1: Write the failing test**

```php
<?php

declare(strict_types=1);

namespace OERManager\Test\Service\Curriculum;

use OERManager\Service\Curriculum\SubjectDerivation;
use PHPUnit\Framework\TestCase;

final class SubjectDerivationTest extends TestCase
{
    private static function about(?string $class, array $subjects): array
    {
        return ['class' => $class, 'subjects' => $subjects];
    }

    private static function subject(int $id, string $title, ?string $class = 'cur:Materia'): array
    {
        return ['id' => $id, 'class' => $class, 'title' => $title];
    }

    public function testTwoYearsOfOneSubjectGiveOneValue(): void
    {
        $maths = self::subject(10, 'Matemáticas (ESO)');
        $derived = SubjectDerivation::derive([
            self::about('cur:MateriaCurso', [$maths]),
            self::about('cur:MateriaCurso', [$maths]),
        ]);
        $this->assertSame([['id' => 10, 'title' => 'Matemáticas (ESO)']], $derived);
    }

    public function testSubjectsAreOrderedByTitleThenId(): void
    {
        $derived = SubjectDerivation::derive([
            self::about('cur:MateriaCurso', [self::subject(30, 'Matemáticas (ESO)')]),
            self::about('cur:MateriaCurso', [self::subject(20, 'Biología (Bachillerato)')]),
            self::about('cur:MateriaCurso', [self::subject(5, 'Matemáticas (ESO)')]),
        ]);
        $this->assertSame([20, 5, 30], SubjectDerivation::ids($derived));
    }

    public function testOnlyMateriaTargetsOfMateriaCursoCount(): void
    {
        $this->assertSame([], SubjectDerivation::derive([
            // A literal or URI schema:about value: no target class.
            self::about(null, []),
            // Another class of target.
            self::about('cur:Curso', [self::subject(1, 'X')]),
            // A MateriaCurso without cur:materia.
            self::about('cur:MateriaCurso', []),
            // cur:materia to something that is not a Materia.
            self::about('cur:MateriaCurso', [self::subject(2, 'Y', 'cur:Etapa')]),
            self::about('cur:MateriaCurso', [self::subject(3, 'Z', null)]),
        ]));
        $this->assertSame([], SubjectDerivation::derive([]));
    }

    public function testMatchesNeedsTheSameIdsInTheSameOrder(): void
    {
        $this->assertTrue(SubjectDerivation::matches([], []));
        $this->assertTrue(SubjectDerivation::matches([20, 5], [20, 5]));
        $this->assertFalse(SubjectDerivation::matches([5, 20], [20, 5]));
        $this->assertFalse(SubjectDerivation::matches([20], [20, 5]));
        $this->assertFalse(SubjectDerivation::matches([20, 5, 7], [20, 5]));
        // A stored value that is not an item link never matches.
        $this->assertFalse(SubjectDerivation::matches([20, null], [20]));
    }
}
```

- [ ] **Step 2: Run the test to verify it fails**

Run: `vendor/bin/phpunit -c test/phpunit.xml test/Service/Curriculum/SubjectDerivationTest.php`
Expected: FAIL, `Class "OERManager\Service\Curriculum\SubjectDerivation" not found`.

- [ ] **Step 3: Write the implementation**

```php
<?php

declare(strict_types=1);

namespace OERManager\Service\Curriculum;

/**
 * TASK-076: the stage subjects (`cur:Materia`) of a REA, derived from its
 * `schema:about` values (`cur:MateriaCurso`) through `cur:materia`
 * (ADR-0022 §5). Stored in `lrmi:educationalAlignment` so property-based
 * search (AdvancedSearch facets, REST API) can filter by subject. Pure, so
 * it is tested on the host; DerivedSubjectWriter feeds it from the entity.
 */
final class SubjectDerivation
{
    /**
     * @param list<array{class:?string, subjects:list<array{id:int, class:?string, title:string}>}> $about
     * @return list<array{id:int, title:string}> distinct, by title then id
     */
    public static function derive(array $about): array
    {
        $byId = [];
        foreach ($about as $target) {
            if (CurModel::MATERIA_CURSO !== ($target['class'] ?? null)) {
                continue;
            }
            foreach ($target['subjects'] ?? [] as $subject) {
                if (CurModel::MATERIA !== ($subject['class'] ?? null)) {
                    continue;
                }
                $id = (int) $subject['id'];
                $byId[$id] = ['id' => $id, 'title' => (string) $subject['title']];
            }
        }
        $derived = array_values($byId);
        usort(
            $derived,
            static fn (array $a, array $b): int => [$a['title'], $a['id']] <=> [$b['title'], $b['id']]
        );
        return $derived;
    }

    /**
     * @param list<array{id:int, title:string}> $derived
     * @return list<int>
     */
    public static function ids(array $derived): array
    {
        return array_map(static fn (array $subject): int => $subject['id'], $derived);
    }

    /**
     * True when the stored values are exactly the derived ones, in order.
     * A stored value that is not an item link is null, so it never matches.
     *
     * @param list<?int> $stored
     * @param list<int> $derived
     */
    public static function matches(array $stored, array $derived): bool
    {
        return array_values($stored) === array_values($derived);
    }
}
```

- [ ] **Step 4: Run the test to verify it passes**

Run: `vendor/bin/phpunit -c test/phpunit.xml test/Service/Curriculum/SubjectDerivationTest.php`
Expected: PASS (4 tests).

- [ ] **Step 5: Lint and commit**

```bash
make lint
git add src/Service/Curriculum/SubjectDerivation.php test/Service/Curriculum/SubjectDerivationTest.php
git commit -m "feat: derive a REA's stage subjects from schema:about (TASK-076)

Model: <model name>"
```

---

### Task 2: `DerivedSubjectWriter`, the listener and its service

**Files:**
- Create: `src/Service/Curriculum/DerivedSubjectWriter.php`
- Modify: `Module.php` (`attachListeners()`; new public method `deriveSubject()` after `guardWorkflowValues()`)
- Modify: `config/module.config.php` (service factory, in the same `factories` array as `Service\Curriculum\CurriculumTerms::class`)
- Test: `test/Service/Curriculum/DerivedSubjectWriterTest.php`; modify `test/ModuleRuntimeTest.php`

**Interfaces:**
- Consumes: `SubjectDerivation::derive()`, `::ids()`, `::matches()` (Task 1); existing `OERManager\Service\Workflow\StatusGuard::term(?object $member): ?string` (`prefix:local` of a property or resource class entity); `MasterViewQuery::LEARNING_RESOURCE_CLASS_TERM`; `CurModel::EDGE_MATERIA`.
- Produces:
  - `DerivedSubjectWriter::TERM = 'lrmi:educationalAlignment'`, `DerivedSubjectWriter::SOURCE_TERM = 'schema:about'`
  - `__construct(\Closure $alignmentProperty, \Closure $newValue)`: `$alignmentProperty(): ?object` returns a managed `Property` entity, or null if not installed; `$newValue(): object` returns a new `Value` entity.
  - `isRea(object $entity): bool`
  - `derivedIds(object $entity): list<int>`
  - `storedIds(object $entity): list<?int>`
  - `apply(object $entity): bool` (true when it changed the values)
  - The class is **not final** (Task 3's test mocks it).
  - `Module::deriveSubject(Event $event): void`

Entity duck types (Doctrine in production, doubles in the tests):
- The item has `getResourceClass()` and `getValues()`. The collection has `add()` and `removeElement()` and is iterable.
- A value has `getProperty()` and `getValueResource()`.
- A target has `getId()`, `getResourceClass()`, `getValues()` and `getTitle()`.
- A new value receives `setResource()`, `setProperty()`, `setType()`, `setValueResource()` and `setIsPublic()`. These are the `Omeka\Entity\Value` setters, checked in Omeka S 4.2.

- [ ] **Step 1: Write the failing writer test**

```php
<?php

declare(strict_types=1);

namespace OERManager\Test\Service\Curriculum;

use OERManager\Service\Curriculum\DerivedSubjectWriter;
use PHPUnit\Framework\TestCase;

final class DerivedSubjectWriterTest extends TestCase
{
    /** @var list<object> values the writer created */
    private array $created = [];
    private object $alignment;

    protected function setUp(): void
    {
        $this->alignment = $this->member('lrmi:educationalAlignment');
    }

    private function writer(bool $installed = true): DerivedSubjectWriter
    {
        return new DerivedSubjectWriter(
            fn (): ?object => $installed ? $this->alignment : null,
            function (): object {
                $value = new class {
                    public $resource;
                    public $property;
                    public $type;
                    public $valueResource;
                    public $isPublic;
                    public function setResource($r)
                    {
                        $this->resource = $r;
                    }
                    public function setProperty($p)
                    {
                        $this->property = $p;
                    }
                    public function setType($t)
                    {
                        $this->type = $t;
                    }
                    public function setValueResource($r)
                    {
                        $this->valueResource = $r;
                    }
                    public function setIsPublic($p)
                    {
                        $this->isPublic = $p;
                    }
                    public function getProperty()
                    {
                        return $this->property;
                    }
                    public function getValueResource()
                    {
                        return $this->valueResource;
                    }
                };
                $this->created[] = $value;
                return $value;
            }
        );
    }

    /** A property or resource class entity: `getVocabulary()->getPrefix()` and `getLocalName()`. */
    private function member(string $term): object
    {
        [$prefix, $local] = explode(':', $term);
        $vocabulary = new class ($prefix) {
            public function __construct(private string $p)
            {
            }
            public function getPrefix()
            {
                return $this->p;
            }
        };
        return new class ($vocabulary, $local) {
            public function __construct(private object $v, private string $l)
            {
            }
            public function getVocabulary()
            {
                return $this->v;
            }
            public function getLocalName()
            {
                return $this->l;
            }
        };
    }

    private function collection(array $values): object
    {
        return new class ($values) implements \IteratorAggregate {
            public function __construct(public array $items)
            {
            }
            public function getIterator(): \Iterator
            {
                return new \ArrayIterator($this->items);
            }
            public function add($v)
            {
                $this->items[] = $v;
            }
            public function removeElement($v)
            {
                $this->items = array_values(array_filter($this->items, static fn ($x) => $x !== $v));
            }
        };
    }

    private function resource(int $id, ?string $class, string $title = '', array $values = []): object
    {
        $classEntity = null === $class ? null : $this->member($class);
        return new class ($id, $classEntity, $title, $this->collection($values)) {
            public function __construct(private int $id, private $class, private string $title, private object $values)
            {
            }
            public function getId()
            {
                return $this->id;
            }
            public function getResourceClass()
            {
                return $this->class;
            }
            public function getTitle()
            {
                return $this->title;
            }
            public function getValues()
            {
                return $this->values;
            }
        };
    }

    private function value(string $term, ?object $target): object
    {
        return new class ($this->member($term), $target) {
            public function __construct(private object $p, private ?object $t)
            {
            }
            public function getProperty()
            {
                return $this->p;
            }
            public function getValueResource()
            {
                return $this->t;
            }
        };
    }

    /** A MateriaCurso item whose cur:materia points to $subject. */
    private function materiaCurso(int $id, ?object $subject): object
    {
        $values = null === $subject ? [] : [$this->value('cur:materia', $subject)];
        return $this->resource($id, 'cur:MateriaCurso', 'MC ' . $id, $values);
    }

    private function rea(array $values, string $class = 'lrmi:LearningResource'): object
    {
        return $this->resource(1, $class, 'REA', $values);
    }

    /** @return list<?int> target ids of the REA's lrmi:educationalAlignment values, in order */
    private function alignmentIds(object $rea): array
    {
        $ids = [];
        foreach ($rea->getValues() as $value) {
            if ('educationalAlignment' === $value->getProperty()->getLocalName()) {
                $ids[] = $value->getValueResource()?->getId();
            }
        }
        return $ids;
    }

    public function testApplyWritesOneValuePerStageSubjectInTitleOrder(): void
    {
        $maths = $this->resource(30, 'cur:Materia', 'Matemáticas (ESO)');
        $biology = $this->resource(20, 'cur:Materia', 'Biología y Geología (ESO)');
        $rea = $this->rea([
            $this->value('schema:about', $this->materiaCurso(101, $maths)),
            $this->value('schema:about', $this->materiaCurso(102, $maths)),
            $this->value('schema:about', $this->materiaCurso(103, $biology)),
        ]);
        $this->assertTrue($this->writer()->apply($rea));
        $this->assertSame([20, 30], $this->alignmentIds($rea));
        $this->assertCount(2, $this->created);
        foreach ($this->created as $value) {
            $this->assertSame($rea, $value->resource);
            $this->assertSame($this->alignment, $value->property);
            $this->assertSame('resource:item', $value->type);
            $this->assertTrue($value->isPublic);
        }
    }

    public function testApplyReplacesHandWrittenAndStaleValues(): void
    {
        $maths = $this->resource(30, 'cur:Materia', 'Matemáticas (ESO)');
        $rea = $this->rea([
            $this->value('schema:about', $this->materiaCurso(101, $maths)),
            $this->value('lrmi:educationalAlignment', $this->resource(99, 'cur:Materia', 'Old')),
            $this->value('lrmi:educationalAlignment', null),
        ]);
        $this->assertSame([99, null], $this->writer()->storedIds($rea));
        $this->assertTrue($this->writer()->apply($rea));
        $this->assertSame([30], $this->alignmentIds($rea));
    }

    public function testRemovingEveryAboutRemovesTheDerivedValues(): void
    {
        $rea = $this->rea([$this->value('lrmi:educationalAlignment', $this->resource(30, 'cur:Materia', 'M'))]);
        $this->assertTrue($this->writer()->apply($rea));
        $this->assertSame([], $this->alignmentIds($rea));
        $this->assertSame([], $this->created);
    }

    public function testApplyLeavesMatchingValuesAlone(): void
    {
        $maths = $this->resource(30, 'cur:Materia', 'Matemáticas (ESO)');
        $stored = $this->value('lrmi:educationalAlignment', $maths);
        $rea = $this->rea([$this->value('schema:about', $this->materiaCurso(101, $maths)), $stored]);
        $this->assertFalse($this->writer()->apply($rea));
        $this->assertSame([], $this->created);
        $this->assertContains($stored, iterator_to_array($rea->getValues()));
    }

    public function testTargetsWithoutClassOrResourceContributeNothing(): void
    {
        $rea = $this->rea([
            $this->value('schema:about', null),
            $this->value('schema:about', $this->resource(5, null)),
            $this->value('schema:about', $this->materiaCurso(6, null)),
            $this->value('schema:about', $this->resource(7, 'cur:Curso', '1º ESO', [
                $this->value('cur:materia', $this->resource(30, 'cur:Materia', 'M')),
            ])),
        ]);
        $this->assertFalse($this->writer()->apply($rea));
        $this->assertSame([], $this->writer()->derivedIds($rea));
    }

    public function testOnlyReaAreTouched(): void
    {
        $alignment = $this->value('lrmi:educationalAlignment', $this->resource(30, 'cur:Materia', 'M'));
        $other = $this->rea([$alignment], 'cur:Curso');
        $this->assertFalse($this->writer()->isRea($other));
        $this->assertFalse($this->writer()->apply($other));
        $this->assertSame([30], $this->alignmentIds($other));
        $this->assertFalse($this->writer()->apply($this->resource(2, null)));
    }

    public function testWithoutTheLrmiPropertyNothingIsWritten(): void
    {
        $maths = $this->resource(30, 'cur:Materia', 'M');
        $rea = $this->rea([$this->value('schema:about', $this->materiaCurso(101, $maths))]);
        $this->assertFalse($this->writer(false)->apply($rea));
        $this->assertSame([], $this->alignmentIds($rea));
    }
}
```

- [ ] **Step 2: Run the test to verify it fails**

Run: `vendor/bin/phpunit -c test/phpunit.xml test/Service/Curriculum/DerivedSubjectWriterTest.php`
Expected: FAIL, `Class "OERManager\Service\Curriculum\DerivedSubjectWriter" not found`.

- [ ] **Step 3: Write the writer**

```php
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
                $subject = $edge->getValueResource();
                if (null === $subject || !method_exists($subject, 'getResourceClass')) {
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
```

- [ ] **Step 4: Run the writer test to verify it passes**

Run: `vendor/bin/phpunit -c test/phpunit.xml test/Service/Curriculum/DerivedSubjectWriterTest.php`
Expected: PASS (7 tests).

- [ ] **Step 5: Write the failing listener test**

In `test/ModuleRuntimeTest.php`, `testLifecycleAclAndListenerContracts()`, change `$this->exactly(6)` to `$this->exactly(7)`. Add after `testStatusGuardListenerRejectsHandMadeWorkflowValues()` (it uses the file's existing `module()`, `entity()` and `valueEntity()` helpers):

```php
    /** TASK-076: the hydrated entity goes to the writer; a non-entity is ignored. */
    public function testDeriveSubjectListenerHandsTheHydratedEntityToTheWriter(): void
    {
        $writer = new \OERManager\Service\Curriculum\DerivedSubjectWriter(
            static fn (): ?object => null,
            static fn (): object => new \stdClass()
        );
        $module = $this->module([\OERManager\Service\Curriculum\DerivedSubjectWriter::class => $writer]);
        $entity = $this->entity(5, 'lrmi:LearningResource', [$this->valueEntity('dcterms:title', 'REA')]);
        // No schema:about and no stored value: nothing to change, and no error.
        $module->deriveSubject((new Event())->setParam('entity', $entity));
        $module->deriveSubject((new Event())->setParam('entity', 'not an entity'));
        $this->assertCount(1, $entity->getValues());
    }
```

- [ ] **Step 6: Run it to verify it fails**

Run: `vendor/bin/phpunit -c test/phpunit.xml test/ModuleRuntimeTest.php`
Expected: FAIL: `Call to undefined method OERManager\Module::deriveSubject()`, and the attach count is 6, not 7.

- [ ] **Step 7: Add the listener and the service**

In `Module::attachListeners()`, after the `guardWorkflowValues` attachment:

```php
        // TASK-076: the stage subject derived from schema:about, on the
        // hydrated entity so every save path (batch append/remove/clear
        // included) derives from the final values, in the same flush.
        $sharedEventManager->attach(
            'Omeka\Api\Adapter\ItemAdapter',
            'api.hydrate.post',
            [$this, 'deriveSubject']
        );
```

After `guardWorkflowValues()`:

```php
    /**
     * TASK-076: rewrites `lrmi:educationalAlignment` of a REA with the stage
     * subjects of its `schema:about` (DerivedSubjectWriter). Never an error.
     */
    public function deriveSubject(Event $event): void
    {
        $entity = $event->getParam('entity');
        if (
            !is_object($entity)
            || !method_exists($entity, 'getValues')
            || !method_exists($entity, 'getResourceClass')
        ) {
            return;
        }
        $this->getServiceLocator()->get(Service\Curriculum\DerivedSubjectWriter::class)->apply($entity);
    }
```

In `config/module.config.php`, in the same `factories` array as `Service\Curriculum\CurriculumTerms::class`:

```php
            // TASK-076. The property id is looked up once; the entity is a
            // fresh reference on each call, so it is always managed.
            Service\Curriculum\DerivedSubjectWriter::class => function ($container) {
                $propertyId = null;
                return new Service\Curriculum\DerivedSubjectWriter(
                    static function () use ($container, &$propertyId): ?object {
                        if (null === $propertyId) {
                            $found = $container->get('Omeka\ApiManager')->search('properties', [
                                'term' => Service\Curriculum\DerivedSubjectWriter::TERM,
                            ])->getContent();
                            $propertyId = $found ? (int) reset($found)->id() : 0;
                        }
                        if ($propertyId <= 0) {
                            return null;
                        }
                        return $container->get('Omeka\EntityManager')
                            ->getReference(\Omeka\Entity\Property::class, $propertyId);
                    },
                    static fn (): object => new \Omeka\Entity\Value()
                );
            },
```

- [ ] **Step 8: Run the tests to verify they pass**

Run: `vendor/bin/phpunit -c test/phpunit.xml test/ModuleRuntimeTest.php test/Service/Curriculum`, then `make lint`.
Expected: PASS; lint clean.

- [ ] **Step 9: Commit**

```bash
git add src/Service/Curriculum/DerivedSubjectWriter.php Module.php config/module.config.php \
  test/Service/Curriculum/DerivedSubjectWriterTest.php test/ModuleRuntimeTest.php
git commit -m "feat: every REA save rewrites its derived stage subject (TASK-076)

Model: <model name>"
```

---

### Task 3: the resync job

**Files:**
- Create: `src/Job/ResyncDerivedSubjects.php`
- Test: `test/Job/ResyncDerivedSubjectsTest.php`

**Interfaces:**
- Consumes: `DerivedSubjectWriter::storedIds()`, `::derivedIds()` (Task 2); `SubjectDerivation::matches()` (Task 1); `MasterViewQuery::buildSearchParams([])['resource_class_id']` (existing; null when the REA class is missing, as `QueueCounter` relies on).
- Produces: `OERManager\Job\ResyncDerivedSubjects` (no args), and `public static function run(callable $ids, callable $entity, DerivedSubjectWriter $writer, callable $update, callable $shouldStop, ?callable $onError = null): array{examined:int, changed:int, failed:int}`. `perform()` is thin glue over `run()`, which the harness of Task 5 also calls.

- [ ] **Step 1: Write the failing test**

```php
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
```

- [ ] **Step 2: Run it to verify it fails**

Run: `vendor/bin/phpunit -c test/phpunit.xml test/Job/ResyncDerivedSubjectsTest.php`
Expected: FAIL, `Class "OERManager\Job\ResyncDerivedSubjects" not found`.

- [ ] **Step 3: Write the job**

```php
<?php

declare(strict_types=1);

namespace OERManager\Job;

use OERManager\Service\Curriculum\DerivedSubjectWriter;
use OERManager\Service\Curriculum\SubjectDerivation;
use OERManager\Service\MasterViewQuery;
use Omeka\Job\AbstractJob;

/**
 * TASK-076: recomputes the derived stage subject of every REA and updates only
 * those that differ, so a second run changes nothing and `o:modified` of the
 * rest does not move. The update is an empty partial update: the
 * `api.hydrate.post` listener does the writing. Run after deploying TASK-076
 * and after each curriculum release (a MateriaCurso may change its
 * cur:materia). Launched by a global administrator from the configuration page.
 *
 * Does not clear the EntityManager, for the reason GovernanceBatchJob gives:
 * clearing detaches the job's owner; about 12 KB per item, some 35 MB for 3 000 REA.
 */
class ResyncDerivedSubjects extends AbstractJob
{
    public function perform(): void
    {
        $services = $this->getServiceLocator();
        $api = $services->get('Omeka\ApiManager');
        $entityManager = $services->get('Omeka\EntityManager');
        $logger = $services->get('Omeka\Logger');
        $classId = $services->get(MasterViewQuery::class)->buildSearchParams([])['resource_class_id'] ?? null;
        if (null === $classId) {
            $logger->warn('OERManager resync derived subjects: no lrmi:LearningResource class, nothing to do.');
            return;
        }
        $result = self::run(
            static fn (): array => array_map('intval', array_values($api->search('items', [
                'resource_class_id' => $classId,
                'return_scalar' => 'id',
            ])->getContent())),
            static fn (int $id): ?object => $entityManager->find(\Omeka\Entity\Item::class, $id),
            $services->get(DerivedSubjectWriter::class),
            static function (int $id) use ($api): void {
                $api->update('items', $id, [], [], ['isPartial' => true]);
            },
            fn (): bool => $this->shouldStop(),
            static function (int $id, \Throwable $e) use ($logger): void {
                $logger->err(sprintf('OERManager resync derived subjects: item %d: %s', $id, $e->getMessage()));
            }
        );
        $logger->info(sprintf(
            'OERManager resync derived subjects: %d REA examined, %d changed, %d failed.',
            $result['examined'],
            $result['changed'],
            $result['failed']
        ));
    }

    /**
     * @param callable():list<int> $ids
     * @param callable(int):?object $entity
     * @param callable(int):void $update
     * @param callable():bool $shouldStop
     * @param (callable(int, \Throwable):void)|null $onError
     * @return array{examined:int, changed:int, failed:int}
     */
    public static function run(
        callable $ids,
        callable $entity,
        DerivedSubjectWriter $writer,
        callable $update,
        callable $shouldStop,
        ?callable $onError = null
    ): array {
        $result = ['examined' => 0, 'changed' => 0, 'failed' => 0];
        foreach ($ids() as $id) {
            if ($shouldStop()) {
                break;
            }
            $item = $entity($id);
            if (null === $item) {
                continue;
            }
            $result['examined']++;
            if (SubjectDerivation::matches($writer->storedIds($item), $writer->derivedIds($item))) {
                continue;
            }
            try {
                $update($id);
                $result['changed']++;
            } catch (\Throwable $e) {
                $result['failed']++;
                if (null !== $onError) {
                    $onError($id, $e);
                }
            }
        }
        return $result;
    }
}
```

- [ ] **Step 4: Run it to verify it passes**

Run: `vendor/bin/phpunit -c test/phpunit.xml test/Job/ResyncDerivedSubjectsTest.php test/Service/Curriculum`, then `make lint`.
Expected: PASS (3 + 11 tests); lint clean.

- [ ] **Step 5: Commit**

```bash
git add src/Job/ResyncDerivedSubjects.php test/Job/ResyncDerivedSubjectsTest.php
git commit -m "feat: a job resyncs the derived stage subject of every REA (TASK-076)

Model: <model name>"
```

---

### Task 4: launch from the configuration page

**Files:**
- Modify: `src/Controller/Admin/IndexController.php` (`configAction()`; new `resyncSubjectsAction()` right after it)
- Modify: `src/Permissions/ReaAcl.php` (`ADMIN_ONLY`)
- Modify: `view/oer-manager/admin/index/config.phtml`
- Test: `test/Controller/IndexControllerTest.php`, `test/Permissions/ReaAclTest.php`

**Interfaces:**
- Consumes: `OERManager\Job\ResyncDerivedSubjects` (Task 3); the controller's existing `csrfValidator()`, `$this->jobDispatcher`, `$this->logger`, and `Omeka\Stdlib\Message` (already imported: `withReaSite()` uses it).
- Produces: route `admin/oer-manager` with `action => 'resync-subjects'` (POST, field `csrf`); the view variable `resyncCsrf` on the config page.

- [ ] **Step 1: Write the failing tests**

In `test/Permissions/ReaAclTest.php`, `testEveryControllerActionIsDecidedAndEveryGrantIsAnAction()`, change the last assertion to:

```php
        $this->assertSame([IndexController::class => ['config', 'resync-subjects'],
            GovernanceBatchController::class => [GovernanceBatchController::PRIVILEGE_UNDO_ANY]], ReaAcl::ADMIN_ONLY);
```

In `test/Controller/IndexControllerTest.php`, add:

```php
    /** TASK-076: a global administrator's action; POST with a valid token only. */
    public function testResyncSubjectsNeedsPostAndCsrf(): void
    {
        $config = ['admin/oer-manager', ['action' => 'config']];
        $dispatched = [];
        $this->dependencies['jobDispatcher']->method('dispatch')->willReturnCallback(
            static function ($class) use (&$dispatched) {
                $dispatched[] = $class;
                return new \Omeka\Entity\Job();
            }
        );

        $this->request->post = false;
        $this->assertSame($config, $this->controller->resyncSubjectsAction());
        $this->request->post = true;
        $this->params->post['csrf'] = 'invalid';
        $this->assertSame($config, $this->controller->resyncSubjectsAction());
        $this->assertSame([], $dispatched);

        $this->params->post['csrf'] = 'valid';
        $this->assertSame($config, $this->controller->resyncSubjectsAction());
        $this->assertSame([\OERManager\Job\ResyncDerivedSubjects::class], $dispatched);
    }

    public function testResyncSubjectsReportsADispatchFailure(): void
    {
        $this->dependencies['jobDispatcher']->method('dispatch')->willThrowException(new \RuntimeException('down'));
        $this->params->post['csrf'] = 'valid';
        $this->assertSame(['admin/oer-manager', ['action' => 'config']], $this->controller->resyncSubjectsAction());
        $this->assertContains('No se pudo lanzar el recálculo de la materia derivada.', $this->messages->messages);
    }
```

In `testConfigGetAndValidAndInvalidSubmissions()`, right after the first `configAction()` assertion (the GET), add:

```php
        $this->assertSame('valid', $this->controller->configAction()->getVariable('resyncCsrf'));
```

- [ ] **Step 2: Run them to verify they fail**

Run: `vendor/bin/phpunit -c test/phpunit.xml test/Controller/IndexControllerTest.php test/Permissions/ReaAclTest.php`
Expected: FAIL (`resyncSubjectsAction` undefined; `ADMIN_ONLY` mismatch; `resyncCsrf` null).

- [ ] **Step 3: Implement**

`src/Permissions/ReaAcl.php`:

```php
    public const ADMIN_ONLY = [
        // TASK-076: the resync of derived subjects writes every REA.
        IndexController::class => ['config', 'resync-subjects'],
        GovernanceBatchController::class => [GovernanceBatchController::PRIVILEGE_UNDO_ANY],
    ];
```

`IndexController::configAction()`, before `return $view;`:

```php
        // TASK-076: the separate «Recalcular» form posts to resync-subjects.
        $view->setVariable('resyncCsrf', $this->csrfValidator()->getHash());
```

Right after `configAction()`:

```php
    /**
     * TASK-076: dispatches ResyncDerivedSubjects. Global administrator only
     * (ReaAcl::ADMIN_ONLY), POST with the CSRF token of the configuration page.
     */
    public function resyncSubjectsAction()
    {
        $back = ['admin/oer-manager', ['action' => 'config']];
        if (!$this->getRequest()->isPost()) {
            return $this->redirect()->toRoute(...$back);
        }
        if (!$this->csrfValidator()->isValid((string) $this->params()->fromPost('csrf'))) {
            $this->messenger()->addError('El formulario ha caducado; vuelve a intentarlo.'); // @translate
            return $this->redirect()->toRoute(...$back);
        }
        try {
            $job = $this->jobDispatcher->dispatch(\OERManager\Job\ResyncDerivedSubjects::class);
        } catch (\Exception $e) {
            $this->logger->err('OERManager resync derived subjects dispatch: ' . $e->getMessage());
            $this->messenger()->addError('No se pudo lanzar el recálculo de la materia derivada.'); // @translate
            return $this->redirect()->toRoute(...$back);
        }
        $this->messenger()->addSuccess(new Message(
            'Recálculo de la materia derivada lanzado (trabajo %d). El resultado queda en su registro.', // @translate
            (int) $job->getId()
        ));
        return $this->redirect()->toRoute(...$back);
    }
```

`view/oer-manager/admin/index/config.phtml`: add `@var string $resyncCsrf` to the docblock, and after `<?php echo $this->form()->closeTag(); ?>`:

```php
<?php // TASK-076: a separate form, so it never mixes with the settings (ConfigPayload::write). ?>
<form method="post" class="oer-resync-subjects"
      action="<?php echo $this->escapeHtml($this->url('admin/oer-manager', ['action' => 'resync-subjects'])); ?>">
    <input type="hidden" name="csrf" value="<?php echo $this->escapeHtml((string) ($resyncCsrf ?? '')); ?>">
    <h3><?php echo $translate('Materia derivada de los REA'); ?></h3>
    <p><?php echo $translate('Recalcula la materia de etapa que se guarda en cada REA para la búsqueda pública. Hazlo tras instalar esta versión y tras cargar una versión nueva del currículo.'); ?></p>
    <button type="submit" class="button"><?php echo $translate('Recalcular la materia derivada de los REA'); ?></button>
</form>
```

- [ ] **Step 4: Run them to verify they pass**

Run: `make test`, then `make lint`.
Expected: PASS; lint clean.

- [ ] **Step 5: Commit**

```bash
git add src/Controller/Admin/IndexController.php src/Permissions/ReaAcl.php view/oer-manager/admin/index/config.phtml \
  test/Controller/IndexControllerTest.php test/Permissions/ReaAclTest.php
git commit -m "feat: global administrators launch the derived subject resync (TASK-076)

Model: <model name>"
```

---

### Task 5: container harness over the real save paths

**Files:**
- Create: `test/container/subject-derivation-check.php`

**Interfaces:**
- Consumes:
  - from `test/container/cur-fixture.php`: `curAuthenticate`, `curClear`, `curFixture` (`SMAT01`, `SMAT02` = Matemáticas 1º/2º ESO; `scheme`), `curMarker`, `curLiteral`, `curLink`, `curCreateRea`, `curDeleteAll`;
  - `RecatalogService::undo()`;
  - `ResyncDerivedSubjects::run()` (Task 3);
  - `DerivedSubjectWriter::TERM` (Task 2);
  - `CurModel::termsQuery()`.

**Writes to the catalogue.** It needs the literal flag `--write` and runs only on the development or a disposable catalogue. It deletes the disposable REA it creates. The job's first run back-fills every REA of the catalogue, which is the deployment back-fill itself: wanted in development, and to be reported.

- [ ] **Step 1: Write the harness**

```php
<?php
// CLI only: these harnesses bootstrap Omeka and this one writes to the catalogue.
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

/**
 * TASK-076: `lrmi:educationalAlignment` follows `schema:about` on every real
 * save path, and the resync job is idempotent.
 *
 * ⚠️ WRITES TO THE CATALOGUE: disposable REA (deleted at the end), and the
 * job's first run back-fills every REA. Development or disposable catalogue
 * only (AGENTS.md). Requires the literal flag --write.
 *
 * Use, inside the container:
 *   php /var/www/html/volume/modules/OERManager/test/container/subject-derivation-check.php <userEmail> --write
 *
 * Exit 1 if a check fails.
 */

require '/var/www/html/bootstrap.php';

use OERManager\Job\ResyncDerivedSubjects;
use OERManager\Service\Curriculum\CurModel;
use OERManager\Service\Curriculum\DerivedSubjectWriter;
use OERManager\Service\RecatalogService;

$application = Omeka\Mvc\Application::init(require '/var/www/html/application/config/application.config.php');
$services = $application->getServiceManager();
require __DIR__ . '/cur-fixture.php';

$email = (string) ($argv[1] ?? '');
if ('' === $email || !in_array('--write', $argv, true)) {
    fwrite(STDERR, "usage: subject-derivation-check.php <userEmail> --write\n");
    exit(2);
}
curAuthenticate($services, $email);

$api = $services->get('Omeka\ApiManager');
$recatalog = $services->get(RecatalogService::class);
$writer = $services->get(DerivedSubjectWriter::class);
$fixture = curFixture($services);
$marker = curMarker('subject-derivation-check');
$created = [];
$failed = 0;
$check = static function (bool $ok, string $label) use (&$failed): void {
    echo '  ' . ($ok ? 'OK  ' : 'FAIL') . ' ' . $label . "\n";
    $failed += $ok ? 0 : 1;
};
/** @return list<int> target ids of the REA's derived values, as stored (fresh read) */
$derived = static function (int $id) use ($services, $api, $email): array {
    curClear($services, $email);
    $ids = [];
    foreach ($api->read('items', $id)->getContent()->value(DerivedSubjectWriter::TERM, ['all' => true]) as $value) {
        $ids[] = $value->valueResource() ? (int) $value->valueResource()->id() : 0;
    }
    return $ids;
};
/** The cur:materia id of a MateriaCurso item, or 0. */
$subjectOf = static function (int $materiaCurso) use ($api): int {
    $value = $api->read('items', $materiaCurso)->getContent()->value(CurModel::EDGE_MATERIA);
    return $value && $value->valueResource() ? (int) $value->valueResource()->id() : 0;
};
$titleOf = static fn (int $id): string => (string) $api->read('items', $id)->getContent()->displayTitle();
$classId = static fn (string $term): int
    => (int) $api->search('resource_classes', ['term' => $term])->getContent()[0]->id();
/** A current MateriaCurso whose subject is not $subject, or 0. */
$otherMateriaCurso = static function (int $subject) use ($api, $fixture, $subjectOf, $classId): int {
    $query = CurModel::termsQuery($fixture['scheme'], $classId(CurModel::MATERIA_CURSO), '', 1);
    foreach ($api->search('items', ['per_page' => 50] + $query)->getContent() as $item) {
        if (!in_array($subjectOf((int) $item->id()), [0, $subject], true)) {
            return (int) $item->id();
        }
    }
    return 0;
};

try {
    $maths = $subjectOf($fixture['SMAT01']);
    $check($maths > 0 && $maths === $subjectOf($fixture['SMAT02']), 'SMAT01 and SMAT02 share one cur:Materia');
    $other = $otherMateriaCurso($maths);
    $otherSubject = $other > 0 ? $subjectOf($other) : 0;
    $check($otherSubject > 0, 'a MateriaCurso of another subject exists');

    // 1. Re-cataloguer: two years of Mathematics give one derived value.
    $rea = curCreateRea($services, $marker, ['schema:about' => [$fixture['SMAT01'], $fixture['SMAT02']]], $email, $created);
    $check([$maths] === $derived($rea), 're-cataloguer: two years of one subject give one value');

    // 2. Undo of that re-cataloguing: schema:about empties and the value goes.
    $recatalog->undo($rea, $email);
    $check([] === $derived($rea), 'undo: no schema:about, no derived value');

    // 3. REST API create with schema:about and a hand-written educationalAlignment.
    $data = ['o:resource_class' => ['o:id' => $classId('lrmi:LearningResource')], 'o:is_public' => false]
        + curLiteral($api, 'dcterms:title', $marker)
        + curLink($api, 'schema:about', $other)
        + curLink($api, DerivedSubjectWriter::TERM, $maths);
    $viaApi = (int) $api->create('items', $data)->getContent()->id();
    $created[] = $viaApi;
    $check([$otherSubject] === $derived($viaApi), 'API create: a hand-written value is replaced by the derived one');

    // 4. Batch append of a Mathematics year: both subjects, in title order.
    $api->batchUpdate('items', [$viaApi], curLink($api, 'schema:about', $fixture['SMAT01']), [
        'continueOnError' => false,
        'collectionAction' => 'append',
    ]);
    $expected = [$maths, $otherSubject];
    usort($expected, static fn (int $a, int $b): int => [$titleOf($a), $a] <=> [$titleOf($b), $b]);
    $check($expected === $derived($viaApi), 'batch append: both subjects, in title order');

    // 5. Batch clear of schema:about: no derived value.
    $aboutId = (int) $api->search('properties', ['term' => 'schema:about'])->getContent()[0]->id();
    $api->batchUpdate('items', [$viaApi], ['clear_property_values' => [$aboutId]], ['continueOnError' => false]);
    $check([] === $derived($viaApi), 'batch clear_property_values: no derived value');

    // 6. A partial update that appends schema:about keeps the rest.
    $api->update('items', $viaApi, curLink($api, 'schema:about', $fixture['SMAT02']), [], [
        'isPartial' => true,
        'collectionAction' => 'append',
    ]);
    $check([$maths] === $derived($viaApi), 'partial update: the derived value follows');
    $check($marker === $titleOf($viaApi), 'partial update keeps the title');

    // 7. Job: run twice; the second run changes nothing; o:modified of an up-to-date REA does not move.
    curClear($services, $email);
    $before = $api->read('items', $viaApi)->getContent()->modified();
    $runJob = static function () use ($services, $api, $writer, $classId): array {
        $reaClass = $classId('lrmi:LearningResource');
        return ResyncDerivedSubjects::run(
            static fn (): array => array_map('intval', array_values($api->search('items', [
                'resource_class_id' => $reaClass,
                'return_scalar' => 'id',
            ])->getContent())),
            static fn (int $id): ?object => $services->get('Omeka\EntityManager')->find(\Omeka\Entity\Item::class, $id),
            $writer,
            static function (int $id) use ($api): void {
                $api->update('items', $id, [], [], ['isPartial' => true]);
            },
            static fn (): bool => false
        );
    };
    $first = $runJob();
    printf("  job, first run: %d examined, %d changed, %d failed\n", $first['examined'], $first['changed'], $first['failed']);
    $check(0 === $first['failed'], 'job, first run: no failure');
    curClear($services, $email);
    $second = $runJob();
    $check(0 === $second['changed'] && 0 === $second['failed'], 'job, second run: 0 changed');
    curClear($services, $email);
    $check($before == $api->read('items', $viaApi)->getContent()->modified(), 'job leaves o:modified of an up-to-date REA alone');
} finally {
    $left = curDeleteAll($services, $created, $marker);
    $check(0 === $left, 'disposable REA deleted');
}

echo $failed ? "FAILED: $failed\n" : "ALL OK\n";
exit($failed ? 1 : 0);
```

Before running, check in the container that `Omeka\Api\Manager::batchUpdate($resource, array $ids, array $data = [], array $options = [])` has that signature in Omeka S 4.2, and adapt the two calls if not.

- [ ] **Step 2: Run it in the development container**

```bash
docker exec omeka-s-moduletemplate-omekas-1 php /var/www/html/volume/modules/OERManager/test/container/subject-derivation-check.php <global-admin email> --write
```

Expected: every line `OK`, `ALL OK`, exit 0. If a line fails, fix the code, not the expectation, and rerun.

- [ ] **Step 3: Commit**

```bash
git add test/container/subject-derivation-check.php
git commit -m "test: container harness for the derived stage subject (TASK-076)

Model: <model name>"
```

---

### Task 6: decisions and closure docs

**Files:**
- Modify: `docs/decisions/0022-curriculo-modelo-cur.md` (append the addendum at the end)
- Modify: `docs/backlog.md` (TASK-076 row → `hecha`; TASK-071 row gains the deployment steps)
- Modify: `docs/traceability.md` (new row)
- Modify: `docs/project-memory.md` (dated entry)

- [ ] **Step 1: ADR-0022 addendum**

Append to `docs/decisions/0022-curriculo-modelo-cur.md`:

```markdown
## Addendum 1 (2026-11-24, TASK-076): the stage subject is derived for search

Owner decision on 2026-11-24. Point 2 is refined: stage, stage subject and competences are not
*catalogued* on the REA. The stage subject is *derived*: on every REA save, OERManager writes into
`lrmi:educationalAlignment` the `cur:Materia` reached through `cur:materia` from each
`schema:about` value, so property-based search (AdvancedSearch facets, REST API) can filter by
subject. It is not a curation dimension: nobody edits it, and it carries no annotations and no
curation event. LRMI expresses a subject as an alignment to a framework node (`alignmentType`
«educationalSubject»); Omeka has no nested objects, so the value points straight at the node.
Point 3 holds (`lrmi`). Rejected: `dcterms:subject` (the mediateca template uses it for free
keywords) and a `cur:` property on the REA (point 3, ADR-0013). Spec:
`docs/superpowers/specs/2026-11-24-task-076-subject-facet-design.md`.
```

- [ ] **Step 2: Backlog, traceability, memory**

- `docs/backlog.md`, TASK-076: set the status to `hecha` and the last column to `—`. Append to the description: «Done 2026-11-24: listener on `api.hydrate.post`, job «Recalcular la materia derivada de los REA» on the configuration page, harness `test/container/subject-derivation-check.php`. [Plan](superpowers/plans/2026-11-24-task-076-derived-subject.md).»
- `docs/backlog.md`, TASK-071: append «**TASK-076 (2026-11-24):** after installing, run «Recalcular la materia derivada de los REA» (configuration page), and repeat it after each curriculum release; in AdvancedSearch of the REA site, the «Materia» facet uses `lrmi:educationalAlignment`.»
- `docs/traceability.md`: add the row «| Faceta pública por materia de etapa | RF-004, RF-006 | ADR-0022 addendum 1 | TASK-076 | Done 2026-11-24: `lrmi:educationalAlignment` derived from `schema:about` on every REA save; resync job; facet configured in deployment (TASK-071). |»
- `docs/project-memory.md`: add the entry «- **TASK-076 done (2026-11-24): derive on the hydrated entity, not the request.** Batch updates are partial and carry append/remove/clear, so only `api.hydrate.post` sees the final `schema:about`; `Resource::$values` is orphanRemoval + cascade persist, so the derived values go out in the same flush. `dcterms:subject` was rejected because the mediateca template uses it for keywords. The curriculum contract (`docs/omeka.md` of `curriculo_lomloe_canarias`, «Consumidores») still needs the note, as a PR to that repository.»

- [ ] **Step 3: Full verification and commit**

Run: `make lint && make test && make test-js`
Expected: all pass.

```bash
git add docs/decisions/0022-curriculo-modelo-cur.md docs/backlog.md docs/traceability.md docs/project-memory.md
git commit -m "docs: close TASK-076 with ADR-0022 addendum 1

Model: <model name>"
```

---

## Out of this plan (for the owner)

- The AdvancedSearch facet configuration on the REA site (deployment, TASK-071).
- The note in the curriculum contract, as a PR to `ateeducacion/curriculo_lomloe_canarias`.
- Deriving the stage (`cur:Etapa`) into its own property: a possible later task.
