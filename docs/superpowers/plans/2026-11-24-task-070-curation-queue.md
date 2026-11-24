# TASK-070 Curation Queue Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.
>
> **For a human collaborator:** work the tasks in order, one commit per task. Every task is test-first:
> write the test, see it fail, write the code, see it pass, run `make lint`, commit.

**Goal:** The master view gains a status filter «En cola» (Propuesto plus public REA outside the flow),
a «Sin campo obligatorio» filter, an age filter and a «Siguiente» step in the panel, and «Cola (N)»
opens it oldest first.

**Architecture:**
- `IdConstraints` (pure) says which unions a request needs: «en cola» and «le falta algún obligatorio».
- `IdConstraintResolver` turns each union into a set of item ids with id-only searches
  (`return_scalar => 'id'`) and intersects them.
- `indexAction()` passes the ids to the existing search as `id => [...]`, so sort, pagination and the
  computed filters keep working unchanged.
- In the browser, the panel announces a finished workflow action. A small module marks the row,
  lowers «Cola (N)» and offers «Siguiente».

**Tech Stack:** PHP 8.4, Omeka S 4.2 (Laminas MVC), PHPUnit 11, PSR-12 (`make lint`), ES modules with
`node --test` (`make test-js`).

**Spec:** `docs/superpowers/specs/2026-11-24-task-070-curation-queue-design.md`. Read it first,
especially «Why id sets».

## Global Constraints

- Target PHP 8.4. No PHPStan or Psalm. `make lint` (PSR-12) must pass.
- Line coverage of `src/` must stay at 90 % or more (`test/check-coverage.php`): every new class gets
  unit tests.
- No custom Doctrine tables (NFR-002). Nothing new is stored.
- **Never mix branch rows with the master view's property rows** in one search: the core joins
  property rows with AND/OR without parentheses. Branch searches carry only `resource_class_id`,
  `return_scalar` and the branch.
- **An empty id set means zero results without calling the API.** The core ignores `id => []` and
  would return every REA.
- Status literals come from `WorkflowStatus` constants (`PROPOSED`, `PUBLISHED`, `DISCARDED`), never
  typed by hand.
- Query parameters: `status=queue`, `missing[]=required`, `age=7|30|90`. Any other value is ignored.
- UI copy is Spanish and goes through `$translate` (PHP) or `Omeka.jsTranslate` (JS). Every JS string
  is listed in `js_translate_strings` (`config/module.config.php`) with `// @translate`.
- Code comments, docs and commit messages are in English. Branch: `feature/task-070-curation-queue`
  from an updated `main`.
- Commit messages carry no `Co-Authored-By` trailer for AI tools. If an AI wrote the change, name the
  model in a plain `Model: …` line of the body.
- Container harnesses run only on a disposable or development catalogue, never the live one
  (AGENTS.md). The one in Task 9 is read-only.
- Do not change the Makefile, the panel's actions, `RecatalogRule`, the ACL or «Mis propuestas».

## Review Focus

1. **«En cola» combined with another property filter** (e.g. `status=queue&missing[]=licence`).
   Expected: only queue REA without licence; the queue's rows never enter the master view's property
   list. → Task 4 (`testBranchSearchesCarryOnlyTheirOwnRows`) and Task 6.
2. **A filter that leaves no REA** (empty union, or empty intersection). Expected: «0 results», never
   the whole catalogue. → Task 1 (`testCombineEmptyIntersection`), Task 6
   (`testAnEmptyIdSetShowsNoResultsWithoutSearching`).
3. **`missing[]=required` with no REA template configured, or a template id that no longer exists.**
   Expected: the checkbox is not offered and the parameter changes nothing. → Task 3 and Task 1
   (`testRequiredWithoutTermsAddsNoGroup`).
4. **`age` with a value outside 7/30/90 (`age=5`, `age=abc`, `age[]=7`).** Expected: ignored, no
   `created_before`. → Task 5.
5. **«Siguiente» on the last row, or on a row the curator reached through a filter that no longer
   lists it.** Expected: the button reads «Recargar la lista» and reloads. → Task 8
   (`nextItemId` tests).

---

### Task 1: `IdConstraints`, the pure description of the unions

**Files:**
- Create: `src/Service/IdConstraints.php`
- Test: `test/Service/IdConstraintsTest.php`

**Interfaces:**
- Produces:
  - `IdConstraints::STATUS_QUEUE = 'queue'`, `IdConstraints::MISSING_REQUIRED = 'required'`
  - `IdConstraints::queueBranches(): list<array<string,mixed>>`
  - `IdConstraints::groups(array $query, list<string> $requiredTerms): list<list<array<string,mixed>>>`
  - `IdConstraints::combine(list<list<list<int>>> $idsPerGroup): list<int>` (union inside a group,
    intersection across groups, ascending ids)

- [ ] **Step 1: Write the failing test**

```php
<?php

declare(strict_types=1);

namespace OERManager\Test\Service;

use OERManager\Service\IdConstraints;
use OERManager\Service\Workflow\WorkflowStatus;
use PHPUnit\Framework\TestCase;

/** TASK-070: the unions the master view needs, as search fragments. */
final class IdConstraintsTest extends TestCase
{
    public function testTheQueueIsProposedOrPublicOutsideTheFlow(): void
    {
        $status = static fn (string $type, string $text): array => [
            'property' => WorkflowStatus::STATUS_TERM, 'type' => $type, 'text' => $text,
        ];
        $this->assertSame([
            ['property' => [$status('eq', WorkflowStatus::PROPOSED)]],
            ['is_public' => true, 'property' => [
                $status('neq', WorkflowStatus::PROPOSED),
                $status('neq', WorkflowStatus::PUBLISHED),
                $status('neq', WorkflowStatus::DISCARDED),
            ]],
        ], IdConstraints::queueBranches());
    }

    public function testNothingToConstrain(): void
    {
        $this->assertSame([], IdConstraints::groups([], ['dcterms:title']));
        $this->assertSame([], IdConstraints::groups(['status' => 'proposed'], []));
        $this->assertSame([], IdConstraints::groups(['missing' => ['licence']], ['dcterms:title']));
    }

    public function testStatusQueueAddsTheQueueGroup(): void
    {
        $this->assertSame([IdConstraints::queueBranches()], IdConstraints::groups(['status' => 'queue'], []));
    }

    public function testRequiredAddsOneNexBranchPerTerm(): void
    {
        $groups = IdConstraints::groups(
            ['missing' => ['licence', 'required']],
            ['dcterms:title', 'dcterms:license', 'dcterms:title']
        );
        $this->assertSame([[
            ['property' => [['property' => 'dcterms:title', 'type' => 'nex']]],
            ['property' => [['property' => 'dcterms:license', 'type' => 'nex']]],
        ]], $groups);
    }

    public function testRequiredWithoutTermsAddsNoGroup(): void
    {
        $this->assertSame([], IdConstraints::groups(['missing' => ['required']], []));
    }

    public function testBothGroupsInOrder(): void
    {
        $groups = IdConstraints::groups(['status' => 'queue', 'missing' => 'required'], ['dcterms:title']);
        $this->assertCount(2, $groups);
        $this->assertSame(IdConstraints::queueBranches(), $groups[0]);
    }

    public function testCombineUnionsInsideAndIntersectsAcross(): void
    {
        $this->assertSame([3, 5, 9], IdConstraints::combine([[[9, 3], [5, 3]]]));
        $this->assertSame([3, 5], IdConstraints::combine([[[9, 3], [5]], [[5, 3, 7]]]));
    }

    public function testCombineEmptyIntersection(): void
    {
        $this->assertSame([], IdConstraints::combine([[[1, 2]], [[3]]]));
        $this->assertSame([], IdConstraints::combine([[[], []]]));
        $this->assertSame([], IdConstraints::combine([]));
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `vendor/bin/phpunit -c test/phpunit.xml --filter IdConstraintsTest`
Expected: FAIL, `Class "OERManager\Service\IdConstraints" not found`.

- [ ] **Step 3: Write the implementation**

```php
<?php

declare(strict_types=1);

namespace OERManager\Service;

use OERManager\Service\Workflow\WorkflowStatus;

/**
 * TASK-070: the unions the master view cannot ask the core in one search.
 *
 * A group is a union of branches; a branch is a fragment of item search
 * parameters. The request's ids are the intersection of its groups. Pure:
 * `IdConstraintResolver` runs the searches.
 *
 * The core joins property rows with AND/OR without parentheses and
 * `is_public` is not a property, so «Propuesto OR public outside the flow»
 * and «lacks any required field» cannot be one search (spec «Why id sets»).
 */
final class IdConstraints
{
    public const STATUS_QUEUE = 'queue';
    public const MISSING_REQUIRED = 'required';

    /**
     * The queue (ADR-0023 §2–3): Propuesto, plus public REA whose status is
     * none of Propuesto, Publicado and Descartado. Disjoint branches, so
     * `QueueCounter` may add their counts.
     *
     * @return list<array<string,mixed>>
     */
    public static function queueBranches(): array
    {
        $status = static fn (string $type, string $text): array => [
            'property' => WorkflowStatus::STATUS_TERM,
            'type' => $type,
            'text' => $text,
        ];
        return [
            ['property' => [$status('eq', WorkflowStatus::PROPOSED)]],
            ['is_public' => true, 'property' => [
                $status('neq', WorkflowStatus::PROPOSED),
                $status('neq', WorkflowStatus::PUBLISHED),
                $status('neq', WorkflowStatus::DISCARDED),
            ]],
        ];
    }

    /**
     * @param array<string,mixed> $query the master view's GET parameters
     * @param list<string> $requiredTerms required properties of the REA template
     * @return list<list<array<string,mixed>>>
     */
    public static function groups(array $query, array $requiredTerms): array
    {
        $groups = [];
        if (self::STATUS_QUEUE === ($query['status'] ?? null)) {
            $groups[] = self::queueBranches();
        }
        $missing = (array) ($query['missing'] ?? []);
        $terms = array_values(array_unique($requiredTerms));
        if (in_array(self::MISSING_REQUIRED, $missing, true) && [] !== $terms) {
            $groups[] = array_map(
                static fn (string $term): array => ['property' => [['property' => $term, 'type' => 'nex']]],
                $terms
            );
        }
        return $groups;
    }

    /**
     * @param list<list<list<int>>> $idsPerGroup for each group, the ids of each branch
     * @return list<int> ascending
     */
    public static function combine(array $idsPerGroup): array
    {
        $result = null;
        foreach ($idsPerGroup as $branches) {
            $union = [];
            foreach ($branches as $ids) {
                foreach ($ids as $id) {
                    $union[(int) $id] = true;
                }
            }
            $result = null === $result ? $union : array_intersect_key($result, $union);
        }
        $ids = array_keys($result ?? []);
        sort($ids);
        return $ids;
    }
}
```

- [ ] **Step 4: Run test to verify it passes**

Run: `vendor/bin/phpunit -c test/phpunit.xml --filter IdConstraintsTest`
Expected: PASS (8 tests).

- [ ] **Step 5: Lint and commit**

```bash
make lint
git add src/Service/IdConstraints.php test/Service/IdConstraintsTest.php
git commit -m "feat: describe the queue and required-field unions as id constraints (TASK-070)"
```

---

### Task 2: `QueueCounter` takes the queue from `IdConstraints`

**Files:**
- Modify: `src/Service/Workflow/QueueCounter.php` (method `count()`)
- Test: `test/Service/Workflow/QueueCounterTest.php` (existing, must stay green unchanged)

**Interfaces:**
- Consumes: `IdConstraints::queueBranches()` (Task 1).
- Produces: `QueueCounter::count(): int`, unchanged.

- [ ] **Step 1: Confirm the existing test pins the two searches**

Run: `vendor/bin/phpunit -c test/phpunit.xml --filter QueueCounterTest`
Expected: PASS. `testCountsProposedAndPublicOutOfFlowWithoutHydrating` asserts the exact rows of both
searches, so it is the regression test for this refactor.

- [ ] **Step 2: Replace the inline definition**

In `count()`, replace everything from `$status = static fn` down to the end of the `$outOfFlow`
assignment with:

```php
        [$proposedBranch, $outOfFlowBranch] = IdConstraints::queueBranches();
        $proposed = $base + $proposedBranch;
        $outOfFlow = $base + $outOfFlowBranch;
```

Add `use OERManager\Service\IdConstraints;` and update the class docblock's last sentence to: «The
queue's definition lives in `IdConstraints::queueBranches()` (TASK-070), shared with the master
view's «En cola» filter.»

- [ ] **Step 3: Run the tests**

Run: `vendor/bin/phpunit -c test/phpunit.xml --filter 'QueueCounterTest|IdConstraintsTest'`
Expected: PASS.

- [ ] **Step 4: Lint and commit**

```bash
make lint
git add src/Service/Workflow/QueueCounter.php
git commit -m "refactor: the queue counter reads the queue's definition from IdConstraints (TASK-070)"
```

---

### Task 3: `ReaTemplateFields`, the required properties of the REA template

**Files:**
- Create: `src/Service/ReaTemplateFields.php`
- Modify: `config/module.config.php` (service factory, next to `Service\MasterViewQuery::class`)
- Test: `test/Service/ReaTemplateFieldsTest.php`

**Interfaces:**
- Produces: `ReaTemplateFields::requiredTerms(): list<string>`. Reads once per request; `[]` when the
  setting is unset or invalid, or the template cannot be read. Not `final`, so controllers can mock it.

- [ ] **Step 1: Write the failing test**

```php
<?php

declare(strict_types=1);

namespace OERManager\Test\Service;

use OERManager\Service\GovernanceSettings;
use OERManager\Service\ReaTemplateFields;
use Omeka\Api\Manager;
use Omeka\Api\Representation\PropertyRepresentation;
use Omeka\Api\Representation\ResourceTemplatePropertyRepresentation;
use Omeka\Api\Representation\ResourceTemplateRepresentation;
use Omeka\Api\Response;
use Omeka\Settings\Settings;
use PHPUnit\Framework\TestCase;

/** TASK-070: «Sin campo obligatorio» reads the REA template's required properties. */
final class ReaTemplateFieldsTest extends TestCase
{
    private function templateProperty(string $term, bool $required): ResourceTemplatePropertyRepresentation
    {
        $property = $this->createMock(PropertyRepresentation::class);
        $property->method('term')->willReturn($term);
        $templateProperty = $this->createMock(ResourceTemplatePropertyRepresentation::class);
        $templateProperty->method('isRequired')->willReturn($required);
        $templateProperty->method('property')->willReturn($property);
        return $templateProperty;
    }

    public function testListsOnlyRequiredTermsAndReadsOnce(): void
    {
        $template = $this->createMock(ResourceTemplateRepresentation::class);
        $template->method('resourceTemplateProperties')->willReturn([
            $this->templateProperty('dcterms:title', true),
            $this->templateProperty('dcterms:subject', false),
            $this->templateProperty('dcterms:license', true),
        ]);
        $api = $this->createMock(Manager::class);
        $api->expects($this->once())->method('read')
            ->with('resource_templates', 3)
            ->willReturn(new Response($template));
        $settings = new Settings();
        $settings->set(GovernanceSettings::REA_TEMPLATE_ID, '3');

        $fields = new ReaTemplateFields($api, $settings);

        $this->assertSame(['dcterms:title', 'dcterms:license'], $fields->requiredTerms());
        $this->assertSame(['dcterms:title', 'dcterms:license'], $fields->requiredTerms());
    }

    public function testNoTemplateConfiguredMeansNoTerms(): void
    {
        $api = $this->createMock(Manager::class);
        $api->expects($this->never())->method('read');

        $this->assertSame([], (new ReaTemplateFields($api, new Settings()))->requiredTerms());
    }

    public function testAnUnreadableTemplateMeansNoTerms(): void
    {
        $api = $this->createMock(Manager::class);
        $api->method('read')->willThrowException(new \RuntimeException('gone'));
        $settings = new Settings();
        $settings->set(GovernanceSettings::REA_TEMPLATE_ID, '99');

        $this->assertSame([], (new ReaTemplateFields($api, $settings))->requiredTerms());
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `vendor/bin/phpunit -c test/phpunit.xml --filter ReaTemplateFieldsTest`
Expected: FAIL, class not found.

- [ ] **Step 3: Write the implementation**

```php
<?php

declare(strict_types=1);

namespace OERManager\Service;

use Omeka\Api\Manager as ApiManager;
use Omeka\Settings\Settings;

/**
 * Required properties of the configured REA template (TASK-070), for the
 * master view's «Sin campo obligatorio» filter. Read once per request.
 * `[]` when no template is configured or it cannot be read: the filter is
 * then not offered and its parameter changes nothing.
 *
 * Not final so the controller tests can stand it in.
 */
class ReaTemplateFields
{
    /** @var list<string>|null */
    private ?array $required = null;

    public function __construct(
        private readonly ApiManager $api,
        private readonly Settings $settings
    ) {
    }

    /** @return list<string> */
    public function requiredTerms(): array
    {
        if (null !== $this->required) {
            return $this->required;
        }
        $terms = [];
        $id = GovernanceSettings::parseId($this->settings->get(GovernanceSettings::REA_TEMPLATE_ID));
        if (null !== $id) {
            try {
                $template = $this->api->read('resource_templates', $id)->getContent();
                foreach ($template->resourceTemplateProperties() as $templateProperty) {
                    if ($templateProperty->isRequired()) {
                        $terms[] = (string) $templateProperty->property()->term();
                    }
                }
            } catch (\Throwable $e) {
                $terms = [];
            }
        }
        return $this->required = array_values(array_unique($terms));
    }
}
```

- [ ] **Step 4: Register the service**

In `config/module.config.php`, under `service_manager` → `factories`, after the
`Service\MasterViewQuery::class` entry:

```php
            // TASK-070: «Sin campo obligatorio» in the master view.
            Service\ReaTemplateFields::class => function ($container) {
                return new Service\ReaTemplateFields(
                    $container->get('Omeka\ApiManager'),
                    $container->get('Omeka\Settings')
                );
            },
```

- [ ] **Step 5: Run the tests, lint and commit**

Run: `vendor/bin/phpunit -c test/phpunit.xml --filter 'ReaTemplateFieldsTest|ModuleConfigTest'`
Expected: PASS.

```bash
make lint
git add src/Service/ReaTemplateFields.php test/Service/ReaTemplateFieldsTest.php config/module.config.php
git commit -m "feat: read the REA template's required properties (TASK-070)"
```

---

### Task 4: `IdConstraintResolver`, from unions to ids

**Files:**
- Create: `src/Service/IdConstraintResolver.php`
- Modify: `config/module.config.php` (service factory, after `Service\ReaTemplateFields::class`)
- Test: `test/Service/IdConstraintResolverTest.php`

**Interfaces:**
- Consumes: `IdConstraints::groups()`, `IdConstraints::combine()` (Task 1);
  `ReaTemplateFields::requiredTerms()` (Task 3); `MasterViewQuery::buildSearchParams([])` for
  `resource_class_id`.
- Produces:
  - `IdConstraintResolver::resolve(array $query): ?array`: `null` = no constraint; otherwise a
    `list<int>`, possibly empty.
  - `IdConstraintResolver::hasRequiredFields(): bool`
  - Not `final` (mocked by the controller tests).

- [ ] **Step 1: Write the failing test**

```php
<?php

declare(strict_types=1);

namespace OERManager\Test\Service;

use OERManager\Service\IdConstraintResolver;
use OERManager\Service\IdConstraints;
use OERManager\Service\MasterViewQuery;
use OERManager\Service\ReaTemplateFields;
use Omeka\Api\Manager;
use Omeka\Api\Response;
use PHPUnit\Framework\TestCase;

/** TASK-070: each branch is an id-only search; groups intersect. */
final class IdConstraintResolverTest extends TestCase
{
    /** @var list<array<string,mixed>> */
    private array $searches = [];

    /** @param list<array<int,int>> $answers content of each id-only search, in order */
    private function resolver(array $answers, array $required = [], ?int $classId = 9): IdConstraintResolver
    {
        $api = $this->createMock(Manager::class);
        $api->method('search')->willReturnCallback(function ($resource, $query) use (&$answers) {
            $this->assertSame('items', $resource);
            $this->searches[] = $query;
            return new Response(array_shift($answers) ?? []);
        });
        $masterViewQuery = $this->createMock(MasterViewQuery::class);
        $masterViewQuery->method('buildSearchParams')->willReturn(['resource_class_id' => $classId]);
        $fields = $this->createMock(ReaTemplateFields::class);
        $fields->method('requiredTerms')->willReturn($required);
        return new IdConstraintResolver($api, $masterViewQuery, $fields);
    }

    public function testNoGroupMeansNoConstraintAndNoSearch(): void
    {
        $this->assertNull($this->resolver([])->resolve(['status' => 'proposed', 'title' => 'x']));
        $this->assertSame([], $this->searches);
    }

    public function testBranchSearchesCarryOnlyTheirOwnRows(): void
    {
        $ids = $this->resolver([[4 => 4, 2 => 2], [7 => 7]])
            ->resolve(['status' => 'queue', 'missing' => ['licence'], 'title' => 'x', 'item_set_id' => 3]);

        $this->assertSame([2, 4, 7], $ids);
        $this->assertCount(2, $this->searches);
        [$proposed, $outOfFlow] = IdConstraints::queueBranches();
        $this->assertSame(['resource_class_id' => 9, 'return_scalar' => 'id'] + $proposed, $this->searches[0]);
        $this->assertSame(['resource_class_id' => 9, 'return_scalar' => 'id'] + $outOfFlow, $this->searches[1]);
    }

    public function testGroupsIntersect(): void
    {
        $ids = $this->resolver(
            [[1 => 1, 2 => 2], [3 => 3], [2 => 2], [3 => 3, 8 => 8]],
            ['dcterms:title', 'dcterms:license']
        )->resolve(['status' => 'queue', 'missing' => ['required']]);

        $this->assertSame([2, 3], $ids);
        $this->assertSame([['property' => 'dcterms:license', 'type' => 'nex']], $this->searches[3]['property']);
    }

    public function testAnEmptyUnionIsAnEmptyListNotNull(): void
    {
        $this->assertSame([], $this->resolver([[], []])->resolve(['status' => 'queue']));
    }

    public function testNoLearningResourceClassMeansNoIds(): void
    {
        $this->assertSame([], $this->resolver([], [], null)->resolve(['status' => 'queue']));
        $this->assertSame([], $this->searches);
    }

    public function testRequiredFieldsAvailability(): void
    {
        $this->assertFalse($this->resolver([])->hasRequiredFields());
        $this->assertTrue($this->resolver([], ['dcterms:title'])->hasRequiredFields());
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `vendor/bin/phpunit -c test/phpunit.xml --filter IdConstraintResolverTest`
Expected: FAIL, class not found.

- [ ] **Step 3: Write the implementation**

```php
<?php

declare(strict_types=1);

namespace OERManager\Service;

use Omeka\Api\Manager as ApiManager;

/**
 * Resolves `IdConstraints` to item ids (TASK-070). Each branch is an id-only
 * search (`return_scalar => 'id'`: nothing is hydrated) carrying ONLY the REA
 * class and the branch: the master view's own filters go in the final search,
 * never here, because the core joins property rows without parentheses.
 *
 * Not final so the controller tests can stand it in.
 */
class IdConstraintResolver
{
    public function __construct(
        private readonly ApiManager $api,
        private readonly MasterViewQuery $masterViewQuery,
        private readonly ReaTemplateFields $templateFields
    ) {
    }

    /**
     * @param array<string,mixed> $query the master view's GET parameters
     * @return list<int>|null null when the request needs no constraint
     */
    public function resolve(array $query): ?array
    {
        $groups = IdConstraints::groups($query, $this->templateFields->requiredTerms());
        if ([] === $groups) {
            return null;
        }
        $classId = $this->masterViewQuery->buildSearchParams([])['resource_class_id'] ?? null;
        if (null === $classId) {
            return [];
        }
        $idsPerGroup = [];
        foreach ($groups as $branches) {
            $idsPerGroup[] = array_map(
                fn (array $branch): array => $this->ids((int) $classId, $branch),
                $branches
            );
        }
        return IdConstraints::combine($idsPerGroup);
    }

    public function hasRequiredFields(): bool
    {
        return [] !== $this->templateFields->requiredTerms();
    }

    /**
     * @param array<string,mixed> $branch
     * @return list<int>
     */
    private function ids(int $classId, array $branch): array
    {
        $content = $this->api->search(
            'items',
            ['resource_class_id' => $classId, 'return_scalar' => 'id'] + $branch
        )->getContent();
        return array_map('intval', array_values((array) $content));
    }
}
```

- [ ] **Step 4: Register the service**

In `config/module.config.php`, after the `Service\ReaTemplateFields::class` entry:

```php
            Service\IdConstraintResolver::class => function ($container) {
                return new Service\IdConstraintResolver(
                    $container->get('Omeka\ApiManager'),
                    $container->get(Service\MasterViewQuery::class),
                    $container->get(Service\ReaTemplateFields::class)
                );
            },
```

- [ ] **Step 5: Run the tests, lint and commit**

Run: `vendor/bin/phpunit -c test/phpunit.xml --filter 'IdConstraintResolverTest|ModuleConfigTest'`
Expected: PASS.

```bash
make lint
git add src/Service/IdConstraintResolver.php test/Service/IdConstraintResolverTest.php config/module.config.php
git commit -m "feat: resolve the queue and required-field unions to item ids (TASK-070)"
```

---

### Task 5: `MasterViewQuery` filters by age

**Files:**
- Modify: `src/Service/MasterViewQuery.php` (constructor, `buildSearchParams()`, new constant)
- Test: `test/Service/MasterViewQueryTest.php` (add two tests)

**Interfaces:**
- Produces:
  - `MasterViewQuery::AGE_DAYS = [7, 30, 90]`
  - constructor `__construct(ApiManager $api, ?\Closure $today = null)`; `$today` returns a
    `\DateTimeImmutable`, defaulting to `new \DateTimeImmutable('today')`. The existing factory
    (`new Service\MasterViewQuery($container->get('Omeka\ApiManager'))`) keeps working.

- [ ] **Step 1: Write the failing test**

Add to `MasterViewQueryTest` (it already has `apiResolvingClass()`):

```php
    /** TASK-070: «Creado hace más de» 7 / 30 / 90 days, on the catalogue entry date. */
    public function testAgeFilterSetsCreatedBefore(): void
    {
        $today = static fn (): \DateTimeImmutable => new \DateTimeImmutable('2026-11-24');
        $query = new MasterViewQuery($this->apiResolvingClass(), $today);

        $this->assertSame('2026-11-17', $query->buildSearchParams(['age' => '7'])['created_before']);
        $this->assertSame('2026-10-25', $query->buildSearchParams(['age' => '30'])['created_before']);
        $this->assertSame('2026-08-26', $query->buildSearchParams(['age' => '90'])['created_before']);
        foreach (['5', 'abc', '', ['7']] as $invalid) {
            $this->assertArrayNotHasKey('created_before', $query->buildSearchParams(['age' => $invalid]));
        }
    }

    /** TASK-070: `status=queue` and `missing[]=required` are resolved by IdConstraintResolver, not here. */
    public function testQueueAndRequiredAreNotNativeParameters(): void
    {
        $params = (new MasterViewQuery($this->apiResolvingClass()))
            ->buildSearchParams(['status' => 'queue', 'missing' => ['required']]);

        $this->assertArrayNotHasKey('property', $params);
        $this->assertArrayNotHasKey('is_public', $params);
    }
```

- [ ] **Step 2: Run test to verify it fails**

Run: `vendor/bin/phpunit -c test/phpunit.xml --filter MasterViewQueryTest`
Expected: FAIL in `testAgeFilterSetsCreatedBefore` (no `created_before`). The second test already
passes: it pins the current behaviour.

- [ ] **Step 3: Write the implementation**

Add the constant after `MAX_ITEM_SETS`:

```php
    /** «Creado hace más de» N days (TASK-070), on `o:created`. Other values are ignored. */
    public const AGE_DAYS = [7, 30, 90];
```

Replace the constructor:

```php
    private \Closure $today;

    public function __construct(ApiManager $api, ?\Closure $today = null)
    {
        $this->api = $api;
        $this->today = $today ?? static fn (): \DateTimeImmutable => new \DateTimeImmutable('today');
    }
```

In `buildSearchParams()`, right after the `visibility` block:

```php
        // TASK-070: age on the catalogue entry date; the core compares `created < day`.
        $age = $query['age'] ?? null;
        if (is_string($age) && ctype_digit($age) && in_array((int) $age, self::AGE_DAYS, true)) {
            $params['created_before'] = ($this->today)()
                ->modify(sprintf('-%d days', (int) $age))
                ->format('Y-m-d');
        }
```

- [ ] **Step 4: Run the tests**

Run: `vendor/bin/phpunit -c test/phpunit.xml --filter MasterViewQueryTest`
Expected: PASS.

- [ ] **Step 5: Lint and commit**

```bash
make lint
git add src/Service/MasterViewQuery.php test/Service/MasterViewQueryTest.php
git commit -m "feat: filter the master view by catalogue age (TASK-070)"
```

---

### Task 6: `indexAction()` applies the ids

**Files:**
- Modify: `src/Controller/Admin/IndexController.php` (constructor, `indexAction()`, `searchAction()`)
- Modify: `config/module.config.php` (the `Controller\Admin\IndexController::class` factory: add the
  new last argument)
- Test: `test/Controller/IndexControllerTest.php`

**Interfaces:**
- Consumes: `IdConstraintResolver::resolve()`, `IdConstraintResolver::hasRequiredFields()` (Task 4).
- Produces: view variable `requiredFieldsAvailable` (bool) for `search.phtml` (Task 7).

- [ ] **Step 1: Write the failing tests**

In `IndexControllerTest`:

1. Add a property `private array $searches = [];` and replace the `search` stub in `setUp()` with:

```php
        $this->api->method('search')->willReturnCallback(function ($resource, $query = []) {
            $this->searches[] = $query;
            return new Response([$this->item], 1);
        });
```

2. Add the tests. The constructor is built by reflection, so the new `IdConstraintResolver` argument is
   a mock in `$this->dependencies['idConstraintResolver']`. A mock returns `null` by default, which
   keeps every existing test on today's path.

```php
    /** TASK-070: ids from the resolver bound the page search. */
    public function testConstrainedIdsBoundThePageSearch(): void
    {
        $this->dependencies['idConstraintResolver']->method('resolve')->willReturn([7, 9]);

        $view = $this->controller->indexAction();

        $this->assertSame([$this->item], $view->getVariable('items'));
        $this->assertSame([7, 9], end($this->searches)['id']);
    }

    /** TASK-070: the core ignores `id => []` and would list every REA. */
    public function testAnEmptyIdSetShowsNoResultsWithoutSearching(): void
    {
        $this->dependencies['idConstraintResolver']->method('resolve')->willReturn([]);

        $view = $this->controller->indexAction();

        $this->assertSame([], $view->getVariable('items'));
        $this->assertSame(0, $view->getVariable('totalResults'));
        $this->assertSame([], $this->searches);
    }

    public function testTheSearchFormKnowsWhetherRequiredFieldsExist(): void
    {
        $this->dependencies['idConstraintResolver']->method('hasRequiredFields')->willReturn(true);

        $this->assertTrue($this->controller->searchAction()->getVariable('requiredFieldsAvailable'));
    }
```

- [ ] **Step 2: Run the tests to verify they fail**

Run: `vendor/bin/phpunit -c test/phpunit.xml --filter IndexControllerTest`
Expected: FAIL. The three new tests fail (unknown dependency `idConstraintResolver`); the rest pass.

- [ ] **Step 3: Inject the resolver**

In `IndexController`, add `use OERManager\Service\IdConstraintResolver;`, a property
`private IdConstraintResolver $idConstraintResolver;`, a last constructor parameter
`IdConstraintResolver $idConstraintResolver` and its assignment
`$this->idConstraintResolver = $idConstraintResolver;`. In the factory in `config/module.config.php`,
add the matching last argument:

```php
                    $container->get(Service\IdConstraintResolver::class)
```

- [ ] **Step 4: Apply the ids in `indexAction()`**

Right after `$searchParams = $this->masterViewQuery->buildSearchParams($query);`:

```php
        // TASK-070: «En cola» and «Sin campo obligatorio» are unions the core
        // cannot search in one query; the resolver turns them into ids.
        $constrainedIds = $this->idConstraintResolver->resolve($query);
        if (null !== $constrainedIds) {
            $searchParams['id'] = $constrainedIds;
        }
```

Then turn the existing `if ([] !== $computedKeys) {` into the second branch of:

```php
        if ([] === $constrainedIds) {
            // The core ignores an empty `id` filter and would list every REA.
            $items = [];
            $this->paginator(0);
            $totalResults = 0;
            $isTruncated = false;
        } elseif ([] !== $computedKeys) {
```

leaving both existing branches (computed and plain) exactly as they are. `null === $constrainedIds`
never equals `[]`, so requests without a constraint take the same path as today.

- [ ] **Step 5: Expose `requiredFieldsAvailable` in `searchAction()`**

Before `return $view;` in `searchAction()`:

```php
        // TASK-070: «Sin campo obligatorio» is offered only with a readable REA template.
        $view->setVariable('requiredFieldsAvailable', $this->idConstraintResolver->hasRequiredFields());
```

- [ ] **Step 6: Run the tests, lint and commit**

Run: `vendor/bin/phpunit -c test/phpunit.xml --filter 'IndexControllerTest|ModuleConfigTest'`
Expected: PASS.

```bash
make lint
git add src/Controller/Admin/IndexController.php config/module.config.php test/Controller/IndexControllerTest.php
git commit -m "feat: the master view applies the queue and required-field ids (TASK-070)"
```

---

### Task 7: Views, filter chips and «Cola (N)»

**Files:**
- Modify: `view/oer-manager/admin/index/index.phtml` (status options; hidden `age`)
- Modify: `view/oer-manager/admin/index/search.phtml` (status options; «Sin campo obligatorio»; age)
- Modify: `Module.php` (`SEARCH_FILTER_LABELS`, `FILTER_VALUE_LABELS`, `MISSING_FILTER_LABELS`)
- Modify: `config/module.config.php` (navigation entry `oer-queue`)
- Test: `test/ModuleRuntimeTest.php`, `test/ModuleConfigTest.php`

**Interfaces:**
- Consumes: `requiredFieldsAvailable` (Task 6); `MasterViewQuery::AGE_DAYS` (Task 5).

- [ ] **Step 1: Write the failing tests**

In `test/ModuleRuntimeTest.php`, next to `testSearchFilterChipsResolveTitlesAndKeepMissingIds`:

```php
    /** TASK-070: the queue, required-field and age filters have readable chips. */
    public function testQueueRequiredAndAgeChips(): void
    {
        $event = new Event();
        $event->setParam('filters', []);
        $event->setParam('query', ['status' => 'queue', 'missing' => ['required'], 'age' => '30']);
        $this->module(['Omeka\ApiManager' => $this->createMock(Manager::class)])->addSearchFilters($event);
        $filters = $event->getParam('filters');

        $this->assertSame(['En cola'], $filters['Estado']);
        $this->assertSame(['Sin campo obligatorio'], $filters['Gobernanza']);
        $this->assertSame(['Creado hace más de 30 días'], $filters['Antigüedad']);
    }
```

In `test/ModuleConfigTest.php`:

```php
    /** TASK-070: «Cola (N)» opens the master view filtered by the queue, oldest first. */
    public function testQueueEntryOpensTheQueueOldestFirst(): void
    {
        $pages = $this->config['navigation']['AdminModule'][0]['pages'];
        $queue = array_values(array_filter(
            $pages,
            static fn (array $page): bool => 'oer-queue' === ($page['id'] ?? null)
        ));

        $this->assertSame(
            ['status' => 'queue', 'sort_by' => 'created', 'sort_order' => 'asc'],
            $queue[0]['query']
        );
    }
```

- [ ] **Step 2: Run the tests to verify they fail**

Run: `vendor/bin/phpunit -c test/phpunit.xml --filter 'testQueueRequiredAndAgeChips|testQueueEntryOpensTheQueueOldestFirst'`
Expected: FAIL (missing chip keys; navigation query is still `['status' => 'proposed']`).

- [ ] **Step 3: Chips in `Module.php`**

- In `SEARCH_FILTER_LABELS`, after `'status' => 'Estado', // @translate`, add
  `'age' => 'Antigüedad', // @translate`.
- In `FILTER_VALUE_LABELS['status']`, add as the **first** entry
  `'queue' => 'En cola', // @translate`.
- Add to `FILTER_VALUE_LABELS`:

```php
        'age' => [
            '7' => 'Creado hace más de 7 días', // @translate
            '30' => 'Creado hace más de 30 días', // @translate
            '90' => 'Creado hace más de 90 días', // @translate
        ],
```

- In `MISSING_FILTER_LABELS`, add last `'required' => 'Sin campo obligatorio', // @translate`.

- [ ] **Step 4: Navigation in `config/module.config.php`**

In the `oer-queue` page, replace `'query' => ['status' => 'proposed'],` with:

```php
                        'query' => ['status' => 'queue', 'sort_by' => 'created', 'sort_order' => 'asc'],
```

and replace the comment above it with:

```php
                    // «Cola (N)» (ADR-0023 §3): Module::labelQueue() writes N
                    // before rendering. TASK-070: the master view's «En cola»
                    // filter, oldest catalogue entry first.
```

- [ ] **Step 5: Status options in both views**

In `view/oer-manager/admin/index/index.phtml` and in `view/oer-manager/admin/index/search.phtml`, add
as the **first** entry of `$statusOptions`:

```php
            'queue' => $translate('En cola'),
```

- [ ] **Step 6: Keep `age` from the quick bar**

In `index.phtml`, add `'age'` to the hidden pass-through list:

```php
    foreach (['stage', 'subject', 'project', 'axis', 'resource_type', 'licence', 'item_set_id', 'age'] as $advanced):
```

- [ ] **Step 7: «Sin campo obligatorio» and age in the advanced search**

In `search.phtml`, right after the `$missingLabels = [...]` array:

```php
    // TASK-070: only with a readable REA template (IdConstraintResolver).
    if (!empty($requiredFieldsAvailable)) {
        $missingLabels['required'] = $translate('Sin campo obligatorio');
    }
```

Add `@var bool $requiredFieldsAvailable` to the file's docblock if it lists its variables. Then, after
the «Flujo de propuesta» `field` block and before the «Gobernanza» one, add:

```php
    <?php $currentAge = (string) ($query['age'] ?? ''); ?>
    <div class="field">
        <div class="field-meta">
            <label for="oer-search-age"><?php echo $translate('Antigüedad'); ?></label>
        </div>
        <div class="inputs">
            <select id="oer-search-age" name="age">
                <option value=""><?php echo $translate('Cualquier fecha de alta'); ?></option>
                <?php foreach (\OERManager\Service\MasterViewQuery::AGE_DAYS as $days): ?>
                <option value="<?php echo $days; ?>" <?php echo (string) $days === $currentAge ? 'selected' : ''; ?>>
                    <?php echo $escape(sprintf($translate('Creado hace más de %d días'), $days)); ?>
                </option>
                <?php endforeach; ?>
            </select>
        </div>
    </div>
```

- [ ] **Step 8: Run the full PHP suite, lint and commit**

Run: `make test`
Expected: PASS.

```bash
make lint
git add Module.php config/module.config.php view/oer-manager/admin/index/index.phtml \
    view/oer-manager/admin/index/search.phtml test/ModuleRuntimeTest.php test/ModuleConfigTest.php
git commit -m "feat: «En cola», «Sin campo obligatorio» and age filters in the master view (TASK-070)"
```

---

### Task 8: «Siguiente» after a workflow action

**Files:**
- Create: `asset/js/core/queueNav.js`
- Create: `asset/js/ui/queueFlow.js`
- Modify: `asset/js/ui/workflowDrawer.js` (export the event; dispatch it on success)
- Modify: `asset/js/main.js` (wire `initQueueFlow()`)
- Modify: `asset/css/oer-master-view.css` (done row, button)
- Modify: `config/module.config.php` (`js_translate_strings`)
- Test: `test/js/queueNav.test.js`

**Interfaces:**
- Produces:
  - `core/queueNav.js`: `QUEUE_ACTIONS`, `actionOf(formAction: string): string`,
    `leavesQueue(action: string): boolean`, `nextItemId(ids: string[], currentId): string|null`,
    `lowerCount(label: string): string`
  - `ui/workflowDrawer.js`: `export const WORKFLOW_CHANGED = 'oer:workflow-changed'`, detail
    `{ itemId: string, action: string, status: string|null }`
  - `ui/queueFlow.js`: `initQueueFlow(): void`
- Consumes: `DRAWER_RENDERED` from `ui/drawer.js` (detail `{ itemId, content }`).

- [ ] **Step 1: Write the failing test**

```js
import { test } from 'node:test';
import assert from 'node:assert/strict';
import { actionOf, leavesQueue, nextItemId, lowerCount } from '../../asset/js/core/queueNav.js';

test('la acción es el último tramo de la URL del formulario', () => {
    assert.equal(actionOf('https://x.test/admin/oer-manager/publish-proposal'), 'publish-proposal');
    assert.equal(actionOf('/admin/oer-manager/discard/?a=1'), 'discard');
    assert.equal(actionOf(''), '');
});

test('publicar, rechazar y descartar sacan el REA de la cola; el resto no', () => {
    ['publish-proposal', 'reject-proposal', 'discard'].forEach((action) => assert.ok(leavesQueue(action)));
    ['propose', 'unpublish', 'reopen', ''].forEach((action) => assert.ok(!leavesQueue(action)));
});

test('el siguiente REA de la página, o null al final', () => {
    const ids = ['4', '9', '2'];
    assert.equal(nextItemId(ids, '4'), '9');
    assert.equal(nextItemId(ids, 9), '2');
    assert.equal(nextItemId(ids, '2'), null);
    assert.equal(nextItemId(ids, '77'), null);
    assert.equal(nextItemId(['4'], '4'), null);
    assert.equal(nextItemId([], '4'), null);
});

test('«Cola (N)» baja en uno y nunca de cero', () => {
    assert.equal(lowerCount('Cola (12)'), 'Cola (11)');
    assert.equal(lowerCount('Queue (1)'), 'Queue (0)');
    assert.equal(lowerCount('Cola (0)'), 'Cola (0)');
    assert.equal(lowerCount('Cola'), 'Cola');
});
```

- [ ] **Step 2: Run test to verify it fails**

Run: `node --test test/js/queueNav.test.js`
Expected: FAIL, `Cannot find module …/asset/js/core/queueNav.js`.

- [ ] **Step 3: Write `asset/js/core/queueNav.js`**

```js
/**
 * Working through the list after a workflow action (TASK-070). Pure: no DOM,
 * so `node --test` covers it; `ui/queueFlow.js` applies it to the page.
 */

/** Actions that take a REA out of the queue (ADR-0023 §2). */
export const QUEUE_ACTIONS = ['publish-proposal', 'reject-proposal', 'discard'];

/** Each panel form posts to `…/oer-manager/<action>`. */
export function actionOf(formAction) {
    const path = String(formAction || '').split('?')[0].replace(/\/+$/, '');
    return path.slice(path.lastIndexOf('/') + 1);
}

export function leavesQueue(action) {
    return QUEUE_ACTIONS.includes(action);
}

/**
 * @param {string[]} ids item ids of the page, in display order
 * @param {string|number} currentId
 * @returns {string|null} null on the last row or when the item is not listed
 */
export function nextItemId(ids, currentId) {
    const at = ids.indexOf(String(currentId));
    if (at < 0 || at === ids.length - 1) {
        return null;
    }
    return ids[at + 1];
}

/** «Cola (12)» → «Cola (11)»; a label without a trailing count is kept. */
export function lowerCount(label) {
    return String(label).replace(/\((\d+)\)\s*$/, (match, count) => `(${Math.max(0, Number(count) - 1)})`);
}
```

- [ ] **Step 4: Run test to verify it passes**

Run: `node --test test/js/queueNav.test.js`
Expected: PASS (4 tests).

- [ ] **Step 5: Announce the action in `ui/workflowDrawer.js`**

Add the import and the exported constant at the top of the file:

```js
import { actionOf } from '../core/queueNav.js';

/** A workflow action succeeded in the panel (TASK-070). */
export const WORKFLOW_CHANGED = 'oer:workflow-changed';
```

In the submit handler, between the `if (!result.updated) { … return; }` block and
`if (itemId) { reopenDrawer(itemId); }`, add:

```js
                document.dispatchEvent(new CustomEvent(WORKFLOW_CHANGED, {
                    detail: { itemId, action: actionOf(form.action), status: result.status || null }
                }));
```

The event goes out before `reopenDrawer()`, so `queueFlow.js` is listening when the panel re-renders.

- [ ] **Step 6: Write `asset/js/ui/queueFlow.js`**

```js
import { DRAWER_RENDERED } from './drawer.js';
import { WORKFLOW_CHANGED } from './workflowDrawer.js';
import { leavesQueue, nextItemId, lowerCount } from '../core/queueNav.js';

/**
 * TASK-070: after «Confirmar y publicar», «Rechazar» or «Descartar» the row is
 * marked with its new state, «Cola (N)» drops by one, and the re-rendered
 * panel offers the next REA of the page («Siguiente»), or «Recargar la
 * lista» on the last row. Works under any filter, not only «En cola».
 */
const TABLE = '#oer-master-view-table';
// The «Cola (N)» entry of the admin navigation. Its href carries the queue
// filter (config/module.config.php, page `oer-queue`).
const QUEUE_LINK = '#menu a[href*="status=queue"]';

function rowIds() {
    return Array.from(document.querySelectorAll(`${TABLE} .oer-open-drawer[data-item-id]`))
        .map((opener) => opener.dataset.itemId);
}

function markRow(itemId, status) {
    const row = document.querySelector(`${TABLE} tr[data-resource-id="${itemId}"]`);
    if (!row) {
        return;
    }
    row.classList.add('oer-row-done');
    let note = row.querySelector('.oer-row-done-status');
    if (!note) {
        note = document.createElement('span');
        note.className = 'oer-row-done-status';
        const opener = row.querySelector('.oer-open-drawer');
        if (opener) {
            opener.after(note);
        }
    }
    note.textContent = status ? Omeka.jsTranslate(status) : '';
}

function lowerQueueLabel() {
    document.querySelectorAll(QUEUE_LINK).forEach((link) => {
        link.textContent = lowerCount(link.textContent);
    });
}

function nextButton(itemId) {
    const next = nextItemId(rowIds(), itemId);
    const button = document.createElement('button');
    button.type = 'button';
    button.className = 'button oer-queue-next';
    if (null === next) {
        button.textContent = Omeka.jsTranslate('Recargar la lista');
        button.addEventListener('click', () => window.location.reload());
        return button;
    }
    button.textContent = Omeka.jsTranslate('Siguiente');
    button.addEventListener('click', () => {
        // The row's own trigger: the core opens the panel as for any click.
        const opener = document.querySelector(`${TABLE} .oer-open-drawer[data-item-id="${next}"]`);
        if (opener) {
            opener.click();
        }
    });
    return button;
}

export function initQueueFlow() {
    let pending = null;

    document.addEventListener(WORKFLOW_CHANGED, (event) => {
        const { itemId, action, status } = event.detail;
        if (!itemId || !leavesQueue(action)) {
            return;
        }
        markRow(itemId, status);
        lowerQueueLabel();
        pending = String(itemId);
    });

    document.addEventListener(DRAWER_RENDERED, (event) => {
        const { itemId, content } = event.detail;
        if (null === pending || String(itemId) !== pending) {
            return;
        }
        pending = null;
        const workflow = content.querySelector('.oer-panel-workflow');
        const button = nextButton(itemId);
        if (workflow) {
            workflow.appendChild(button);
        } else {
            content.prepend(button);
        }
        button.focus();
    });
}
```

Before relying on `QUEUE_LINK`, open the admin with the browser's inspector and confirm that the
navigation's container is `#menu`. If it is not, change only that selector.

- [ ] **Step 7: Wire it in `asset/js/main.js`**

Add `import { initQueueFlow } from './ui/queueFlow.js';` after the `initWorkflowDrawer` import, and
`initQueueFlow();` right after `initWorkflowDrawer();`.

- [ ] **Step 8: Styles in `asset/css/oer-master-view.css`**

Append:

```css
/* TASK-070: a row the curator has just published, rejected or discarded. */
#oer-master-view-table tr.oer-row-done > td {
    opacity: 0.55;
}

#oer-master-view-table .oer-row-done-status {
    margin-inline-start: 0.5em;
    font-style: italic;
}

.oer-queue-next {
    margin-block-start: 1rem;
}
```

- [ ] **Step 9: Register the JS strings**

In `config/module.config.php`, at the end of `js_translate_strings`:

```php
        // TASK-070: «Siguiente» after a workflow action, and the row's new state.
        'Siguiente', // @translate
        'Recargar la lista', // @translate
        'Publicado', // @translate
        'Rechazado', // @translate
        'Descartado', // @translate
```

Skip any of the three status labels that the array already lists.

- [ ] **Step 10: Run the checks and commit**

Run: `make test-js && make test && make lint`
Expected: all PASS. `test/js/panelStylesheets.test.js` still passes: no view changed its stylesheets.

```bash
git add asset/js/core/queueNav.js asset/js/ui/queueFlow.js asset/js/ui/workflowDrawer.js asset/js/main.js \
    asset/css/oer-master-view.css config/module.config.php test/js/queueNav.test.js
git commit -m "feat: mark the row and offer «Siguiente» after a workflow action (TASK-070)"
```

---

### Task 9: Container check and manual verification

**Files:**
- Create: `test/container/queue-check.php`

**Interfaces:**
- Consumes: `IdConstraintResolver::resolve()`, `QueueCounter::count()`, `WorkflowStatus::inQueue()`,
  `WorkflowStatus::normalize()`, `MasterViewQuery::buildSearchParams()`, `curAuthenticate()` from
  `test/container/cur-fixture.php`.

- [ ] **Step 1: Write the read-only harness**

```php
<?php
// CLI only: these harnesses bootstrap Omeka, so they must never run over HTTP.
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

/**
 * TASK-070, read-only: «En cola» returns exactly the REA that
 * WorkflowStatus::inQueue() accepts, and as many as «Cola (N)» counts.
 * Writes nothing. Run on a development or disposable catalogue only.
 *
 * Use, inside the container:
 *   php /var/www/html/modules/OERManager/test/container/queue-check.php <userEmail>
 *
 * Exit 1 if a check fails.
 */

require '/var/www/html/bootstrap.php';

$application = Omeka\Mvc\Application::init(require '/var/www/html/application/config/application.config.php');
$services = $application->getServiceManager();
require __DIR__ . '/cur-fixture.php';

$email = (string) ($argv[1] ?? '');
if ('' === $email) {
    fwrite(STDERR, "usage: queue-check.php <userEmail>\n");
    exit(2);
}
curAuthenticate($services, $email);

$api = $services->get('Omeka\ApiManager');
$resolver = $services->get(OERManager\Service\IdConstraintResolver::class);
$counter = $services->get(OERManager\Service\Workflow\QueueCounter::class);
$base = $services->get(OERManager\Service\MasterViewQuery::class)->buildSearchParams([]);
$failed = 0;
$check = static function (bool $ok, string $label) use (&$failed): void {
    echo '  ' . ($ok ? 'OK  ' : 'FAIL') . ' ' . $label . "\n";
    $failed += $ok ? 0 : 1;
};

// Reference: every REA, decided one by one with the same rule the panel uses.
$expected = [];
foreach ($api->search('items', $base)->getContent() as $item) {
    $statusValue = $item->value(OERManager\Service\Workflow\WorkflowStatus::STATUS_TERM);
    $status = null === $statusValue
        ? null
        : OERManager\Service\Workflow\WorkflowStatus::normalize((string) $statusValue->value());
    if (OERManager\Service\Workflow\WorkflowStatus::inQueue($status, $item->isPublic())) {
        $expected[] = (int) $item->id();
    }
}
sort($expected);

$queue = $resolver->resolve(['status' => 'queue']);
$check(is_array($queue), 'status=queue is a constraint');
$check($queue === $expected, sprintf('En cola = inQueue() (%d REA)', count($expected)));
$check(count($expected) === $counter->count(), sprintf('Cola (N) = %d', $counter->count()));
$check(null === $resolver->resolve(['status' => 'proposed']), 'status=proposed needs no constraint');

exit($failed > 0 ? 1 : 0);
```

- [ ] **Step 2: Run it in the development container**

Run (as a `rea_curador` user of the development catalogue, never the live one):

```bash
docker exec <omeka-container> php /var/www/html/modules/OERManager/test/container/queue-check.php <curator-email>
```

Expected: four `OK` lines and exit 0. Paste the output into the PR.

- [ ] **Step 3: Manual check in the admin**

As a `rea_curador` who is not an administrator, after a hard reload:
1. «Cola (N)» opens the master view with «Estado: En cola», oldest first, and its total equals N.
2. Combine «En cola» with «Sin licencia», a collection and «Creado hace más de 30 días»: the chips
   show all of them and the totals shrink.
3. In the panel, «Confirmar y publicar» one REA: the row is greyed with «Publicado», N drops by one,
   and «Siguiente» opens the next row. Repeat with «Rechazar» and «Descartar».
4. On the last row the button reads «Recargar la lista»; after the reload the finished REA are gone.
5. With no REA template configured, «Sin campo obligatorio» is not offered.

- [ ] **Step 4: Commit**

```bash
git add test/container/queue-check.php
git commit -m "test: container check that «En cola» matches the queue rule and its counter (TASK-070)"
```

---

### Task 10: Close the task in the governance docs

**Files:**
- Modify: `docs/backlog.md` (TASK-070 row: status and a dated closing note)
- Modify: `docs/traceability.md` (the TASK-070 line)
- Modify: `docs/project-memory.md` (one dated entry with the lessons)

- [ ] **Step 1: Update the docs**

- `docs/backlog.md`: in the TASK-070 row, set the status to `hecha` and append a dated note: what was
  built (the four filters, «Siguiente», the navigation), the test counts, the `queue-check.php` output
  and the manual check. Keep TASK-065 open until curators have tried the queue (1.0 plan).
- `docs/traceability.md`: point the TASK-070 line at this plan, the spec and `queue-check.php`.
- `docs/project-memory.md`: a dated entry with what was non-obvious (id sets instead of one search;
  the core's empty `id` filter; branch rows never mixed with the master view's rows).

- [ ] **Step 2: Final verification**

Run: `make lint && make test && make test-js`
Expected: all PASS.

- [ ] **Step 3: Commit and open the PR**

```bash
git add docs/backlog.md docs/traceability.md docs/project-memory.md
git commit -m "docs: close TASK-070 (backlog, traceability, project memory)"
git push -u origin feature/task-070-curation-queue
```

Open a PR against `main` with: summary, test counts, the `queue-check.php` output and the manual
check list from Task 9.
