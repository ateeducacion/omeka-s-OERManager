# TASK-028 slice 4 — batch licence and authorship Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Let a curator assign licence, authorship, publisher and rights holder to many REA at once
(ticked rows or every REA matching the master view's filter), in Fill-empty or Replace mode, executed
as an Omeka Job with preview, confirmation, progress, cancel and reattach.

**Architecture:** A synchronous preview resolves the target set server-side, counts values with
cheap API queries and freezes a one-shot plan in a private file store. Apply turns the plan into a
`GovernanceBatchJob`; a pure runner loops over the ids, calling the existing
`GovernanceService::apply()` per item (now with `onlyEmpty`, `batch` and `reread` options), and
reports progress through the job-state store the AI propose already uses. A new
`GovernanceBatchController` owns the five endpoints; the UI is a new `ui/governanceBatch.js` in the
master view's batch bar, reusing the governance field widgets extracted from `ui/governance.js`.

**Tech Stack:** PHP 8.4 / Omeka S 4.2 module (Laminas MVC), PHPUnit 11 on the host with the stubs in
`test/stubs/`, ES modules with `node --test`, container harness scripts in `test/container/`.

**Spec:** `docs/superpowers/specs/2026-09-24-task-028-slice-4-batch-governance-design.md`

## Global Constraints

- Target PHP 8.4; new PHP files start with `declare(strict_types=1);`; PSR-12 (`make lint`).
- Never patch Omeka core; no Doctrine tables (NFR-002). Plans and job state are private JSON files.
- Design for the production catalogue of about **3000 REA**; the container holds a ~19-REA sample.
- Batch fields: `dcterms:license`, `dcterms:creator`, `dcterms:publisher`, `dcterms:rightsHolder`. Never `dcterms:source`.
- Modes: `fill` (default) and `replace`. No append mode.
- `BATCH_MAX = 5000` ids per plan. Plan TTL 1800 s, one-shot. Progress every 25 items; `EntityManager::clear()` every 50.
- Batch id in each event: `batch-<jobId>`. Per-item undo unchanged.
- ACL: exactly the roles that hold `governance-apply`: `editor`, `site_admin`, `reviewer` (and `global_admin` via core).
- «All matching» refused when a computed filter's base search exceeds `ComputedFilter::HARD_CAP` (2000).
- Errors never leak exception text to the client (I2); unexpected detail goes to the Omeka logger.
- Every UI string through `Omeka.jsTranslate()` / `$translate` with `/* @translate */` or `// @translate`.
- Keep the owner's `Makefile` unchanged. Run `make lint`, `make test`, `make test-js` on the host.
- Container harness writes only to fixtures it creates and deletes; never to catalogue items.
- Commit messages end with `Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>`.

## Deviations from the spec (flagged for owner review)

1. **Own controller and route.** Spec §7 puts the five actions in `IndexController` under
   `admin/oer-manager`. `IndexController` already has 20 constructor arguments and 1331 lines; the plan
   adds `Controller\Admin\GovernanceBatchController` on a sibling route `admin/oer-manager-batch`
   (`/oer-manager/batch[/:action]`), the precedent set by `StatsController`. ACL, privileges and roles
   are unchanged from the spec.
2. **`returnScalar` is an API option, not a query parameter.** Spec §7 writes `return_scalar=id`; in
   Omeka S 4 the id-only search is `$api->search('items', $query, ['returnScalar' => 'id'])`. The
   harness still verifies it first, with the paginated fallback.
3. **A ticked field needs a value.** Not stated in the spec: a ticked field with an empty value is a
   `required` error. Otherwise Replace with an empty licence would clear it on thousands of REA —
   clearing is not an assignment and stays out of this slice.

## Review Focus

- Crafted plan token (`../x`, empty, non-hex) reaching `BatchPlanStore` → rejected before touching the filesystem (Task 5).
- `lrmi:LearningResource` class unresolved → `buildSearchParams()` has `resource_class_id => null`, which would match the whole catalogue → selection refused with `class_unresolved` (Task 6).
- Posted `ids[]` with duplicates, zeros, strings or ids of non-REA items → cast, deduplicated, and constrained to the REA class server-side (Task 6).
- Status or cancel for another user's job, or for an AI-propose job id → `not_found` (native job ACL) or `not_batch` (Task 9).
- Ticked field with an empty value in Replace mode → `required`, nothing written (Task 4).

---

## File Structure

| File | Responsibility |
| --- | --- |
| `src/Service/ComputedPredicates.php` (modify) | Gains `predicate()`, moved out of `IndexController` so the batch selection reuses it |
| `src/Service/CurationEvent.php` (modify) | `buildTyped()` accepts an optional batch id |
| `src/Service/GovernanceService.php` (modify) | `apply()` gains `onlyEmpty`, `batch`, `reread`; new `formOptions()` |
| `src/Service/Governance/BatchRequest.php` (create) | Pure validation of the curator's batch request |
| `src/Service/Governance/BatchPlan.php` (create) | Immutable plan + preview summary |
| `src/Service/Governance/BatchPlanStore.php` (create) | One-shot, TTL-bound, owner-checked plan files |
| `src/Service/Governance/BatchSelection.php` (create) | Resolves the target id set and counts existing values |
| `src/Service/Governance/BatchSelectionException.php` (create) | Selection refusal with a code |
| `src/Service/Governance/GovernanceBatchRunner.php` (create) | Pure loop: progress, cancel, flush, tallies, failure codes |
| `src/Service/Governance/BatchProgressReporter.php` (create) | `ProgressReporter` writing batch-shaped state to the job-state store |
| `src/Job/GovernanceBatchJob.php` (create) | Wires the runner to the services |
| `src/Controller/Admin/GovernanceBatchController.php` (create) | Five actions |
| `view/oer-manager/admin/governance-batch/form.phtml` (create) | Sidebar form partial |
| `config/module.config.php`, `Module.php` (modify) | Factories, route, ACL |
| `asset/js/ui/governanceFields.js` (create) | Field widgets extracted from `ui/governance.js` |
| `asset/js/core/governanceBatchModel.js` (create) | Pure batch model |
| `asset/js/ui/governanceBatch.js` (create) | Selection strip, form, preview, progress, reattach |
| `asset/js/config.js`, `asset/js/main.js`, `view/oer-manager/admin/index/index.phtml`, `asset/css/oer-master-view.css`, `IndexController::indexAction` (modify) | Wiring |
| `test/container/governance-batch-check.php` (create) | Container harness on disposable fixtures |
| `docs/…` (modify) | ADR-0020 addendum, backlog, traceability, requirements, project memory |

---

### Task 1: Move the computed predicate into `ComputedPredicates`

**Files:**
- Modify: `src/Service/ComputedPredicates.php`
- Modify: `src/Controller/Admin/IndexController.php` (method `computedPredicate()` around line 215, its call in `indexAction()`, and the now-unused `use OERManager\ColumnType\AlignmentStatus;` if nothing else uses it)
- Test: `test/Service/ComputedPredicatesTest.php`

**Interfaces:**
- Produces: `ComputedPredicates::predicate(array $keys, array $candidates, array $query, IntegrityChecker $checker): callable` — `callable(int $id): bool`, AND of every active key.

- [ ] **Step 1: Write the failing tests** (append to `test/Service/ComputedPredicatesTest.php`; add the `use` lines at the top of the file if missing)

```php
use OERManager\Service\IntegrityChecker;
use OERManager\Service\IntegrityResult;
use Omeka\Api\Representation\ItemRepresentation;

    public function testPredicateMatchesTheRequestedIntegrityStatus(): void
    {
        $ok = $this->createMock(ItemRepresentation::class);
        $warn = $this->createMock(ItemRepresentation::class);
        $checker = $this->createMock(IntegrityChecker::class);
        $checker->method('check')->willReturnCallback(
            fn ($item) => new IntegrityResult($item === $warn
                ? [['severity' => 'warning', 'code' => 'missing_license', 'field' => 'dcterms:license', 'message' => 'x']]
                : [])
        );
        $predicate = ComputedPredicates::predicate(
            [ComputedPredicates::INTEGRITY],
            [1 => $ok, 2 => $warn],
            ['integrity' => 'warning'],
            $checker
        );

        $this->assertFalse($predicate(1));
        $this->assertTrue($predicate(2));
    }

    public function testNoKeysMatchesEverything(): void
    {
        $predicate = ComputedPredicates::predicate([], [], [], $this->createMock(IntegrityChecker::class));

        $this->assertTrue($predicate(99));
    }
```

- [ ] **Step 2: Run to verify they fail**

Run: `vendor/bin/phpunit -c test/phpunit.xml --filter ComputedPredicatesTest`
Expected: FAIL, `Call to undefined method OERManager\Service\ComputedPredicates::predicate()`.

- [ ] **Step 3: Implement.** Add to `ComputedPredicates` (plus `use OERManager\ColumnType\AlignmentStatus;` and `use Omeka\Api\Representation\ItemRepresentation;`):

```php
    /**
     * The active computed predicates composed into one (AND). Shared by the
     * master view and the batch selection so both judge «matching» the same way.
     *
     * @param list<string> $keys
     * @param array<int,ItemRepresentation> $candidates
     * @return callable(int):bool
     */
    public static function predicate(array $keys, array $candidates, array $query, IntegrityChecker $checker): callable
    {
        $predicates = [];
        foreach ($keys as $key) {
            if (self::ALIGNMENT_PARTIAL === $key) {
                $predicates[] = static fn (int $id): bool => AlignmentStatus::PARTIAL
                    === AlignmentStatus::statusFor($candidates[$id]);
                continue;
            }
            if (self::INTEGRITY === $key) {
                $wanted = (string) $query['integrity'];
                $predicates[] = static fn (int $id): bool => $wanted
                    === $checker->check($candidates[$id], false)->getStatus();
            }
        }

        return static function (int $id) use ($predicates): bool {
            foreach ($predicates as $predicate) {
                if (!$predicate($id)) {
                    return false;
                }
            }
            return true;
        };
    }
```

In `IndexController::indexAction()` replace `$this->computedPredicate($computedKeys, $candidates, $query)` with
`ComputedPredicates::predicate($computedKeys, $candidates, $query, $this->integrityChecker)`, delete the private
`computedPredicate()` method and its docblock, and remove `use OERManager\ColumnType\AlignmentStatus;` if
`grep -n AlignmentStatus src/Controller/Admin/IndexController.php` shows no other use.

- [ ] **Step 4: Run to verify they pass, including the master view**

Run: `vendor/bin/phpunit -c test/phpunit.xml --filter 'ComputedPredicatesTest|IndexControllerTest'`
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add src/Service/ComputedPredicates.php src/Controller/Admin/IndexController.php test/Service/ComputedPredicatesTest.php
git commit -m "refactor: share the computed-filter predicate with the batch selection

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

---

### Task 2: Batch id in the typed curation event (+ ADR-0020 addendum)

**Files:**
- Modify: `src/Service/CurationEvent.php` (`buildTyped()`, around line 240)
- Modify: `docs/decisions/0020-curation-event-typed-values.md` (append an addendum section)
- Test: `test/Service/CurationEventTest.php`

**Interfaces:**
- Produces: `CurationEvent::buildTyped(array $terms, ?string $undoOf = null, ?string $batch = null): ?array` — adds `'batch' => $batch` only when non-null.

- [ ] **Step 1: Write the failing test**

```php
    public function testTypedEventCarriesAnOptionalBatchIdThatSurvivesDecode(): void
    {
        $terms = ['dcterms:license' => ['before' => [], 'after' => [['type' => 'uri', 'uri' => 'https://x/by/4.0/']]]];

        $plain = CurationEvent::buildTyped($terms);
        $batched = CurationEvent::buildTyped($terms, null, 'batch-42');

        $this->assertArrayNotHasKey('batch', $plain);
        $this->assertSame('batch-42', $batched['batch']);
        $decoded = CurationEvent::decode(CurationEvent::encode($batched));
        $this->assertSame('batch-42', $decoded['batch']);
        $this->assertSame('governance', CurationEvent::scopeOf($decoded));
        $this->assertSame(CurationEvent::OP_GOVERNANCE, $decoded['op']);
    }
```

- [ ] **Step 2: Run to verify it fails**

Run: `vendor/bin/phpunit -c test/phpunit.xml --filter testTypedEventCarriesAnOptionalBatchId`
Expected: FAIL (`Undefined array key "batch"`).

- [ ] **Step 3: Implement.** Change the signature and the return of `buildTyped()`:

```php
    public static function buildTyped(array $terms, ?string $undoOf = null, ?string $batch = null): ?array
    {
        // ... existing $changed loop unchanged ...
        if (!$changed) {
            return null;
        }
        $event = [
            'v' => self::VERSION_TYPED,
            'op' => null === $undoOf ? self::OP_GOVERNANCE : self::OP_UNDO,
            'undoOf' => $undoOf,
            'terms' => $changed,
        ];
        if (null !== $batch) {
            $event['batch'] = $batch;
        }
        return $event;
    }
```

Add `@param string|null $batch identifier of the batch that produced the event (ADR-0020 addendum)` to its docblock.

Append to `docs/decisions/0020-curation-event-typed-values.md`:

```markdown
## Addendum (2026-09-24, TASK-028 slice 4): optional `batch` key

A v2 payload may carry `"batch": "batch-<jobId>"`, the id of the batch job that wrote it. It has no
effect on replay: `undoEvent()` restores `before` exactly as for an individual edit, and the undo
event it writes carries no batch id. The key exists now, before any batch undo, because it cannot be
added to events already written; a later slice can find every event of a batch by it. `decode()`
already accepts it (it validates only `v` and `terms`). Owner decision in the slice 4 design session.
```

- [ ] **Step 4: Run to verify it passes**

Run: `vendor/bin/phpunit -c test/phpunit.xml --filter CurationEventTest`
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add src/Service/CurationEvent.php test/Service/CurationEventTest.php docs/decisions/0020-curation-event-typed-values.md
git commit -m "feat: record an optional batch id in typed curation events

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

---

### Task 3: `GovernanceService` — `onlyEmpty`, `batch`, `reread`, `formOptions()`

**Files:**
- Modify: `src/Service/GovernanceService.php` (`read()` lines 32-45, `apply()` lines 47-97)
- Test: `test/Service/GovernanceServiceTest.php`

**Interfaces:**
- Consumes: `CurationEvent::buildTyped($terms, $undoOf, $batch)` (Task 2).
- Produces:
  - `apply(int $itemId, array $raw, string $contributor, ?string $undoOf = null, bool $onlyEmpty = false, ?string $batch = null, bool $reread = true): array` — every return now also carries `'skipped' => list<string>` (terms dropped by `onlyEmpty`; `[]` otherwise). With `reread: false` a successful write returns `['updated' => true, 'event' => [...], 'skipped' => [...]]` without `values`.
  - `formOptions(): array{options: array{licence: list, publisher: list}, notices: array<string,string>, defaultRightsHolder: string}`.

- [ ] **Step 1: Write the failing tests** (append to `GovernanceServiceTest`)

```php
    public function testFillModeSkipsFieldsThatAlreadyHaveAValueAndWritesTheRest(): void
    {
        $this->items[1] = $this->item(1, '', [GovernanceFields::CREATOR => [$this->value('Existing')]]);
        $service = $this->makeService();

        $result = $service->apply(1, [
            GovernanceFields::CREATOR => ['Ana'],
            GovernanceFields::RIGHTS_HOLDER => ['Consejería'],
        ], 'Curator', null, onlyEmpty: true);

        $this->assertTrue($result['updated']);
        $this->assertSame([GovernanceFields::CREATOR], $result['skipped']);
        $this->assertArrayNotHasKey(GovernanceFields::CREATOR, $this->writes[0]);
        $this->assertNotContains($this->propertyId(GovernanceFields::CREATOR), $this->writes[0]['clear_property_values']);
        $this->assertSame('Consejería', $this->writes[0][GovernanceFields::RIGHTS_HOLDER][0]['@value']);
    }

    public function testFillModeWithEveryFieldTakenWritesNothing(): void
    {
        $this->items[1] = $this->item(1, '', [GovernanceFields::CREATOR => [$this->value('Existing')]]);
        $service = $this->makeService();

        $result = $service->apply(1, [GovernanceFields::CREATOR => ['Ana']], 'Curator', null, onlyEmpty: true);

        $this->assertFalse($result['updated']);
        $this->assertTrue($result['unchanged']);
        $this->assertSame([GovernanceFields::CREATOR], $result['skipped']);
        $this->assertSame([], $this->writes);
    }

    public function testBatchIdReachesTheEventAndRereadCanBeSkipped(): void
    {
        $service = $this->makeService();

        $result = $service->apply(1, [GovernanceFields::CREATOR => ['Ana']], 'Curator', batch: 'batch-7', reread: false);

        $this->assertTrue($result['updated']);
        $this->assertArrayNotHasKey('values', $result);
        $this->assertSame([], $result['skipped']);
        $payload = CurationEvent::decode(
            $this->writes[0]['dcterms:provenance'][0]['@annotation']['dcterms:replaces'][0]['@value']
        );
        $this->assertSame('batch-7', $payload['batch']);
    }

    public function testFormOptionsCarryVocabulariesNoticesAndDefaultRightsHolder(): void
    {
        $this->settings->method('get')->willReturnCallback(
            fn ($key, $default = null) => GovernanceSettings::DEFAULT_RIGHTS_HOLDER === $key ? 'Consejería' : $default
        );
        $service = $this->makeService(licenceVocabId: 2, licenceEntries: [['uri' => 'https://x/by/4.0/', 'label' => 'BY']]);

        $options = $service->formOptions();

        $this->assertSame([['uri' => 'https://x/by/4.0/', 'label' => 'BY']], $options['options']['licence']);
        $this->assertArrayHasKey(GovernanceFields::PUBLISHER, $options['notices']);
        $this->assertSame('Consejería', $options['defaultRightsHolder']);
    }
```

- [ ] **Step 2: Run to verify they fail**

Run: `vendor/bin/phpunit -c test/phpunit.xml --filter GovernanceServiceTest`
Expected: FAIL (unknown named parameter `onlyEmpty`, undefined method `formOptions`).

- [ ] **Step 3: Implement.** Replace `read()` and `apply()`:

```php
    /** @return array<string,mixed> */
    public function read(ItemRepresentation $item): array
    {
        $values = $this->currentValues($item);
        return array_merge(
            [
                'values' => $values,
                'licenceStatus' => LicenceStatus::of($values[GovernanceFields::LICENCE] ?? [], $this->licenceVocab->uris()),
            ],
            $this->formOptions()
        );
    }

    /**
     * What a governance form needs that does not depend on any item: the two
     * vocabularies, their degradation notices and the default rights holder.
     * The batch form (slice 4) uses it on its own; `read()` adds the item.
     *
     * @return array<string,mixed>
     */
    public function formOptions(): array
    {
        return [
            'options' => [
                'licence' => $this->licenceVocab->entries(),
                'publisher' => $this->publisherVocab->entries(),
            ],
            'notices' => $this->notices(),
            'defaultRightsHolder' => (string) $this->settings->get(GovernanceSettings::DEFAULT_RIGHTS_HOLDER, ''),
        ];
    }

    /**
     * @param bool $onlyEmpty Fill mode (slice 4): drop every term the item already has a value in
     * @param string|null $batch Batch id recorded in the event (ADR-0020 addendum)
     * @param bool $reread Re-read the item for the panel's repaint; a batch does not need it
     * @return array<string,mixed>
     */
    public function apply(
        int $itemId,
        array $raw,
        string $contributor,
        ?string $undoOf = null,
        bool $onlyEmpty = false,
        ?string $batch = null,
        bool $reread = true
    ): array {
        $normalised = GovernanceFields::normalise($raw);
        if ($normalised['errors']) {
            return ['updated' => false, 'errors' => $normalised['errors'], 'skipped' => []];
        }

        $when = $this->writer->stamp();
        $item = $this->api->read('items', $itemId)->getContent();
        $current = $this->currentValues($item);

        // Checked here, on the read the write already needs, not at preview
        // time: a value somebody set since the preview must not be overwritten.
        $skipped = [];
        if ($onlyEmpty) {
            foreach (array_keys($normalised['values']) as $term) {
                if ([] !== ($current[$term] ?? [])) {
                    unset($normalised['values'][$term]);
                    $skipped[] = $term;
                }
            }
            if ([] === $normalised['values']) {
                return ['updated' => false, 'unchanged' => true, 'skipped' => $skipped];
            }
        }

        $data = [];
        $clear = [];
        $terms = [];
        foreach ($normalised['values'] as $term => $submitted) {
            $propertyId = $this->writer->propertyId($term);
            if (null === $propertyId) {
                continue;
            }
            $after = $this->withVocabularyType($term, $submitted);
            $terms[$term] = ['before' => $current[$term] ?? [], 'after' => $after];
            $clear[] = $propertyId;
            if ($after) {
                $data[$term] = $this->buildValues($propertyId, $after, $contributor, $when, $term);
            }
        }
        if (!$clear) {
            return ['updated' => false, 'properties' => [], 'skipped' => $skipped];
        }

        // Nothing changed means nothing is written: rewriting identical values
        // would reseal every annotation with a new author and time.
        $event = CurationEvent::buildTyped($terms, $undoOf, $batch);
        if (null === $event) {
            return ['updated' => false, 'unchanged' => true, 'skipped' => $skipped];
        }

        $eventValue = $this->writer->eventValue($event, $contributor, $when);
        if ($eventValue) {
            $data['dcterms:provenance'] = [$eventValue];
        }
        $this->writer->commit($itemId, $clear, $data);

        $summary = ['when' => $when, 'summary' => CurationEvent::summary($event)];
        if (!$reread) {
            return ['updated' => true, 'event' => $summary, 'skipped' => $skipped];
        }
        $fresh = $this->api->read('items', $itemId)->getContent();
        return [
            'updated' => true,
            'values' => $this->currentValues($fresh),
            'event' => $summary,
            'skipped' => $skipped,
        ];
    }
```

- [ ] **Step 4: Run to verify they pass, plus every caller**

Run: `vendor/bin/phpunit -c test/phpunit.xml --filter 'GovernanceServiceTest|IndexControllerTest|UndoRouterTest'`
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add src/Service/GovernanceService.php test/Service/GovernanceServiceTest.php
git commit -m "feat: fill-empty, batch id and no-reread options on governance apply

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

---

### Task 4: `BatchRequest` — validate the curator's request

**Files:**
- Create: `src/Service/Governance/BatchRequest.php`
- Test: `test/Service/Governance/BatchRequestTest.php`

**Interfaces:**
- Produces: `BatchRequest::fromPost(mixed $governance, mixed $mode): BatchRequest` with public readonly `array $raw` (`term => list<string>`), `string $mode` (`'fill'|'replace'`), `array $errors` (`term|'_' => code`); constants `BATCH_TERMS`, `MODE_FILL`, `MODE_REPLACE`; method `isValid(): bool`.

- [ ] **Step 1: Write the failing tests**

```php
<?php

declare(strict_types=1);

namespace OERManager\Test\Service\Governance;

use OERManager\Service\Governance\BatchRequest;
use OERManager\Service\Governance\GovernanceFields;
use PHPUnit\Framework\TestCase;

final class BatchRequestTest extends TestCase
{
    public function testTickedFieldsAreNormalisedAndModeDefaultsToFill(): void
    {
        $request = BatchRequest::fromPost([
            GovernanceFields::CREATOR => [' Ana ', '', 'Luis'],
            GovernanceFields::LICENCE => 'https://creativecommons.org/licenses/by/4.0/',
        ], null);

        $this->assertTrue($request->isValid());
        $this->assertSame(BatchRequest::MODE_FILL, $request->mode);
        $this->assertSame(['Ana', 'Luis'], $request->raw[GovernanceFields::CREATOR]);
        $this->assertSame(['https://creativecommons.org/licenses/by/4.0/'], $request->raw[GovernanceFields::LICENCE]);
    }

    public function testSourceAndUnknownTermsAreNeverBatched(): void
    {
        $request = BatchRequest::fromPost([
            GovernanceFields::SOURCE => 'https://example.org/rea/1',
            'dcterms:title' => 'x',
            GovernanceFields::PUBLISHER => 'ACME',
        ], 'replace');

        $this->assertSame([GovernanceFields::PUBLISHER], array_keys($request->raw));
        $this->assertSame(BatchRequest::MODE_REPLACE, $request->mode);
    }

    public function testATickedFieldWithoutAValueIsRequiredNotAClear(): void
    {
        $request = BatchRequest::fromPost([GovernanceFields::LICENCE => '  '], 'replace');

        $this->assertFalse($request->isValid());
        $this->assertSame('required', $request->errors[GovernanceFields::LICENCE]);
    }

    public function testNoFieldUnknownModeAndInvalidValuesAreErrors(): void
    {
        $this->assertSame('no-field', BatchRequest::fromPost([], 'fill')->errors['_']);
        $this->assertSame('no-field', BatchRequest::fromPost('not-an-array', 'fill')->errors['_']);
        $this->assertSame('mode', BatchRequest::fromPost([GovernanceFields::CREATOR => ['Ana']], 'append')->errors['_']);
        $this->assertSame(
            'not-http-uri',
            BatchRequest::fromPost([GovernanceFields::LICENCE => 'ccbysa'], 'fill')->errors[GovernanceFields::LICENCE]
        );
        $this->assertSame(
            'too-many',
            BatchRequest::fromPost([GovernanceFields::PUBLISHER => ['A', 'B']], 'fill')->errors[GovernanceFields::PUBLISHER]
        );
    }
}
```

- [ ] **Step 2: Run to verify it fails**

Run: `vendor/bin/phpunit -c test/phpunit.xml --filter BatchRequestTest`
Expected: FAIL (`Class "OERManager\Service\Governance\BatchRequest" not found`).

- [ ] **Step 3: Implement**

```php
<?php

declare(strict_types=1);

namespace OERManager\Service\Governance;

/**
 * The curator's batch request (TASK-028 slice 4), validated. Pure.
 *
 * A term present in the input is a field the curator ticked; an absent one is
 * «do not touch». `dcterms:source` is never batched: it is the URI one REA came
 * from. A ticked field must carry a value — clearing a field across thousands
 * of REA is not an assignment and is out of this slice.
 */
final class BatchRequest
{
    public const MODE_FILL = 'fill';
    public const MODE_REPLACE = 'replace';

    public const BATCH_TERMS = [
        GovernanceFields::LICENCE,
        GovernanceFields::CREATOR,
        GovernanceFields::PUBLISHER,
        GovernanceFields::RIGHTS_HOLDER,
    ];

    /**
     * @param array<string,list<string>> $raw
     * @param array<string,string> $errors
     */
    private function __construct(
        public readonly array $raw,
        public readonly string $mode,
        public readonly array $errors
    ) {
    }

    public static function fromPost(mixed $governance, mixed $mode): self
    {
        $mode = null === $mode || '' === $mode ? self::MODE_FILL : (string) $mode;
        $posted = is_array($governance) ? $governance : [];

        $raw = [];
        foreach (self::BATCH_TERMS as $term) {
            if (!array_key_exists($term, $posted)) {
                continue;
            }
            $raw[$term] = array_values(array_filter(
                array_map(static fn ($value): string => trim((string) $value), (array) $posted[$term]),
                static fn (string $value): bool => '' !== $value
            ));
        }

        $errors = [];
        if (!in_array($mode, [self::MODE_FILL, self::MODE_REPLACE], true)) {
            $errors['_'] = 'mode';
        }
        if ([] === $raw) {
            $errors['_'] = 'no-field';
        }
        foreach ($raw as $term => $values) {
            if ([] === $values) {
                $errors[$term] = 'required';
            }
        }
        $errors += GovernanceFields::normalise($raw)['errors'];

        return new self($raw, $mode, $errors);
    }

    public function isValid(): bool
    {
        return [] === $this->errors;
    }
}
```

- [ ] **Step 4: Run to verify it passes**

Run: `vendor/bin/phpunit -c test/phpunit.xml --filter BatchRequestTest`
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add src/Service/Governance/BatchRequest.php test/Service/Governance/BatchRequestTest.php
git commit -m "feat: validate batch governance requests

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

---

### Task 5: `BatchPlan` and `BatchPlanStore`

**Files:**
- Create: `src/Service/Governance/BatchPlan.php`, `src/Service/Governance/BatchPlanStore.php`
- Test: `test/Service/Governance/BatchPlanTest.php`, `test/Service/Governance/BatchPlanStoreTest.php`

**Interfaces:**
- Consumes: `BatchRequest::MODE_FILL|MODE_REPLACE`.
- Produces:
  - `new BatchPlan(int $ownerId, list<int> $ids, array $raw, string $mode)`; readonly props `ownerId`, `ids`, `raw`, `mode`; `summary(array<string,int> $haveValue): array<string,array{total:int,write:int,skipped_has_value?:int,overwrite?:int}>`; `writeTotal(array $summary): int`; `toArray(): array`; `static fromArray(array $data): ?BatchPlan`.
  - `new BatchPlanStore(string $baseDir, int $ttlSeconds = 1800)`; `put(BatchPlan $plan): string` (32 hex chars); `take(string $token, int $ownerId): ?BatchPlan` (deletes the file; null if malformed token, missing, expired, or other owner); `sweepOld(): void`.

- [ ] **Step 1: Write the failing tests**

`test/Service/Governance/BatchPlanTest.php`:

```php
<?php

declare(strict_types=1);

namespace OERManager\Test\Service\Governance;

use OERManager\Service\Governance\BatchPlan;
use OERManager\Service\Governance\BatchRequest;
use PHPUnit\Framework\TestCase;

final class BatchPlanTest extends TestCase
{
    private const RAW = ['dcterms:license' => ['https://x/by/4.0/'], 'dcterms:creator' => ['Ana']];

    public function testFillSummarySkipsItemsThatHaveAValue(): void
    {
        $plan = new BatchPlan(3, [1, 2, 3, 4], self::RAW, BatchRequest::MODE_FILL);

        $summary = $plan->summary(['dcterms:license' => 1, 'dcterms:creator' => 0]);

        $this->assertSame(['total' => 4, 'write' => 3, 'skipped_has_value' => 1], $summary['dcterms:license']);
        $this->assertSame(['total' => 4, 'write' => 4, 'skipped_has_value' => 0], $summary['dcterms:creator']);
        $this->assertSame(4, BatchPlan::writeTotal($summary));
    }

    public function testReplaceSummaryCountsOverwrites(): void
    {
        $plan = new BatchPlan(3, [1, 2], self::RAW, BatchRequest::MODE_REPLACE);

        $summary = $plan->summary(['dcterms:license' => 2, 'dcterms:creator' => 0]);

        $this->assertSame(['total' => 2, 'write' => 2, 'overwrite' => 2], $summary['dcterms:license']);
    }

    public function testWriteTotalIsZeroWhenFillFindsEverythingTaken(): void
    {
        $plan = new BatchPlan(3, [1, 2], ['dcterms:license' => ['https://x/']], BatchRequest::MODE_FILL);

        $this->assertSame(0, BatchPlan::writeTotal($plan->summary(['dcterms:license' => 2])));
    }

    public function testArrayRoundTripAndRejectionOfBrokenData(): void
    {
        $plan = new BatchPlan(3, [5, 6], self::RAW, BatchRequest::MODE_FILL);

        $copy = BatchPlan::fromArray($plan->toArray());

        $this->assertSame([5, 6], $copy->ids);
        $this->assertSame(3, $copy->ownerId);
        $this->assertSame(self::RAW, $copy->raw);
        $this->assertNull(BatchPlan::fromArray(['ids' => 'x']));
        $this->assertNull(BatchPlan::fromArray([]));
    }
}
```

`test/Service/Governance/BatchPlanStoreTest.php`:

```php
<?php

declare(strict_types=1);

namespace OERManager\Test\Service\Governance;

use OERManager\Service\Governance\BatchPlan;
use OERManager\Service\Governance\BatchPlanStore;
use OERManager\Service\Governance\BatchRequest;
use PHPUnit\Framework\TestCase;

final class BatchPlanStoreTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/oer_batch_plans_' . bin2hex(random_bytes(6));
    }

    protected function tearDown(): void
    {
        foreach (glob($this->dir . '/*') ?: [] as $file) {
            unlink($file);
        }
        if (is_dir($this->dir)) {
            rmdir($this->dir);
        }
    }

    private function plan(int $owner = 3): BatchPlan
    {
        return new BatchPlan($owner, [1, 2], ['dcterms:creator' => ['Ana']], BatchRequest::MODE_FILL);
    }

    public function testATokenCanBeTakenOnceByItsOwner(): void
    {
        $store = new BatchPlanStore($this->dir);
        $token = $store->put($this->plan());

        $this->assertMatchesRegularExpression('/^[a-f0-9]{32}$/', $token);
        $this->assertNull($store->take($token, 99), 'another user cannot take it');
        $this->assertSame([1, 2], $store->take($token, 3)->ids);
        $this->assertNull($store->take($token, 3), 'one-shot');
    }

    public function testAnotherUsersAttemptDoesNotBurnTheToken(): void
    {
        $store = new BatchPlanStore($this->dir);
        $token = $store->put($this->plan());

        $store->take($token, 99);

        $this->assertNotNull($store->take($token, 3));
    }

    public function testExpiredPlansAreRefusedAndSwept(): void
    {
        $store = new BatchPlanStore($this->dir, 60);
        $token = $store->put($this->plan());
        touch($this->dir . '/' . $token . '.json', time() - 120);

        $this->assertNull($store->take($token, 3));

        $other = $store->put($this->plan());
        touch($this->dir . '/' . $other . '.json', time() - 120);
        $store->sweepOld();
        $this->assertFileDoesNotExist($this->dir . '/' . $other . '.json');
    }

    public function testMalformedTokensNeverReachTheFilesystem(): void
    {
        $store = new BatchPlanStore($this->dir);
        mkdir($this->dir, 0700, true);
        file_put_contents($this->dir . '/../oer_victim.json', '{}');

        foreach (['', '../oer_victim', 'ABC', str_repeat('g', 32), str_repeat('a', 31)] as $token) {
            $this->assertNull($store->take($token, 3), var_export($token, true));
        }
        $this->assertFileExists($this->dir . '/../oer_victim.json');
        unlink($this->dir . '/../oer_victim.json');
    }
}
```

- [ ] **Step 2: Run to verify they fail**

Run: `vendor/bin/phpunit -c test/phpunit.xml --filter 'BatchPlanTest|BatchPlanStoreTest'`
Expected: FAIL (classes not found).

- [ ] **Step 3: Implement**

`src/Service/Governance/BatchPlan.php`:

```php
<?php

declare(strict_types=1);

namespace OERManager\Service\Governance;

/**
 * A frozen batch (TASK-028 slice 4): who asked, which ids, which values, which
 * mode. The id list is fixed at preview time so the number the curator
 * confirms is the set the job processes. Pure.
 */
final class BatchPlan
{
    /**
     * @param list<int> $ids
     * @param array<string,list<string>> $raw
     */
    public function __construct(
        public readonly int $ownerId,
        public readonly array $ids,
        public readonly array $raw,
        public readonly string $mode
    ) {
    }

    /**
     * Preview per ticked field, from how many of the ids already have a value.
     *
     * @param array<string,int> $haveValue term => count of ids with a value
     * @return array<string,array<string,int>>
     */
    public function summary(array $haveValue): array
    {
        $total = count($this->ids);
        $summary = [];
        foreach (array_keys($this->raw) as $term) {
            $have = min($total, max(0, (int) ($haveValue[$term] ?? 0)));
            $summary[$term] = BatchRequest::MODE_REPLACE === $this->mode
                ? ['total' => $total, 'write' => $total, 'overwrite' => $have]
                : ['total' => $total, 'write' => $total - $have, 'skipped_has_value' => $have];
        }
        return $summary;
    }

    /**
     * Largest per-field write count: 0 means applying would write nothing.
     *
     * @param array<string,array<string,int>> $summary
     */
    public static function writeTotal(array $summary): int
    {
        $max = 0;
        foreach ($summary as $field) {
            $max = max($max, (int) $field['write']);
        }
        return $max;
    }

    /** @return array<string,mixed> */
    public function toArray(): array
    {
        return ['owner' => $this->ownerId, 'ids' => $this->ids, 'raw' => $this->raw, 'mode' => $this->mode];
    }

    /** @param array<string,mixed> $data */
    public static function fromArray(array $data): ?self
    {
        if (
            !isset($data['owner'], $data['ids'], $data['raw'], $data['mode'])
            || !is_array($data['ids']) || !is_array($data['raw'])
        ) {
            return null;
        }
        return new self(
            (int) $data['owner'],
            array_values(array_map('intval', $data['ids'])),
            $data['raw'],
            (string) $data['mode']
        );
    }
}
```

`src/Service/Governance/BatchPlanStore.php`:

```php
<?php

declare(strict_types=1);

namespace OERManager\Service\Governance;

/**
 * Plans between preview and apply (TASK-028 slice 4): one private JSON file
 * per random token, same atomic-write pattern as ProposalStore. A plan is taken
 * once, only by its owner and only before it expires; the token is validated
 * against its exact shape before any path is built from it.
 */
final class BatchPlanStore
{
    private const TOKEN_PATTERN = '/^[a-f0-9]{32}$/';

    public function __construct(private string $baseDir, private int $ttlSeconds = 1800)
    {
    }

    public function put(BatchPlan $plan): string
    {
        if (!is_dir($this->baseDir)) {
            @mkdir($this->baseDir, 0700, true);
        }
        $token = bin2hex(random_bytes(16));
        $tmp = $this->baseDir . '/.' . $token . '.tmp';
        file_put_contents($tmp, (string) json_encode($plan->toArray(), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        rename($tmp, $this->path($token));
        return $token;
    }

    public function take(string $token, int $ownerId): ?BatchPlan
    {
        if (1 !== preg_match(self::TOKEN_PATTERN, $token)) {
            return null;
        }
        $path = $this->path($token);
        if (!is_file($path)) {
            return null;
        }
        if (@filemtime($path) < time() - $this->ttlSeconds) {
            @unlink($path);
            return null;
        }
        $data = json_decode((string) file_get_contents($path), true);
        $plan = is_array($data) ? BatchPlan::fromArray($data) : null;
        if (null === $plan || $plan->ownerId !== $ownerId) {
            return null;
        }
        @unlink($path);
        return $plan;
    }

    public function sweepOld(): void
    {
        $cutoff = time() - $this->ttlSeconds;
        foreach (glob($this->baseDir . '/*.json') ?: [] as $file) {
            if (@filemtime($file) < $cutoff) {
                @unlink($file);
            }
        }
    }

    private function path(string $token): string
    {
        return $this->baseDir . '/' . $token . '.json';
    }
}
```

- [ ] **Step 4: Run to verify they pass**

Run: `vendor/bin/phpunit -c test/phpunit.xml --filter 'BatchPlanTest|BatchPlanStoreTest'`
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add src/Service/Governance/BatchPlan.php src/Service/Governance/BatchPlanStore.php test/Service/Governance/BatchPlanTest.php test/Service/Governance/BatchPlanStoreTest.php
git commit -m "feat: frozen one-shot batch plans with preview summary

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

---

### Task 6: `BatchSelection` — resolve the target set and count values

**Files:**
- Create: `src/Service/Governance/BatchSelection.php`, `src/Service/Governance/BatchSelectionException.php`
- Modify: `config/module.config.php` (`service_manager.factories`, next to `Service\GovernanceService::class`)
- Test: `test/Service/Governance/BatchSelectionTest.php`

**Interfaces:**
- Consumes: `MasterViewQuery::buildSearchParams(array $query): array`, `ComputedPredicates::activeKeys()`, `ComputedPredicates::predicate()` (Task 1), `ComputedFilter::HARD_CAP`.
- Produces:
  - `class BatchSelection` (not final, so the controller test can mock it) with `const BATCH_MAX = 5000`;
    `resolveIds(array $ids): list<int>`, `resolveMatching(array $query): list<int>`, `countWithValue(list<int> $ids, string $term): int`.
  - `final class BatchSelectionException extends \RuntimeException` with `public readonly string $reason` — one of `empty`, `too_many`, `class_unresolved`, `computed_truncated`.

- [ ] **Step 1: Write the failing tests**

```php
<?php

declare(strict_types=1);

namespace OERManager\Test\Service\Governance;

use OERManager\Service\ComputedFilter;
use OERManager\Service\Governance\BatchSelection;
use OERManager\Service\Governance\BatchSelectionException;
use OERManager\Service\IntegrityChecker;
use OERManager\Service\IntegrityResult;
use OERManager\Service\MasterViewQuery;
use OERManager\Test\Support\RepresentationFactory;
use Omeka\Api\Manager;
use PHPUnit\Framework\TestCase;

final class BatchSelectionTest extends TestCase
{
    use RepresentationFactory;

    private $api;
    private $query;
    private $checker;
    private array $searches = [];
    /** @var callable */
    private $respond;

    protected function setUp(): void
    {
        $this->api = $this->createMock(Manager::class);
        $this->query = $this->createMock(MasterViewQuery::class);
        $this->checker = $this->createMock(IntegrityChecker::class);
        $this->query->method('buildSearchParams')->willReturnCallback(
            static fn (array $q) => ['resource_class_id' => 9] + $q
        );
        $this->respond = fn (array $params, array $options) => $this->response([4, 2], 2);
        $this->api->method('search')->willReturnCallback(function ($resource, $params, $options = []) {
            $this->searches[] = [$params, $options];
            return ($this->respond)($params, $options);
        });
    }

    private function selection(): BatchSelection
    {
        return new BatchSelection($this->api, $this->query, $this->checker);
    }

    private function reason(callable $call): string
    {
        try {
            $call();
        } catch (BatchSelectionException $e) {
            return $e->reason;
        }
        $this->fail('expected a BatchSelectionException');
    }

    public function testExplicitIdsAreCleanedAndConstrainedToTheReaClass(): void
    {
        $ids = $this->selection()->resolveIds(['4', 4, 0, -1, 'x', 2]);

        $this->assertSame([2, 4], $ids);
        [$params, $options] = $this->searches[0];
        $this->assertSame(9, $params['resource_class_id']);
        $this->assertSame([2, 4], $params['id']);
        $this->assertSame(['returnScalar' => 'id'], $options);
    }

    public function testMatchingRebuildsTheQueryWithoutPaginationOrSorting(): void
    {
        $this->selection()->resolveMatching(['page' => 3, 'sort_by' => 'title', 'title' => 'x']);

        [$params, $options] = $this->searches[0];
        $this->assertArrayNotHasKey('page', $params);
        $this->assertArrayNotHasKey('sort_by', $params);
        $this->assertSame('x', $params['title']);
        $this->assertSame(['returnScalar' => 'id'], $options);
    }

    public function testUnresolvedClassEmptySetAndOverCapAreRefused(): void
    {
        $this->query = $this->createMock(MasterViewQuery::class);
        $this->query->method('buildSearchParams')->willReturn(['resource_class_id' => null]);
        $this->assertSame('class_unresolved', $this->reason(fn () => $this->selection()->resolveMatching([])));

        $this->setUp();
        $this->respond = fn () => $this->response([], 0);
        $this->assertSame('empty', $this->reason(fn () => $this->selection()->resolveIds([1])));
        $this->assertSame('empty', $this->reason(fn () => $this->selection()->resolveIds(['x'])));

        $this->respond = fn () => $this->response(range(1, BatchSelection::BATCH_MAX + 1));
        $this->assertSame('too_many', $this->reason(fn () => $this->selection()->resolveMatching([])));
    }

    public function testComputedFilterIsEvaluatedAndRefusedWhenTruncated(): void
    {
        $ok = $this->item(1);
        $warn = $this->item(2);
        $this->checker->method('check')->willReturnCallback(
            fn ($item) => new IntegrityResult($item === $warn ? [['severity' => 'warning']] : [])
        );
        $this->respond = fn ($params, $options) => $this->response([$ok, $warn], 2);

        $this->assertSame([2], $this->selection()->resolveMatching(['integrity' => 'warning']));
        $this->assertSame(ComputedFilter::HARD_CAP, $this->searches[0][0]['per_page']);

        $this->respond = fn ($params, $options) => $this->response([$ok, $warn], ComputedFilter::HARD_CAP + 1);
        $this->assertSame(
            'computed_truncated',
            $this->reason(fn () => $this->selection()->resolveMatching(['integrity' => 'warning']))
        );
    }

    public function testCountWithValueUsesAnExistsQueryOverTheIds(): void
    {
        $this->respond = fn () => $this->response([], 7);

        $this->assertSame(7, $this->selection()->countWithValue([1, 2, 3], 'dcterms:license'));
        $this->assertSame(0, $this->selection()->countWithValue([], 'dcterms:license'));

        [$params] = $this->searches[0];
        $this->assertSame([1, 2, 3], $params['id']);
        $this->assertSame([['joiner' => 'and', 'property' => 'dcterms:license', 'type' => 'ex']], $params['property']);
        $this->assertSame(1, $params['per_page']);
        $this->assertCount(1, $this->searches, 'an empty id list never queries');
    }
}
```

- [ ] **Step 2: Run to verify it fails**

Run: `vendor/bin/phpunit -c test/phpunit.xml --filter BatchSelectionTest`
Expected: FAIL (classes not found).

- [ ] **Step 3: Implement**

`src/Service/Governance/BatchSelectionException.php`:

```php
<?php

declare(strict_types=1);

namespace OERManager\Service\Governance;

/** A batch selection the preview refuses, with the code the UI explains. */
final class BatchSelectionException extends \RuntimeException
{
    public function __construct(public readonly string $reason)
    {
        parent::__construct($reason);
    }
}
```

`src/Service/Governance/BatchSelection.php`:

```php
<?php

declare(strict_types=1);

namespace OERManager\Service\Governance;

use OERManager\Service\ComputedFilter;
use OERManager\Service\ComputedPredicates;
use OERManager\Service\IntegrityChecker;
use OERManager\Service\MasterViewQuery;
use Omeka\Api\Manager as ApiManager;

/**
 * Which REA a batch acts on (TASK-028 slice 4), resolved server-side and
 * sized for the ~3000-REA production catalogue: ids come back as scalars, and
 * existing values are counted with one exists-query per field instead of
 * reading every item. Ids posted by the client are never trusted: they are
 * re-searched inside the REA class.
 */
class BatchSelection
{
    public const BATCH_MAX = 5000;

    public function __construct(
        private ApiManager $api,
        private MasterViewQuery $masterViewQuery,
        private IntegrityChecker $integrityChecker
    ) {
    }

    /**
     * @param array<mixed> $ids ticked rows, as posted
     * @return list<int>
     */
    public function resolveIds(array $ids): array
    {
        $clean = array_values(array_unique(array_filter(
            array_map(static fn ($id): int => is_numeric($id) ? (int) $id : 0, $ids),
            static fn (int $id): bool => $id > 0
        )));
        if ([] === $clean) {
            throw new BatchSelectionException('empty');
        }
        sort($clean);
        if (count($clean) > self::BATCH_MAX) {
            throw new BatchSelectionException('too_many');
        }
        $params = $this->baseParams([]);
        $params['id'] = $clean;
        return $this->ids($params);
    }

    /**
     * @param array<string,mixed> $query the master view's GET parameters
     * @return list<int>
     */
    public function resolveMatching(array $query): array
    {
        unset($query['page'], $query['per_page'], $query['sort_by'], $query['sort_order']);
        $params = $this->baseParams($query);

        $keys = ComputedPredicates::activeKeys($query);
        if ([] === $keys) {
            return $this->ids($params);
        }

        $params['page'] = 1;
        $params['per_page'] = ComputedFilter::HARD_CAP;
        $response = $this->api->search('items', $params);
        if ($response->getTotalResults() > ComputedFilter::HARD_CAP) {
            throw new BatchSelectionException('computed_truncated');
        }
        $candidates = [];
        foreach ($response->getContent() as $item) {
            $candidates[(int) $item->id()] = $item;
        }
        $predicate = ComputedPredicates::predicate($keys, $candidates, $query, $this->integrityChecker);
        $ids = array_values(array_filter(array_keys($candidates), $predicate));
        sort($ids);
        return $this->guard($ids);
    }

    /** @param list<int> $ids */
    public function countWithValue(array $ids, string $term): int
    {
        if ([] === $ids) {
            return 0;
        }
        return (int) $this->api->search('items', [
            'id' => $ids,
            'property' => [['joiner' => 'and', 'property' => $term, 'type' => 'ex']],
            'page' => 1,
            'per_page' => 1,
        ])->getTotalResults();
    }

    /** @return array<string,mixed> */
    private function baseParams(array $query): array
    {
        $params = $this->masterViewQuery->buildSearchParams($query);
        // Without the class the search would match the whole Omeka catalogue,
        // not the REA: the same trap CatalogSnapshot guards against.
        if (empty($params['resource_class_id'])) {
            throw new BatchSelectionException('class_unresolved');
        }
        unset($params['page'], $params['per_page'], $params['sort_by'], $params['sort_order']);
        return $params;
    }

    /** @return list<int> */
    private function ids(array $params): array
    {
        $ids = array_map('intval', (array) $this->api->search('items', $params, ['returnScalar' => 'id'])->getContent());
        sort($ids);
        return $this->guard(array_values(array_unique($ids)));
    }

    /**
     * @param list<int> $ids
     * @return list<int>
     */
    private function guard(array $ids): array
    {
        if ([] === $ids) {
            throw new BatchSelectionException('empty');
        }
        if (count($ids) > self::BATCH_MAX) {
            throw new BatchSelectionException('too_many');
        }
        return $ids;
    }
}
```

Register in `config/module.config.php`, `service_manager.factories`, right after the `Service\Curation\UndoRouter::class` factory:

```php
            // Lote de gobernanza (TASK-028 slice 4): conjunto objetivo y planes.
            Service\Governance\BatchSelection::class => function ($container) {
                return new Service\Governance\BatchSelection(
                    $container->get('Omeka\ApiManager'),
                    $container->get(Service\MasterViewQuery::class),
                    $container->get(Service\IntegrityChecker::class)
                );
            },
            Service\Governance\BatchPlanStore::class => function () {
                return new Service\Governance\BatchPlanStore(sys_get_temp_dir() . '/oer-manager-batch-plans');
            },
```

- [ ] **Step 4: Run to verify it passes, plus the config test**

Run: `vendor/bin/phpunit -c test/phpunit.xml --filter 'BatchSelectionTest|ModuleConfigTest'`
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add src/Service/Governance/BatchSelection.php src/Service/Governance/BatchSelectionException.php config/module.config.php test/Service/Governance/BatchSelectionTest.php
git commit -m "feat: resolve and count the batch target set server-side

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

---

### Task 7: `GovernanceBatchRunner` and `BatchProgressReporter`

**Files:**
- Create: `src/Service/Governance/GovernanceBatchRunner.php`, `src/Service/Governance/BatchProgressReporter.php`
- Create: `test/stubs/Omeka/Api/Exception/NotFoundException.php` (only if `find test/stubs -name NotFoundException.php` finds nothing)
- Test: `test/Service/Governance/GovernanceBatchRunnerTest.php`, `test/Service/Governance/BatchProgressReporterTest.php`

**Interfaces:**
- Consumes: `OERManager\Service\Ai\ProgressReporter` (`report(string $step, int $done, int $total): void`, `shouldStop(): bool`), `ProposalStore::write(int $jobId, array $state)`.
- Produces:
  - `GovernanceBatchRunner::run(list<int> $ids, callable $apply, ProgressReporter $progress, ?callable $flush = null, ?callable $log = null): array{written:int, skipped:int, unchanged:int, failed: list<array{id:int, code:string}>, done:int, total:int, stopped:bool}` — `$apply(int $id): array` is a `GovernanceService::apply()` result; `$flush()` runs every `FLUSH_EVERY = 50` items; `$log(string $message)` receives unexpected errors; `PROGRESS_EVERY = 25`.
  - `BatchProgressReporter` (implements `ProgressReporter`), `const KIND = 'governance-batch'`, constructor `(ProposalStore $store, callable $shouldStop, int $jobId)`, plus `finish(string $status, array $tallies, string $batch): void`.

- [ ] **Step 1: Write the failing tests**

`test/Service/Governance/GovernanceBatchRunnerTest.php`:

```php
<?php

declare(strict_types=1);

namespace OERManager\Test\Service\Governance;

use OERManager\Service\Ai\ProgressReporter;
use OERManager\Service\Governance\GovernanceBatchRunner;
use Omeka\Api\Exception\NotFoundException;
use Omeka\Permissions\Exception\PermissionDeniedException;
use PHPUnit\Framework\TestCase;

final class GovernanceBatchRunnerTest extends TestCase
{
    private function progress(int $stopAfter = PHP_INT_MAX): ProgressReporter
    {
        return new class ($stopAfter) implements ProgressReporter {
            public array $reports = [];
            private int $checks = 0;

            public function __construct(private int $stopAfter)
            {
            }

            public function report(string $step, int $done, int $total): void
            {
                $this->reports[] = [$done, $total];
            }

            public function shouldStop(): bool
            {
                return ++$this->checks > $this->stopAfter;
            }
        };
    }

    public function testTalliesEveryOutcomeWithoutAborting(): void
    {
        $outcomes = [
            1 => ['updated' => true, 'skipped' => []],
            2 => ['updated' => false, 'unchanged' => true, 'skipped' => ['dcterms:license']],
            3 => ['updated' => false, 'unchanged' => true, 'skipped' => []],
            4 => new PermissionDeniedException('private'),
            5 => new NotFoundException('gone'),
            6 => new \LogicException('secret detail'),
            7 => ['updated' => false, 'errors' => ['dcterms:license' => 'not-http-uri']],
        ];
        $logged = [];

        $result = (new GovernanceBatchRunner())->run(
            array_keys($outcomes),
            function (int $id) use ($outcomes): array {
                if ($outcomes[$id] instanceof \Throwable) {
                    throw $outcomes[$id];
                }
                return $outcomes[$id];
            },
            $this->progress(),
            null,
            function (string $message) use (&$logged): void {
                $logged[] = $message;
            }
        );

        $this->assertSame(1, $result['written']);
        $this->assertSame(1, $result['skipped']);
        $this->assertSame(1, $result['unchanged']);
        $this->assertSame([
            ['id' => 4, 'code' => 'denied'],
            ['id' => 5, 'code' => 'not_found'],
            ['id' => 6, 'code' => 'unexpected'],
            ['id' => 7, 'code' => 'invalid'],
        ], $result['failed']);
        $this->assertSame(7, $result['done']);
        $this->assertFalse($result['stopped']);
        $this->assertCount(1, $logged);
        $this->assertStringContainsString('secret detail', $logged[0]);
    }

    public function testReportsProgressFlushesAndStopsBetweenItems(): void
    {
        $progress = $this->progress(60);
        $flushes = 0;
        $applied = 0;

        $result = (new GovernanceBatchRunner())->run(
            range(1, 100),
            function () use (&$applied): array {
                $applied++;
                return ['updated' => true, 'skipped' => []];
            },
            $progress,
            function () use (&$flushes): void {
                $flushes++;
            }
        );

        $this->assertTrue($result['stopped']);
        $this->assertSame(60, $applied);
        $this->assertSame(60, $result['done']);
        $this->assertSame(100, $result['total']);
        $this->assertSame(1, $flushes, 'flush after item 50');
        $this->assertSame([[0, 100], [25, 100], [50, 100], [60, 100]], $progress->reports);
    }
}
```

`test/Service/Governance/BatchProgressReporterTest.php`:

```php
<?php

declare(strict_types=1);

namespace OERManager\Test\Service\Governance;

use OERManager\Service\Ai\ProposalStore;
use OERManager\Service\Governance\BatchProgressReporter;
use PHPUnit\Framework\TestCase;

final class BatchProgressReporterTest extends TestCase
{
    public function testWritesBatchShapedStateAndDelegatesStop(): void
    {
        $dir = sys_get_temp_dir() . '/oer_batch_state_' . bin2hex(random_bytes(6));
        $store = new ProposalStore($dir);
        $reporter = new BatchProgressReporter($store, static fn (): bool => true, 12);

        $reporter->report('batch', 25, 100);
        $this->assertSame(
            ['kind' => 'governance-batch', 'status' => 'in_progress', 'done' => 25, 'total' => 100],
            $store->read(12)
        );
        $this->assertTrue($reporter->shouldStop());

        $reporter->finish('completed', ['written' => 3], 'batch-12');
        $this->assertSame(
            ['kind' => 'governance-batch', 'status' => 'completed', 'batch' => 'batch-12', 'tallies' => ['written' => 3]],
            $store->read(12)
        );

        array_map('unlink', glob($dir . '/*'));
        rmdir($dir);
    }
}
```

If the stub is missing, create `test/stubs/Omeka/Api/Exception/NotFoundException.php`:

```php
<?php

namespace Omeka\Api\Exception;

class NotFoundException extends \RuntimeException
{
}
```

- [ ] **Step 2: Run to verify they fail**

Run: `vendor/bin/phpunit -c test/phpunit.xml --filter 'GovernanceBatchRunnerTest|BatchProgressReporterTest'`
Expected: FAIL (classes not found).

- [ ] **Step 3: Implement**

`src/Service/Governance/GovernanceBatchRunner.php`:

```php
<?php

declare(strict_types=1);

namespace OERManager\Service\Governance;

use OERManager\Service\Ai\ProgressReporter;
use Omeka\Api\Exception\NotFoundException;
use Omeka\Permissions\Exception\PermissionDeniedException;

/**
 * The batch loop (TASK-028 slice 4), pure: the per-item write, the progress
 * channel, the memory flush and the logger are injected, so the job that wires
 * them stays thin. One item's failure never aborts the batch; cancel is honoured
 * between items, never inside one.
 */
final class GovernanceBatchRunner
{
    public const PROGRESS_EVERY = 25;
    public const FLUSH_EVERY = 50;

    /**
     * @param list<int> $ids
     * @param callable(int):array $apply
     * @param callable():void|null $flush
     * @param callable(string):void|null $log
     * @return array<string,mixed>
     */
    public function run(array $ids, callable $apply, ProgressReporter $progress, ?callable $flush = null, ?callable $log = null): array
    {
        $total = count($ids);
        $tallies = ['written' => 0, 'skipped' => 0, 'unchanged' => 0, 'failed' => [], 'done' => 0, 'total' => $total, 'stopped' => false];
        $progress->report('batch', 0, $total);

        foreach ($ids as $id) {
            if ($progress->shouldStop()) {
                $tallies['stopped'] = true;
                break;
            }
            $tallies = $this->tally($tallies, $id, $apply, $log);
            $tallies['done']++;
            if (null !== $flush && 0 === $tallies['done'] % self::FLUSH_EVERY) {
                $flush();
            }
            if (0 === $tallies['done'] % self::PROGRESS_EVERY) {
                $progress->report('batch', $tallies['done'], $total);
            }
        }

        if (0 !== $tallies['done'] % self::PROGRESS_EVERY) {
            $progress->report('batch', $tallies['done'], $total);
        }
        return $tallies;
    }

    /** @param array<string,mixed> $tallies */
    private function tally(array $tallies, int $id, callable $apply, ?callable $log): array
    {
        try {
            $result = $apply($id);
        } catch (PermissionDeniedException $e) {
            $tallies['failed'][] = ['id' => $id, 'code' => 'denied'];
            return $tallies;
        } catch (NotFoundException $e) {
            $tallies['failed'][] = ['id' => $id, 'code' => 'not_found'];
            return $tallies;
        } catch (\Throwable $e) {
            if (null !== $log) {
                $log('OERManager governance batch item ' . $id . ': ' . $e->getMessage());
            }
            $tallies['failed'][] = ['id' => $id, 'code' => 'unexpected'];
            return $tallies;
        }

        if (!empty($result['errors'])) {
            $tallies['failed'][] = ['id' => $id, 'code' => 'invalid'];
        } elseif (true === ($result['updated'] ?? false)) {
            $tallies['written']++;
        } elseif ([] !== ($result['skipped'] ?? [])) {
            $tallies['skipped']++;
        } else {
            $tallies['unchanged']++;
        }
        return $tallies;
    }
}
```

`src/Service/Governance/BatchProgressReporter.php`:

```php
<?php

declare(strict_types=1);

namespace OERManager\Service\Governance;

use OERManager\Service\Ai\ProgressReporter;
use OERManager\Service\Ai\ProposalStore;

/**
 * Batch job state in the same private store the AI propose uses (TASK-020),
 * tagged with `kind` so the status endpoint never serves another job's state
 * as a batch.
 */
final class BatchProgressReporter implements ProgressReporter
{
    public const KIND = 'governance-batch';

    /** @param callable():bool $shouldStop */
    public function __construct(private ProposalStore $store, private $shouldStop, private int $jobId)
    {
    }

    public function report(string $step, int $done, int $total): void
    {
        $this->store->write($this->jobId, [
            'kind' => self::KIND,
            'status' => 'in_progress',
            'done' => $done,
            'total' => $total,
        ]);
    }

    public function shouldStop(): bool
    {
        return (bool) ($this->shouldStop)();
    }

    /** @param array<string,mixed> $tallies */
    public function finish(string $status, array $tallies, string $batch): void
    {
        $this->store->write($this->jobId, [
            'kind' => self::KIND,
            'status' => $status,
            'batch' => $batch,
            'tallies' => $tallies,
        ]);
    }
}
```

- [ ] **Step 4: Run to verify they pass**

Run: `vendor/bin/phpunit -c test/phpunit.xml --filter 'GovernanceBatchRunnerTest|BatchProgressReporterTest'`
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add src/Service/Governance/GovernanceBatchRunner.php src/Service/Governance/BatchProgressReporter.php test/Service/Governance/GovernanceBatchRunnerTest.php test/Service/Governance/BatchProgressReporterTest.php test/stubs/Omeka/Api/Exception
git commit -m "feat: pure batch runner with progress, cancel and per-item failures

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

---

### Task 8: `GovernanceBatchJob`

**Files:**
- Create: `src/Job/GovernanceBatchJob.php`
- Test: `test/Job/GovernanceBatchJobTest.php`

**Interfaces:**
- Consumes: `GovernanceBatchRunner::run()`, `BatchProgressReporter`, `GovernanceService::apply()` (Task 3), `ProposalStore`.
- Produces: job args contract — `ids` (list<int>), `raw` (term => list<string>), `mode` (`fill|replace`), `contributor` (string). Batch id `batch-<jobId>`. Final state status: `completed`, `stopped`, or `error` (code `unexpected`).

- [ ] **Step 1: Write the failing test**

The host stub `AbstractJob` has public `$job` and `$serviceLocator`, and its `getArg()` always returns the default; the test subclasses the job to feed args.

```php
<?php

declare(strict_types=1);

namespace OERManager\Test\Job;

use OERManager\Job\GovernanceBatchJob;
use OERManager\Service\Ai\ProposalStore;
use OERManager\Service\GovernanceService;
use PHPUnit\Framework\TestCase;

final class GovernanceBatchJobTest extends TestCase
{
    public function testAppliesEveryIdWithTheBatchOptionsAndRecordsTheOutcome(): void
    {
        $dir = sys_get_temp_dir() . '/oer_batch_job_' . bin2hex(random_bytes(6));
        $store = new ProposalStore($dir);
        $governance = $this->createMock(GovernanceService::class);
        $calls = [];
        $governance->method('apply')->willReturnCallback(
            function (...$args) use (&$calls): array {
                $calls[] = $args;
                return ['updated' => true, 'skipped' => []];
            }
        );
        $entityManager = new class {
            public int $clears = 0;

            public function clear(): void
            {
                $this->clears++;
            }
        };
        $logger = $this->createMock(\Laminas\Log\LoggerInterface::class);
        $services = $this->createMock(\Laminas\ServiceManager\ServiceLocatorInterface::class);
        $services->method('get')->willReturnMap([
            [GovernanceService::class, $governance],
            [ProposalStore::class, $store],
            ['Omeka\EntityManager', $entityManager],
            ['Omeka\Logger', $logger],
        ]);

        $job = new class extends GovernanceBatchJob {
            public array $args = [];

            public function getArg($name, $default = null)
            {
                return $this->args[$name] ?? $default;
            }
        };
        $job->args = [
            'ids' => [3, 4],
            'raw' => ['dcterms:creator' => ['Ana']],
            'mode' => 'fill',
            'contributor' => 'curator@example.org',
        ];
        $job->job = new \Omeka\Entity\Job();
        $job->serviceLocator = $services;

        $job->perform();

        $this->assertSame(
            [3, ['dcterms:creator' => ['Ana']], 'curator@example.org', null, true, 'batch-1', false],
            $calls[0]
        );
        $state = $store->read(1);
        $this->assertSame('completed', $state['status']);
        $this->assertSame('batch-1', $state['batch']);
        $this->assertSame(2, $state['tallies']['written']);

        array_map('unlink', glob($dir . '/*'));
        rmdir($dir);
    }
}
```

Check before writing: `grep -n "function get\b\|interface" test/stubs/Laminas/ServiceManager/ServiceLocatorInterface.php` must show `get($name)`; if the stub only declares `has()`, add `public function get($name);` to it.

- [ ] **Step 2: Run to verify it fails**

Run: `vendor/bin/phpunit -c test/phpunit.xml --filter GovernanceBatchJobTest`
Expected: FAIL (`Class "OERManager\Job\GovernanceBatchJob" not found`).

- [ ] **Step 3: Implement**

```php
<?php

declare(strict_types=1);

namespace OERManager\Job;

use OERManager\Service\Ai\ProposalStore;
use OERManager\Service\Governance\BatchProgressReporter;
use OERManager\Service\Governance\BatchRequest;
use OERManager\Service\Governance\GovernanceBatchRunner;
use OERManager\Service\GovernanceService;
use Omeka\Job\AbstractJob;

/**
 * Batch assignment of licence and authorship (TASK-028 slice 4). Thin glue:
 * the loop lives in GovernanceBatchRunner, the per-item write in
 * GovernanceService::apply(). Runs as its owner, so the native edit ACL
 * decides item by item. Doctrine's identity map is cleared every 50 items so
 * memory does not grow across thousands of writes.
 */
class GovernanceBatchJob extends AbstractJob
{
    public function perform(): void
    {
        $services = $this->getServiceLocator();
        /** @var GovernanceService $governance */
        $governance = $services->get(GovernanceService::class);
        /** @var ProposalStore $store */
        $store = $services->get(ProposalStore::class);
        $entityManager = $services->get('Omeka\EntityManager');
        $logger = $services->get('Omeka\Logger');

        $jobId = (int) $this->job->getId();
        $batch = 'batch-' . $jobId;
        $ids = array_map('intval', (array) $this->getArg('ids', []));
        $raw = (array) $this->getArg('raw', []);
        $onlyEmpty = BatchRequest::MODE_REPLACE !== (string) $this->getArg('mode', BatchRequest::MODE_FILL);
        $contributor = (string) $this->getArg('contributor', 'unknown');

        $progress = new BatchProgressReporter($store, fn (): bool => $this->shouldStop(), $jobId);
        try {
            $tallies = (new GovernanceBatchRunner())->run(
                $ids,
                static fn (int $id): array => $governance->apply($id, $raw, $contributor, null, $onlyEmpty, $batch, false),
                $progress,
                static function () use ($entityManager): void {
                    $entityManager->clear();
                },
                static function (string $message) use ($logger): void {
                    $logger->err($message);
                }
            );
            $progress->finish($tallies['stopped'] ? 'stopped' : 'completed', $tallies, $batch);
        } catch (\Throwable $e) {
            $logger->err('OERManager governance batch job ' . $jobId . ': ' . $e->getMessage());
            $progress->finish('error', ['code' => 'unexpected'], $batch);
        }
    }
}
```

- [ ] **Step 4: Run to verify it passes**

Run: `vendor/bin/phpunit -c test/phpunit.xml --filter GovernanceBatchJobTest`
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add src/Job/GovernanceBatchJob.php test/Job/GovernanceBatchJobTest.php test/stubs/Laminas/ServiceManager/ServiceLocatorInterface.php
git commit -m "feat: governance batch job wiring the runner to apply

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

---

### Task 9: `GovernanceBatchController`, route, ACL and form partial

**Files:**
- Create: `src/Controller/Admin/GovernanceBatchController.php`
- Create: `view/oer-manager/admin/governance-batch/form.phtml`
- Modify: `config/module.config.php` (controller factory, route `oer-manager-batch` under `admin.child_routes`, next to `oer-manager-stats`)
- Modify: `Module.php` (`onBootstrap`, new `allow` after the curation block)
- Test: `test/Controller/GovernanceBatchControllerTest.php`, `test/ModuleConfigTest.php` (route and factory present)

**Interfaces:**
- Consumes: `BatchRequest`, `BatchSelection`, `BatchSelectionException`, `BatchPlan`, `BatchPlanStore`, `BatchProgressReporter::KIND`, `GovernanceService::formOptions()`, `Omeka\Job\Dispatcher`, `ProposalStore`.
- Produces (JSON unless stated; every POST requires `csrf`):
  - `GET form` → terminal `ViewModel`, template `oer-manager/admin/governance-batch/form`, variables `governance` (formOptions), `csrf`, `urls` (preview/apply/status/cancel).
  - `POST preview` (`governance[...]`, `mode`, and `ids[]` **or** `scope=matching` + `query` string) → `{token, summary, writeTotal, mode, total}` or `{error, errors?}`; errors: `method`, `csrf`, `invalid` (+`errors`), `empty`, `too_many`, `class_unresolved`, `computed_truncated`.
  - `POST apply` (`token`) → `{jobId}` or `{error: plan_expired|dispatch}`.
  - `POST status` (`jobId`) → stored state, or `{status:'in_progress', done:0, total:0}` before the first report, `{status:'error', code:'job_died'|'job_<native>'}`, `{error: id|not_found|not_batch}`.
  - `POST cancel` (`jobId`) → `{stopped: true}` or `{error: id|not_found|not_batch}`.

- [ ] **Step 1: Write the failing test**

```php
<?php

declare(strict_types=1);

namespace OERManager\Test\Controller;

use OERManager\Controller\Admin\GovernanceBatchController;
use OERManager\Service\Ai\ProposalStore;
use OERManager\Service\Governance\BatchPlanStore;
use OERManager\Service\Governance\BatchSelection;
use OERManager\Service\Governance\BatchSelectionException;
use OERManager\Service\GovernanceService;
use Omeka\Api\Response;
use Omeka\Job\Dispatcher;
use PHPUnit\Framework\TestCase;

final class GovernanceBatchControllerTest extends TestCase
{
    private GovernanceBatchController $controller;
    private $selection;
    private $governance;
    private $dispatcher;
    private BatchPlanStore $plans;
    private ProposalStore $states;
    private $params;
    private $request;
    private $api;
    private string $dir;
    private string $jobStatus = 'in_progress';
    private bool $jobMissing = false;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/oer_batch_ctl_' . bin2hex(random_bytes(6));
        $this->selection = $this->createMock(BatchSelection::class);
        $this->governance = $this->createMock(GovernanceService::class);
        $this->dispatcher = $this->createMock(Dispatcher::class);
        $this->plans = new BatchPlanStore($this->dir . '/plans');
        $this->states = new ProposalStore($this->dir . '/states');
        $this->api = $this->createMock(\Omeka\Api\Manager::class);
        $this->api->method('read')->willReturnCallback(function () {
            if ($this->jobMissing) {
                throw new \RuntimeException('not yours');
            }
            $status = $this->jobStatus;
            return new Response(new class ($status) {
                public function __construct(private string $status)
                {
                }

                public function status()
                {
                    return $this->status;
                }
            });
        });
        $this->controller = new GovernanceBatchController(
            $this->selection,
            $this->plans,
            $this->governance,
            $this->dispatcher,
            $this->states,
            $this->createMock(\Laminas\Log\LoggerInterface::class)
        );
        $this->params = new class {
            public array $post = ['csrf' => 'valid'];
            public function fromPost($key = null, $default = null)
            {
                return null === $key ? $this->post : ($this->post[$key] ?? $default);
            }
            public function fromQuery($key = null, $default = null)
            {
                return $default;
            }
        };
        $this->request = new class {
            public bool $post = true;
            public function isPost()
            {
                return $this->post;
            }
        };
        $identity = new class {
            public function getId()
            {
                return 3;
            }
            public function getName()
            {
                return 'Curator';
            }
        };
        $this->controller->plugins = [
            'api' => $this->api, 'params' => $this->params, 'request' => $this->request,
            'identity' => $identity,
            'url' => new class {
                public function fromRoute($route, $params = [])
                {
                    return $route . '/' . $params['action'];
                }
            },
        ];
    }

    protected function tearDown(): void
    {
        foreach (['/plans', '/states'] as $sub) {
            array_map('unlink', glob($this->dir . $sub . '/*') ?: []);
            @rmdir($this->dir . $sub);
        }
        @rmdir($this->dir);
    }

    private function data(string $action): array
    {
        return $this->controller->{$action . 'Action'}()->getVariables();
    }

    private function validPreview(): array
    {
        $this->params->post = ['csrf' => 'valid', 'mode' => 'fill', 'ids' => ['1', '2'],
            'governance' => ['dcterms:license' => 'https://x/by/4.0/']];
        $this->selection->method('resolveIds')->willReturn([1, 2]);
        $this->selection->method('countWithValue')->willReturn(1);
        return $this->data('preview');
    }

    public function testPostActionsRejectGetAndInvalidCsrf(): void
    {
        foreach (['preview', 'apply', 'status', 'cancel'] as $action) {
            $this->request->post = false;
            $this->assertSame('method', $this->data($action)['error']);
            $this->request->post = true;
            $this->params->post = ['csrf' => 'invalid'];
            $this->assertSame('csrf', $this->data($action)['error']);
        }
    }

    public function testFormServesOptionsCsrfAndUrls(): void
    {
        $this->governance->method('formOptions')->willReturn(['options' => [], 'notices' => [], 'defaultRightsHolder' => '']);

        $view = $this->controller->formAction();

        $this->assertSame('oer-manager/admin/governance-batch/form', $view->getTemplate());
        $this->assertSame('valid', $view->getVariable('csrf'));
        $this->assertSame('admin/oer-manager-batch/preview', $view->getVariable('urls')['preview']);
    }

    public function testPreviewCountsAndReturnsAOneShotToken(): void
    {
        $result = $this->validPreview();

        $this->assertMatchesRegularExpression('/^[a-f0-9]{32}$/', $result['token']);
        $this->assertSame(['total' => 2, 'write' => 1, 'skipped_has_value' => 1], $result['summary']['dcterms:license']);
        $this->assertSame(1, $result['writeTotal']);
        $this->assertSame(2, $result['total']);
    }

    public function testPreviewRefusalsAreCodes(): void
    {
        $this->params->post = ['csrf' => 'valid', 'mode' => 'fill', 'governance' => []];
        $result = $this->data('preview');
        $this->assertSame('invalid', $result['error']);
        $this->assertSame('no-field', $result['errors']['_']);

        $this->params->post = ['csrf' => 'valid', 'mode' => 'fill', 'scope' => 'matching', 'query' => 'integrity=warning',
            'governance' => ['dcterms:creator' => ['Ana']]];
        $this->selection->method('resolveMatching')->with(['integrity' => 'warning'])
            ->willThrowException(new BatchSelectionException('computed_truncated'));
        $this->assertSame('computed_truncated', $this->data('preview')['error']);
    }

    public function testApplyTakesThePlanOnceAndDispatchesTheJob(): void
    {
        $token = $this->validPreview()['token'];
        $this->dispatcher->expects($this->once())->method('dispatch')
            ->with(\OERManager\Job\GovernanceBatchJob::class, [
                'ids' => [1, 2],
                'raw' => ['dcterms:license' => ['https://x/by/4.0/']],
                'mode' => 'fill',
                'contributor' => 'Curator',
            ])
            ->willReturn(new \Omeka\Entity\Job());

        $this->params->post = ['csrf' => 'valid', 'token' => $token];
        $this->assertSame(1, $this->data('apply')['jobId']);
        $this->assertSame('plan_expired', $this->data('apply')['error']);
    }

    public function testApplyDispatchFailureIsSanitised(): void
    {
        $token = $this->validPreview()['token'];
        $this->dispatcher->method('dispatch')->willThrowException(new \RuntimeException('secret'));
        $this->params->post = ['csrf' => 'valid', 'token' => $token];

        $this->assertSame('dispatch', $this->data('apply')['error']);
    }

    public function testStatusServesOnlyBatchStateOfAReadableJob(): void
    {
        $this->params->post = ['csrf' => 'valid', 'jobId' => 0];
        $this->assertSame('id', $this->data('status')['error']);

        $this->params->post['jobId'] = 5;
        $this->jobMissing = true;
        $this->assertSame('not_found', $this->data('status')['error']);
        $this->jobMissing = false;

        $this->assertSame('in_progress', $this->data('status')['status']);

        $this->states->write(5, ['status' => 'completed', 'payload' => []]);
        $this->assertSame('not_batch', $this->data('status')['error']);

        $this->states->write(5, ['kind' => 'governance-batch', 'status' => 'in_progress', 'done' => 25, 'total' => 100]);
        $this->assertSame(25, $this->data('status')['done']);

        $this->jobStatus = 'completed';
        $this->assertSame('job_died', $this->data('status')['code']);

        $this->states->write(5, ['kind' => 'governance-batch', 'status' => 'completed', 'batch' => 'batch-5', 'tallies' => []]);
        $this->assertSame('batch-5', $this->data('status')['batch']);
    }

    public function testCancelStopsOnlyABatchJob(): void
    {
        $this->params->post = ['csrf' => 'valid', 'jobId' => 5];
        $this->states->write(5, ['status' => 'in_progress', 'step' => 'x']);
        $this->assertSame('not_batch', $this->data('cancel')['error']);

        $this->states->write(5, ['kind' => 'governance-batch', 'status' => 'in_progress', 'done' => 0, 'total' => 1]);
        $this->dispatcher->expects($this->once())->method('stop')->with(5);
        $this->assertTrue($this->data('cancel')['stopped']);
    }
}
```

In `test/ModuleConfigTest.php` add (adapt to the file's existing `$config` accessor; look at how it asserts the `oer-manager-stats` route and copy that shape):

```php
    public function testBatchControllerAndRouteAreRegistered(): void
    {
        $config = (new \OERManager\Module())->getConfig();
        $this->assertArrayHasKey(
            \OERManager\Controller\Admin\GovernanceBatchController::class,
            $config['controllers']['factories']
        );
        $this->assertSame(
            '/oer-manager/batch[/:action]',
            $config['router']['routes']['admin']['child_routes']['oer-manager-batch']['options']['route']
        );
    }
```

- [ ] **Step 2: Run to verify it fails**

Run: `vendor/bin/phpunit -c test/phpunit.xml --filter 'GovernanceBatchControllerTest|ModuleConfigTest'`
Expected: FAIL (controller class not found; route key missing).

- [ ] **Step 3: Implement the controller**

```php
<?php

declare(strict_types=1);

namespace OERManager\Controller\Admin;

use Laminas\Log\LoggerInterface;
use Laminas\Mvc\Controller\AbstractActionController;
use Laminas\Validator\Csrf;
use Laminas\View\Model\JsonModel;
use Laminas\View\Model\ViewModel;
use OERManager\Job\GovernanceBatchJob;
use OERManager\Service\Ai\ProposalStore;
use OERManager\Service\Governance\BatchPlan;
use OERManager\Service\Governance\BatchPlanStore;
use OERManager\Service\Governance\BatchProgressReporter;
use OERManager\Service\Governance\BatchRequest;
use OERManager\Service\Governance\BatchSelection;
use OERManager\Service\Governance\BatchSelectionException;
use OERManager\Service\GovernanceService;
use Omeka\Job\Dispatcher;

/**
 * Batch assignment of licence and authorship (TASK-028 slice 4, RF-015).
 * Own controller and route, like StatsController, so the master view's
 * controller does not grow further. ACL per privilege in Module::onBootstrap,
 * same roles as `governance-apply`. Preview writes nothing; apply only
 * dispatches; the job does the writing.
 */
class GovernanceBatchController extends AbstractActionController
{
    public const CSRF_NAME = 'oer_governance_batch';
    public const ROUTE = 'admin/oer-manager-batch';

    public function __construct(
        private BatchSelection $selection,
        private BatchPlanStore $plans,
        private GovernanceService $governance,
        private Dispatcher $jobDispatcher,
        private ProposalStore $states,
        private LoggerInterface $logger
    ) {
    }

    private function csrfValidator(): Csrf
    {
        return new Csrf(['name' => self::CSRF_NAME, 'salt' => 'oermanager', 'timeout' => 3600]);
    }

    /** @return JsonModel|null the refusal, or null when the POST may proceed */
    private function guard(): ?JsonModel
    {
        if (!$this->getRequest()->isPost()) {
            return new JsonModel(['error' => 'method']);
        }
        if (!$this->csrfValidator()->isValid((string) $this->params()->fromPost('csrf'))) {
            return new JsonModel(['error' => 'csrf']);
        }
        return null;
    }

    public function formAction()
    {
        $view = new ViewModel([
            'governance' => $this->governance->formOptions(),
            'csrf' => $this->csrfValidator()->getHash(),
            'urls' => [
                'preview' => $this->url()->fromRoute(self::ROUTE, ['action' => 'preview']),
                'apply' => $this->url()->fromRoute(self::ROUTE, ['action' => 'apply']),
                'status' => $this->url()->fromRoute(self::ROUTE, ['action' => 'status']),
                'cancel' => $this->url()->fromRoute(self::ROUTE, ['action' => 'cancel']),
                // Failed ids in the result link to their item; `__ID__` is replaced client-side.
                'item' => $this->url()->fromRoute('admin/id', ['controller' => 'item', 'action' => 'show', 'id' => '__ID__']),
            ],
        ]);
        $view->setTemplate('oer-manager/admin/governance-batch/form');
        $view->setTerminal(true);
        return $view;
    }

    public function previewAction()
    {
        if ($refusal = $this->guard()) {
            return $refusal;
        }
        $request = BatchRequest::fromPost(
            $this->params()->fromPost('governance', []),
            $this->params()->fromPost('mode')
        );
        if (!$request->isValid()) {
            return new JsonModel(['error' => 'invalid', 'errors' => $request->errors]);
        }

        try {
            if ('matching' === $this->params()->fromPost('scope')) {
                parse_str((string) $this->params()->fromPost('query', ''), $query);
                $ids = $this->selection->resolveMatching($query);
            } else {
                $ids = $this->selection->resolveIds((array) $this->params()->fromPost('ids', []));
            }
            $haveValue = [];
            foreach (array_keys($request->raw) as $term) {
                $haveValue[$term] = $this->selection->countWithValue($ids, $term);
            }
        } catch (BatchSelectionException $e) {
            return new JsonModel(['error' => $e->reason]);
        } catch (\Exception $e) {
            $this->logger->err('OERManager governance batch preview: ' . $e->getMessage());
            return new JsonModel(['error' => 'unexpected']);
        }

        $plan = new BatchPlan((int) $this->identity()->getId(), $ids, $request->raw, $request->mode);
        $summary = $plan->summary($haveValue);
        $this->plans->sweepOld();
        return new JsonModel([
            'token' => $this->plans->put($plan),
            'summary' => $summary,
            'writeTotal' => BatchPlan::writeTotal($summary),
            'mode' => $request->mode,
            'total' => count($ids),
        ]);
    }

    public function applyAction()
    {
        if ($refusal = $this->guard()) {
            return $refusal;
        }
        $identity = $this->identity();
        $plan = $this->plans->take((string) $this->params()->fromPost('token', ''), (int) $identity->getId());
        if (null === $plan) {
            return new JsonModel(['error' => 'plan_expired']);
        }
        $this->states->sweepOld(86400);
        try {
            $job = $this->jobDispatcher->dispatch(GovernanceBatchJob::class, [
                'ids' => $plan->ids,
                'raw' => $plan->raw,
                'mode' => $plan->mode,
                'contributor' => (string) $identity->getName(),
            ]);
        } catch (\Exception $e) {
            $this->logger->err('OERManager governance batch dispatch: ' . $e->getMessage());
            return new JsonModel(['error' => 'dispatch']);
        }
        return new JsonModel(['jobId' => (int) $job->getId()]);
    }

    public function statusAction()
    {
        if ($refusal = $this->guard()) {
            return $refusal;
        }
        [$job, $state, $refusal] = $this->batchJob();
        if ($refusal) {
            return $refusal;
        }
        $native = (string) $job->status();
        $finished = in_array($native, ['completed', 'error', 'stopped'], true);
        if (null === $state) {
            return new JsonModel($finished
                ? ['status' => 'error', 'code' => 'job_' . $native]
                : ['status' => 'in_progress', 'done' => 0, 'total' => 0]);
        }
        // A finished job whose state still says in_progress died without
        // writing its outcome (OOM, kill): same zombie rule as the AI propose.
        if ($finished && 'in_progress' === ($state['status'] ?? '')) {
            return new JsonModel(['status' => 'error', 'code' => 'job_died']);
        }
        return new JsonModel($state);
    }

    public function cancelAction()
    {
        if ($refusal = $this->guard()) {
            return $refusal;
        }
        [$job, , $refusal] = $this->batchJob();
        if ($refusal) {
            return $refusal;
        }
        $this->jobDispatcher->stop((int) $this->params()->fromPost('jobId'));
        return new JsonModel(['stopped' => true]);
    }

    /**
     * The posted job, readable by this user (the native job ACL restricts it
     * to its owner and admins), and its stored state only if it is a batch.
     *
     * @return array{0:mixed,1:?array,2:?JsonModel}
     */
    private function batchJob(): array
    {
        $jobId = (int) $this->params()->fromPost('jobId');
        if ($jobId <= 0) {
            return [null, null, new JsonModel(['error' => 'id'])];
        }
        try {
            $job = $this->api()->read('jobs', $jobId)->getContent();
        } catch (\Exception $e) {
            return [null, null, new JsonModel(['error' => 'not_found'])];
        }
        $state = $this->states->read($jobId);
        if (null !== $state && BatchProgressReporter::KIND !== ($state['kind'] ?? null)) {
            return [null, null, new JsonModel(['error' => 'not_batch'])];
        }
        return [$job, $state, null];
    }
}
```

Note on `cancelAction`: a job id with no state yet is accepted (the batch was just dispatched); a job id whose state belongs to another kind is refused.

`view/oer-manager/admin/governance-batch/form.phtml`:

```php
<?php
/**
 * Batch licence and authorship form (TASK-028 slice 4), served into the
 * master view's batch sidebar. The widgets are built by ui/governanceBatch.js
 * from `data-governance`, the same shape the item panel receives.
 *
 * @var \Laminas\View\Renderer\PhpRenderer $this
 * @var array $governance
 * @var string $csrf
 * @var array $urls
 */
$escape = $this->plugin('escapeHtml');
$translate = $this->plugin('translate');
$payload = ['values' => []] + $governance;
?>
<div class="oer-batch-governance"
    data-governance="<?php echo $escape(json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)); ?>"
    data-csrf="<?php echo $escape($csrf); ?>"
    data-preview-url="<?php echo $escape($urls['preview']); ?>"
    data-apply-url="<?php echo $escape($urls['apply']); ?>"
    data-status-url="<?php echo $escape($urls['status']); ?>"
    data-cancel-url="<?php echo $escape($urls['cancel']); ?>"
    data-item-url="<?php echo $escape($urls['item']); ?>">
    <h3><?php echo $translate('Licencia y autoría en lote'); /* @translate */ ?></h3>
    <p class="oer-batch-target"></p>
    <div class="oer-batch-fields"></div>
    <fieldset class="oer-batch-mode">
        <legend><?php echo $translate('Valores existentes'); /* @translate */ ?></legend>
        <label><input type="radio" name="oer-batch-mode" value="fill" checked> <?php echo $translate('Rellenar solo vacíos'); /* @translate */ ?></label>
        <label><input type="radio" name="oer-batch-mode" value="replace"> <?php echo $translate('Sustituir'); /* @translate */ ?></label>
    </fieldset>
    <p class="oer-batch-error" hidden></p>
    <div class="oer-batch-preview" hidden></div>
    <div class="oer-batch-actions">
        <button type="button" class="button oer-batch-preview-button"><?php echo $translate('Previsualizar'); /* @translate */ ?></button>
    </div>
    <div class="oer-batch-progress" hidden></div>
</div>
```

`config/module.config.php` — controller factory (next to `StatsController`):

```php
            Controller\Admin\GovernanceBatchController::class => function ($container) {
                return new Controller\Admin\GovernanceBatchController(
                    $container->get(Service\Governance\BatchSelection::class),
                    $container->get(Service\Governance\BatchPlanStore::class),
                    $container->get(Service\GovernanceService::class),
                    $container->get('Omeka\Job\Dispatcher'),
                    $container->get(Service\Ai\ProposalStore::class),
                    $container->get('Omeka\Logger')
                );
            },
```

Route, as a sibling of `oer-manager-stats` in `admin.child_routes` (copy that entry's exact keys; only the name, route string and controller differ):

```php
                    'oer-manager-batch' => [
                        'type' => \Laminas\Router\Http\Segment::class,
                        'options' => [
                            'route' => '/oer-manager/batch[/:action]',
                            'constraints' => ['action' => '[a-zA-Z][a-zA-Z0-9_-]*'],
                            'defaults' => [
                                '__NAMESPACE__' => 'OERManager\Controller\Admin',
                                'controller' => Controller\Admin\GovernanceBatchController::class,
                                'action' => 'form',
                            ],
                        ],
                    ],
```

`Module.php`, after the curation `allow` block:

```php
        // Lote de licencia y autoría (TASK-028 slice 4): los mismos roles que
        // `governance-apply`. Escribir en lote no es más privilegiado que
        // escribir en un item; el ACL nativo de edición decide item a item
        // dentro del Job, que corre como su dueño.
        $acl->allow(
            ['editor', 'site_admin', 'reviewer'],
            [Controller\Admin\GovernanceBatchController::class],
            ['form', 'preview', 'apply', 'status', 'cancel']
        );
```

- [ ] **Step 4: Run to verify it passes**

Run: `vendor/bin/phpunit -c test/phpunit.xml --filter 'GovernanceBatchControllerTest|ModuleConfigTest'`
Expected: PASS. Then `make lint`.

- [ ] **Step 5: Commit**

```bash
git add src/Controller/Admin/GovernanceBatchController.php view/oer-manager/admin/governance-batch/form.phtml config/module.config.php Module.php test/Controller/GovernanceBatchControllerTest.php test/ModuleConfigTest.php
git commit -m "feat: batch governance endpoints, route and ACL

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

---

### Task 10: Extract the governance field widgets (no behaviour change)

**Files:**
- Create: `asset/js/ui/governanceFields.js`
- Modify: `asset/js/ui/governance.js` (lines 96-314 move out; imports change)

**Interfaces:**
- Produces (all exported from `ui/governanceFields.js`, unchanged signatures and DOM): `firstEntryRaw(entries)`, `buildFieldError()`, `buildTextInput(value)`, `buildVocabSelect(vocabOptions, currentRaw, currentEntry)`, `buildLicenceHint(select, vocabOptions)`, `buildVocabField(term, label, governance, vocabKey) → {el, initial}`, `buildTextField(term, label, value) → {el, initial}`, `buildAuthorRow(value)`, `buildAuthorsField(governance) → {el, initial}`, `rightsHolderInitial(governance)`, `addAuthorRow(formEl)`.

- [ ] **Step 1: Move the functions.** Cut `firstEntryRaw`, `buildFieldError`, `buildTextInput`, `buildVocabSelect`, `buildLicenceHint`, `buildVocabField`, `buildTextField`, `buildAuthorRow`, `buildAuthorsField`, `rightsHolderInitial` (with their docblocks) and `addAuthorRow` from `ui/governance.js` into `ui/governanceFields.js`, prefixing each with `export`. The new file starts with:

```js
import { TERMS, licenceState } from '../core/governanceModel.js';

/**
 * Governance field widgets (RF-015), shared by the item panel's form
 * (`ui/governance.js`, slice 3b) and the batch form (`ui/governanceBatch.js`,
 * slice 4). Moved here verbatim so both editors render and read fields the
 * same way; no behaviour lives here that is not already in slice 3b.
 */
```

In `ui/governance.js`, replace the first import line with:

```js
import { TERMS, buildPayload, validate, rows } from '../core/governanceModel.js';
import {
    firstEntryRaw,
    buildVocabField,
    buildTextField,
    buildAuthorsField,
    rightsHolderInitial,
    addAuthorRow
} from './governanceFields.js';
```

Keep `licenceState` imported in `ui/governance.js` only if `grep -n licenceState asset/js/ui/governance.js` still finds a use after the move.

- [ ] **Step 2: Verify nothing broke**

Run: `node --check asset/js/ui/governanceFields.js && node --check asset/js/ui/governance.js && make test-js`
Expected: syntax OK; all JS tests pass (the count is unchanged).
Run: `grep -n "function buildVocabField\|function buildAuthorsField" asset/js/ui/governance.js`
Expected: no output (moved, not duplicated).

- [ ] **Step 3: Commit**

```bash
git add asset/js/ui/governanceFields.js asset/js/ui/governance.js
git commit -m "refactor(js): share the governance field widgets

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

---

### Task 11: `core/governanceBatchModel.js`

**Files:**
- Create: `asset/js/core/governanceBatchModel.js`
- Test: `test/js/governanceBatchModel.test.js`

**Interfaces:**
- Consumes: `buildPayload`, `validate`, `TERMS` from `core/governanceModel.js`.
- Produces:
  - `BATCH_KEYS = ['licence', 'creator', 'publisher', 'rightsHolder']`
  - `validateBatch(ticked) → {key|'_': code}` where `ticked` is `{licence?: string, creator?: string[], publisher?: string, rightsHolder?: string}` (only ticked keys present); codes `no-field`, `required`, plus `validate()`'s `not-http-uri` / `too-many` keyed by **term**.
  - `previewPairs({ticked, mode, csrf, selection}) → Array<[string,string]>` with `selection` = `{scope:'ids', ids:string[]}` or `{scope:'matching', query:string}`.
  - `selectionAfterToggle(state, event)` — pure selection state machine: state `{scope:'ids'|'matching', pageCount:number, checked:number, totalMatching:number}`; events `{type:'check', checked}`, `{type:'select-matching'}`, `{type:'clear'}`; returns next state.
  - `stripModel(state) → {visible:boolean, text:string, action:'select-matching'|'clear'|null, count:number}`.
  - `previewModel(response) → {rows: Array<{term, write, other, otherKind:'skipped'|'overwrite'}>, canApply:boolean, needsOverwriteConfirm:boolean, overwriteTotal:number, writeTotal:number}`.
  - `resultModel(state) → {finished:boolean, percent:number, tallies?:object, failed?:Array, batch?:string, status:string}`.

- [ ] **Step 1: Write the failing tests**

```js
import test from 'node:test';
import assert from 'node:assert/strict';
import {
    validateBatch,
    previewPairs,
    selectionAfterToggle,
    stripModel,
    previewModel,
    resultModel
} from '../../asset/js/core/governanceBatchModel.js';

test('a batch needs at least one ticked field, each with a value', () => {
    assert.deepEqual(validateBatch({}), { _: 'no-field' });
    assert.deepEqual(validateBatch({ licence: '  ' }), { 'dcterms:license': 'required' });
    assert.deepEqual(validateBatch({ creator: ['', ' '] }), { 'dcterms:creator': 'required' });
    assert.deepEqual(validateBatch({ licence: 'ccbysa' }), { 'dcterms:license': 'not-http-uri' });
    assert.deepEqual(validateBatch({ creator: ['Ana'], publisher: 'ACME' }), {});
});

test('preview pairs carry only ticked fields, the mode and the selection', () => {
    const ids = previewPairs({
        ticked: { creator: ['Ana', ' ', 'Luis'], licence: 'https://x/by/4.0/' },
        mode: 'replace',
        csrf: 'h',
        selection: { scope: 'ids', ids: ['4', '2'] }
    });
    assert.deepEqual(ids, [
        ['governance[dcterms:license]', 'https://x/by/4.0/'],
        ['governance[dcterms:creator][]', 'Ana'],
        ['governance[dcterms:creator][]', 'Luis'],
        ['mode', 'replace'],
        ['csrf', 'h'],
        ['ids[]', '4'],
        ['ids[]', '2']
    ]);

    const matching = previewPairs({
        ticked: { publisher: 'ACME' },
        mode: 'fill',
        csrf: 'h',
        selection: { scope: 'matching', query: 'title=x&integrity=warning' }
    });
    assert.deepEqual(matching.slice(-2), [['scope', 'matching'], ['query', 'title=x&integrity=warning']]);
});

test('selecting every row on the page offers every matching REA, and back', () => {
    let state = { scope: 'ids', pageCount: 25, checked: 0, totalMatching: 2480 };
    assert.equal(stripModel(state).visible, false);

    state = selectionAfterToggle(state, { type: 'check', checked: 25 });
    assert.deepEqual(stripModel(state), { visible: true, text: 'page-all', action: 'select-matching', count: 2480 });

    state = selectionAfterToggle(state, { type: 'select-matching' });
    assert.equal(state.scope, 'matching');
    assert.deepEqual(stripModel(state), { visible: true, text: 'matching-all', action: 'clear', count: 2480 });

    state = selectionAfterToggle(state, { type: 'check', checked: 24 });
    assert.equal(state.scope, 'ids', 'unticking a row falls back to ids');

    state = selectionAfterToggle({ ...state, scope: 'matching' }, { type: 'clear' });
    assert.deepEqual(state, { scope: 'ids', pageCount: 25, checked: 0, totalMatching: 2480 });
});

test('no strip when the page already holds every matching REA', () => {
    const state = selectionAfterToggle({ scope: 'ids', pageCount: 19, checked: 0, totalMatching: 19 }, { type: 'check', checked: 19 });
    assert.equal(stripModel(state).visible, false);
});

test('preview model gates apply and the overwrite confirmation', () => {
    const fill = previewModel({
        mode: 'fill',
        writeTotal: 3,
        summary: { 'dcterms:license': { total: 4, write: 3, skipped_has_value: 1 } }
    });
    assert.deepEqual(fill.rows, [{ term: 'dcterms:license', write: 3, other: 1, otherKind: 'skipped' }]);
    assert.equal(fill.canApply, true);
    assert.equal(fill.needsOverwriteConfirm, false);

    const replace = previewModel({
        mode: 'replace',
        writeTotal: 4,
        summary: {
            'dcterms:license': { total: 4, write: 4, overwrite: 2 },
            'dcterms:creator': { total: 4, write: 4, overwrite: 1 }
        }
    });
    assert.equal(replace.needsOverwriteConfirm, true);
    assert.equal(replace.overwriteTotal, 3);

    assert.equal(previewModel({ mode: 'fill', writeTotal: 0, summary: {} }).canApply, false);
});

test('result model follows the job state', () => {
    assert.deepEqual(resultModel({ status: 'in_progress', done: 25, total: 100 }), { finished: false, percent: 25, status: 'in_progress' });
    assert.deepEqual(resultModel({ status: 'in_progress', done: 0, total: 0 }), { finished: false, percent: 0, status: 'in_progress' });
    const done = resultModel({
        status: 'completed',
        batch: 'batch-9',
        tallies: { written: 2, skipped: 1, unchanged: 0, failed: [{ id: 7, code: 'denied' }], done: 4, total: 4 }
    });
    assert.equal(done.finished, true);
    assert.equal(done.batch, 'batch-9');
    assert.deepEqual(done.failed, [{ id: 7, code: 'denied' }]);
    assert.equal(resultModel({ status: 'error', code: 'job_died' }).finished, true);
});
```

- [ ] **Step 2: Run to verify it fails**

Run: `node --test test/js/governanceBatchModel.test.js`
Expected: FAIL (`Cannot find module .../governanceBatchModel.js`).

- [ ] **Step 3: Implement**

```js
import { TERMS, buildPayload, validate } from './governanceModel.js';

/**
 * Pure model of the batch licence and authorship form (TASK-028 slice 4).
 * The server re-validates everything (BatchRequest, BatchSelection); this
 * only lets the form warn early and decide what to show. No DOM.
 */

export const BATCH_KEYS = ['licence', 'creator', 'publisher', 'rightsHolder'];

const KEY_TERM = {
    licence: TERMS.LICENCE,
    creator: TERMS.CREATOR,
    publisher: TERMS.PUBLISHER,
    rightsHolder: TERMS.RIGHTS_HOLDER
};

/** Mirrors BatchRequest: no-field, required, then GovernanceFields' own rules. */
export function validateBatch(ticked) {
    const keys = BATCH_KEYS.filter((key) => Object.prototype.hasOwnProperty.call(ticked, key));
    if (!keys.length) {
        return { _: 'no-field' };
    }
    const payload = buildPayload(ticked);
    const errors = {};
    keys.forEach((key) => {
        if (!payload[KEY_TERM[key]].length) {
            errors[KEY_TERM[key]] = 'required';
        }
    });
    return Object.assign(validate(ticked), errors);
}

export function previewPairs({ ticked, mode, csrf, selection }) {
    const payload = buildPayload(ticked);
    const pairs = [];
    BATCH_KEYS.forEach((key) => {
        const term = KEY_TERM[key];
        if (!payload[term]) {
            return;
        }
        if (TERMS.CREATOR === term) {
            payload[term].forEach((value) => pairs.push([`governance[${term}][]`, value]));
        } else {
            payload[term].forEach((value) => pairs.push([`governance[${term}]`, value]));
        }
    });
    pairs.push(['mode', mode], ['csrf', csrf]);
    if ('matching' === selection.scope) {
        pairs.push(['scope', 'matching'], ['query', selection.query]);
    } else {
        selection.ids.forEach((id) => pairs.push(['ids[]', String(id)]));
    }
    return pairs;
}

export function selectionAfterToggle(state, event) {
    if ('clear' === event.type) {
        return { ...state, scope: 'ids', checked: 0 };
    }
    if ('select-matching' === event.type) {
        return { ...state, scope: 'matching' };
    }
    const checked = event.checked;
    const scope = 'matching' === state.scope && checked === state.pageCount ? 'matching' : 'ids';
    return { ...state, checked, scope };
}

export function stripModel(state) {
    if ('matching' === state.scope) {
        return { visible: true, text: 'matching-all', action: 'clear', count: state.totalMatching };
    }
    const pageFull = state.pageCount > 0 && state.checked === state.pageCount;
    if (pageFull && state.totalMatching > state.pageCount) {
        return { visible: true, text: 'page-all', action: 'select-matching', count: state.totalMatching };
    }
    return { visible: false, text: '', action: null, count: 0 };
}

export function previewModel(response) {
    const replace = 'replace' === response.mode;
    const rows = Object.entries(response.summary || {}).map(([term, field]) => ({
        term,
        write: field.write,
        other: replace ? field.overwrite : field.skipped_has_value,
        otherKind: replace ? 'overwrite' : 'skipped'
    }));
    const overwriteTotal = replace ? rows.reduce((sum, row) => sum + row.other, 0) : 0;
    return {
        rows,
        canApply: response.writeTotal > 0,
        needsOverwriteConfirm: overwriteTotal > 0,
        overwriteTotal,
        writeTotal: response.writeTotal
    };
}

export function resultModel(state) {
    if ('in_progress' === state.status) {
        const percent = state.total > 0 ? Math.floor((state.done / state.total) * 100) : 0;
        return { finished: false, percent, status: 'in_progress' };
    }
    const tallies = state.tallies || {};
    return {
        finished: true,
        percent: 100,
        status: state.status,
        code: state.code,
        batch: state.batch,
        tallies,
        failed: tallies.failed || []
    };
}
```

- [ ] **Step 4: Run to verify it passes**

Run: `node --test test/js/governanceBatchModel.test.js && make test-js`
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add asset/js/core/governanceBatchModel.js test/js/governanceBatchModel.test.js
git commit -m "feat(js): pure model for the batch governance form

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

---

### Task 12: Batch UI in the master view

**Files:**
- Create: `asset/js/ui/governanceBatch.js`
- Modify: `asset/js/config.js`, `asset/js/main.js`, `asset/js/ui/visibility.js` (dispatch a selection event), `view/oer-manager/admin/index/index.phtml` (button, strip, sidebar, data attributes), `src/Controller/Admin/IndexController.php` (`indexAction`: `totalResults`, `canBatchGovernance`), `asset/css/oer-master-view.css`
- Test: `test/Controller/IndexControllerTest.php`

**Interfaces:**
- Consumes: `core/governanceBatchModel.js` (Task 11), `ui/governanceFields.js` (Task 10), the batch endpoints (Task 9).
- Produces: event `oer:selection-changed` on `document`, `detail: {checked: number, pageCount: number}`, dispatched by `visibility.js::refreshSelectionUi()`; `readConfig()` gains `totalResults`, `canBatchGovernance`, `batchFormUrl`.

- [ ] **Step 1: Write the failing controller test** (append to `IndexControllerTest`)

```php
    public function testIndexExposesTheMatchingTotalAndTheBatchPermission(): void
    {
        $this->dependencies['acl']->method('userIsAllowed')->willReturn(true);

        $view = $this->controller->indexAction();

        $this->assertSame(1, $view->getVariable('totalResults'));
        $this->assertTrue($view->getVariable('canBatchGovernance'));
    }
```

(`setUp()` stubs `search` with `new Response([$this->item], 1)`, so the total is 1.)

- [ ] **Step 2: Run to verify it fails**

Run: `vendor/bin/phpunit -c test/phpunit.xml --filter testIndexExposesTheMatchingTotal`
Expected: FAIL (`null` is not `1`).

- [ ] **Step 3: Implement the server side.** In `IndexController::indexAction()`, set `$totalResults = $filtered['total'];` in the computed branch and `$totalResults = $response->getTotalResults();` in the plain branch, next to each `$this->paginator(...)` call, and before `return $view;` add:

```php
        // Lote de gobernanza (TASK-028 slice 4): la franja «seleccionar los N
        // que coinciden» necesita el total del filtro, y el botón solo se pinta
        // a quien podría usarlo (el endpoint vuelve a comprobarlo).
        $view->setVariable('totalResults', $totalResults);
        $view->setVariable(
            'canBatchGovernance',
            $this->acl->userIsAllowed(GovernanceBatchController::class, 'preview')
        );
```

with `use OERManager\Controller\Admin\GovernanceBatchController;` — same namespace, so no `use` is needed; reference it as `GovernanceBatchController::class`.

In `index.phtml`:
- in the batch bar, after the two visibility buttons (line ~112):

```php
    <?php if (!empty($canBatchGovernance)): ?>
    <a href="#" class="button oer-batch-governance-open sidebar-content"
       data-sidebar-selector="#oer-batch-sidebar"
       data-sidebar-content-url="<?php echo $escape($this->url('admin/oer-manager-batch', ['action' => 'form'])); ?>"><?php echo $translate('Licencia y autoría…'); /* @translate */ ?></a>
    <?php endif; ?>
```

- just above the `<table id="oer-master-view-table"` line:

```php
<p class="oer-select-matching-strip" hidden
   data-page-all="<?php echo $escape($translate('Los %1$s REA de esta página están seleccionados.')); /* @translate */ ?>"
   data-select-matching="<?php echo $escape($translate('Seleccionar los %1$s que coinciden con el filtro')); /* @translate */ ?>"
   data-matching-all="<?php echo $escape($translate('Los %1$s REA que coinciden con el filtro están seleccionados.')); /* @translate */ ?>"
   data-clear="<?php echo $escape($translate('Deshacer selección')); /* @translate */ ?>">
    <span class="oer-select-matching-text"></span>
    <button type="button" class="oer-select-matching-action"></button>
</p>
```

- on the table, add `data-total-results="<?php echo (int) ($totalResults ?? 0); ?>"` and `data-can-batch-governance="<?php echo !empty($canBatchGovernance) ? '1' : '0'; ?>"`.
- after `<div id="oer-detail-sidebar" class="sidebar">…</div>`, a second sidebar with the same inner markup the core expects (copy `#oer-detail-sidebar`'s children exactly, changing only the id to `oer-batch-sidebar`).

In `asset/css/oer-master-view.css`, find the rules for `#oer-detail-sidebar` and extend each selector to `#oer-detail-sidebar, #oer-batch-sidebar`; then add:

```css
.oer-select-matching-strip {
    margin: 0 0 .5em;
    padding: .4em .75em;
    background: var(--oer-surface, #f6f6f6);
}
.oer-batch-governance .oer-batch-field-toggle { display: block; margin-top: .75em; font-weight: bold; }
.oer-batch-governance .oer-batch-preview table { width: 100%; }
.oer-batch-governance .oer-batch-overwrite { color: var(--oer-warn, #955c0d); }
.oer-batch-governance progress { width: 100%; }
```

In `config.js`, add to the frozen object:

```js
        totalResults: Number(d.totalResults || 0),
        canBatchGovernance: flag(d.canBatchGovernance),
```

In `visibility.js`, at the end of `refreshSelectionUi()` (before its closing brace, after the counter update, and also reached when `bar` is null — move the dispatch above the `if (!bar) return;` line):

```js
    document.dispatchEvent(new CustomEvent('oer:selection-changed', {
        detail: { checked: selected.length, pageCount: all.length }
    }));
```

and export `selectedIds` and `refreshSelectionUi` (prefix both declarations with `export`), which `governanceBatch.js` imports.

In `main.js`, add `import { initGovernanceBatch } from './ui/governanceBatch.js';` and, where the other `init*` calls run with `config`, `initGovernanceBatch(config);`.

- [ ] **Step 4: Implement `ui/governanceBatch.js`**

```js
import {
    validateBatch,
    previewPairs,
    selectionAfterToggle,
    stripModel,
    previewModel,
    resultModel
} from '../core/governanceBatchModel.js';
import { TERMS } from '../core/governanceModel.js';
import { TERM_LABELS } from '../core/drawerModel.js';
import { buildVocabField, buildTextField, buildAuthorsField, addAuthorRow } from './governanceFields.js';
import { selectedIds, refreshSelectionUi } from './visibility.js';

/**
 * Batch licence and authorship (TASK-028 slice 4): the «select all matching»
 * strip, the form served into `#oer-batch-sidebar`, preview → confirm → job
 * progress, and reattach after a reload. Decisions live in
 * core/governanceBatchModel.js; this file only touches the DOM and the network.
 */

const JOB_KEY = 'oer-governance-batch-job';
const POLL_MS = 2000;

const FIELD_ERROR_TEXT = {
    required: 'Escribe o elige un valor.',
    'not-http-uri': 'Debe ser una URL http o https.',
    'too-many': 'Solo se admite un valor.'
};

const PREVIEW_ERROR_TEXT = {
    'no-field': 'Marca al menos un campo.',
    mode: 'Modo no válido.',
    empty: 'La selección no contiene REA.',
    too_many: 'La selección supera el máximo permitido por lote; acota el filtro.',
    class_unresolved: 'No se encuentra la clase de REA en esta instalación.',
    computed_truncated: 'El filtro de integridad o de anclaje supera el tope de cálculo; combínalo con otro filtro.',
    plan_expired: 'La previsualización ha caducado. Vuelve a previsualizar.',
    dispatch: 'No se pudo iniciar el lote.',
    csrf: 'La sesión ha caducado. Recarga la página.',
    unexpected: 'Error inesperado. Consulta el registro.'
};

const FAILURE_TEXT = {
    denied: 'sin permiso',
    not_found: 'ya no existe',
    invalid: 'valor no válido',
    unexpected: 'error inesperado'
};

let selection = { scope: 'ids', pageCount: 0, checked: 0, totalMatching: 0 };

function t(text) {
    return Omeka.jsTranslate(text);
}

function safeStorage(action) {
    try {
        return action(window.localStorage);
    } catch (error) {
        return null;
    }
}

function post(url, pairs) {
    const body = new URLSearchParams();
    pairs.forEach(([name, value]) => body.append(name, value));
    return fetch(url, { method: 'POST', headers: { 'X-Requested-With': 'XMLHttpRequest' }, body })
        .then((response) => response.json())
        .catch(() => ({ error: 'unexpected' }));
}

function currentQuery() {
    const params = new URLSearchParams(window.location.search);
    params.delete('page');
    return params.toString();
}

function paintStrip() {
    const strip = document.querySelector('.oer-select-matching-strip');
    if (!strip) {
        return;
    }
    const model = stripModel(selection);
    strip.hidden = !model.visible;
    if (!model.visible) {
        return;
    }
    const text = 'matching-all' === model.text ? strip.dataset.matchingAll : strip.dataset.pageAll;
    strip.querySelector('.oer-select-matching-text').textContent =
        text.replace('%1$s', 'matching-all' === model.text ? model.count : selection.pageCount);
    const action = strip.querySelector('.oer-select-matching-action');
    action.dataset.action = model.action;
    action.textContent = ('clear' === model.action ? strip.dataset.clear : strip.dataset.selectMatching)
        .replace('%1$s', model.count);
}

function targetText() {
    return 'matching' === selection.scope
        ? t('Se aplicará a los %1$s REA que coinciden con el filtro.').replace('%1$s', selection.totalMatching)
        : t('Se aplicará a %1$s REA seleccionados.').replace('%1$s', selectedIds().length);
}

function buildFields(root, governance) {
    const container = root.querySelector('.oer-batch-fields');
    const specs = [
        { key: 'licence', build: () => buildVocabField(TERMS.LICENCE, 'Licencia', governance, 'licence') },
        { key: 'creator', build: () => buildAuthorsField(governance) },
        { key: 'publisher', build: () => buildVocabField(TERMS.PUBLISHER, 'Editor', governance, 'publisher') },
        {
            key: 'rightsHolder',
            build: () => buildTextField(TERMS.RIGHTS_HOLDER, 'Titular de derechos', String(governance.defaultRightsHolder || ''))
        }
    ];
    specs.forEach(({ key, build }) => {
        const toggle = document.createElement('label');
        toggle.className = 'oer-batch-field-toggle';
        const box = document.createElement('input');
        box.type = 'checkbox';
        box.className = 'oer-batch-assign';
        box.dataset.key = key;
        toggle.appendChild(box);
        toggle.appendChild(document.createTextNode(` ${t('Asignar este campo')}`));
        const field = build().el;
        field.hidden = true;
        field.dataset.key = key;
        box.addEventListener('change', () => {
            field.hidden = !box.checked;
        });
        container.appendChild(toggle);
        container.appendChild(field);
    });
}

function collectTicked(root) {
    const ticked = {};
    root.querySelectorAll('.oer-batch-assign:checked').forEach((box) => {
        const field = root.querySelector(`.oer-governance-field[data-key="${box.dataset.key}"]`);
        if ('creator' === box.dataset.key) {
            ticked.creator = Array.from(field.querySelectorAll('.oer-governance-author-input')).map((input) => input.value);
        } else {
            ticked[box.dataset.key] = field.querySelector('.oer-governance-input').value;
        }
    });
    return ticked;
}

function showErrors(root, errors) {
    root.querySelectorAll('.oer-governance-field-error').forEach((p) => {
        p.hidden = true;
    });
    const general = root.querySelector('.oer-batch-error');
    general.hidden = true;
    Object.entries(errors).forEach(([key, code]) => {
        const field = '_' === key ? null : root.querySelector(`.oer-governance-field[data-term="${key}"]`);
        const target = field ? field.querySelector('.oer-governance-field-error') : general;
        target.textContent = t(FIELD_ERROR_TEXT[code] || PREVIEW_ERROR_TEXT[code] || PREVIEW_ERROR_TEXT.unexpected);
        target.hidden = false;
    });
}

function renderPreview(root, response, onApply) {
    const box = root.querySelector('.oer-batch-preview');
    box.textContent = '';
    const model = previewModel(response);
    const table = document.createElement('table');
    model.rows.forEach((row) => {
        const tr = document.createElement('tr');
        const label = document.createElement('th');
        label.textContent = t(TERM_LABELS[row.term] || row.term);
        const write = document.createElement('td');
        write.textContent = t('se escribirá en %1$s').replace('%1$s', row.write);
        const other = document.createElement('td');
        other.textContent = 'overwrite' === row.otherKind
            ? t('se sobrescribirán %1$s').replace('%1$s', row.other)
            : t('ya tenían valor %1$s (se omiten)').replace('%1$s', row.other);
        if ('overwrite' === row.otherKind && row.other > 0) {
            other.className = 'oer-batch-overwrite';
        }
        tr.append(label, write, other);
        table.appendChild(tr);
    });
    box.appendChild(table);

    if (!model.canApply) {
        const none = document.createElement('p');
        none.textContent = t('No hay nada que escribir: todos los REA ya tienen valor en los campos marcados.');
        box.appendChild(none);
        box.hidden = false;
        return;
    }

    const apply = document.createElement('button');
    apply.type = 'button';
    apply.className = 'button oer-batch-apply';
    apply.textContent = t('Aplicar a %1$s REA').replace('%1$s', response.total);
    if (model.needsOverwriteConfirm) {
        const confirm = document.createElement('label');
        const box2 = document.createElement('input');
        box2.type = 'checkbox';
        confirm.appendChild(box2);
        confirm.appendChild(document.createTextNode(
            ` ${t('Entiendo que se sobrescribirán %1$s valores existentes').replace('%1$s', model.overwriteTotal)}`
        ));
        apply.disabled = true;
        box2.addEventListener('change', () => {
            apply.disabled = !box2.checked;
        });
        box.appendChild(confirm);
    }
    apply.addEventListener('click', () => onApply(response.token));
    box.appendChild(apply);
    box.hidden = false;
}

function renderProgress(root, state, urls, csrf) {
    const box = root.querySelector('.oer-batch-progress');
    box.hidden = false;
    box.textContent = '';
    const model = resultModel(state);
    if (!model.finished) {
        const bar = document.createElement('progress');
        bar.max = 100;
        bar.value = model.percent;
        const label = document.createElement('p');
        label.textContent = state.total
            ? t('%1$s de %2$s REA').replace('%1$s', state.done).replace('%2$s', state.total)
            : t('Iniciando…');
        const cancel = document.createElement('button');
        cancel.type = 'button';
        cancel.textContent = t('Cancelar');
        cancel.addEventListener('click', () => {
            cancel.disabled = true;
            post(urls.cancel, [['csrf', csrf], ['jobId', String(state.jobId)]]);
        });
        box.append(bar, label, cancel);
        return;
    }
    const summary = document.createElement('p');
    if ('error' === model.status) {
        summary.textContent = t('El lote terminó con error (%1$s).').replace('%1$s', model.code || 'unexpected');
    } else {
        const tallies = model.tallies;
        summary.textContent = t('%1$s escritos · %2$s omitidos (ya tenían valor) · %3$s sin cambios · %4$s fallidos')
            .replace('%1$s', tallies.written || 0)
            .replace('%2$s', tallies.skipped || 0)
            .replace('%3$s', tallies.unchanged || 0)
            .replace('%4$s', model.failed.length);
        if ('stopped' === model.status) {
            summary.textContent = `${t('Lote cancelado.')} ${summary.textContent}`;
        }
    }
    box.appendChild(summary);
    if (model.batch) {
        const id = document.createElement('p');
        id.textContent = t('Identificador del lote: %1$s').replace('%1$s', model.batch);
        box.appendChild(id);
    }
    if (model.failed.length) {
        const list = document.createElement('ul');
        model.failed.forEach(({ id, code }) => {
            const li = document.createElement('li');
            const link = document.createElement('a');
            link.href = urls.item.replace('__ID__', String(id));
            link.target = '_blank';
            link.textContent = `#${id}`;
            li.append(link, ` — ${t(FAILURE_TEXT[code] || FAILURE_TEXT.unexpected)}`);
            list.appendChild(li);
        });
        box.appendChild(list);
    }
    const reload = document.createElement('button');
    reload.type = 'button';
    reload.className = 'button';
    reload.textContent = t('Recargar la vista');
    reload.addEventListener('click', () => window.location.reload());
    box.appendChild(reload);
}

function poll(root, jobId, urls, csrf) {
    post(urls.status, [['csrf', csrf], ['jobId', String(jobId)]]).then((state) => {
        if (state.error) {
            safeStorage((storage) => storage.removeItem(JOB_KEY));
            return;
        }
        renderProgress(root, { ...state, jobId }, urls, csrf);
        if (resultModel(state).finished) {
            safeStorage((storage) => storage.removeItem(JOB_KEY));
            return;
        }
        window.setTimeout(() => poll(root, jobId, urls, csrf), POLL_MS);
    });
}

function mountForm(root) {
    let governance;
    try {
        governance = JSON.parse(root.dataset.governance);
    } catch (error) {
        return;
    }
    const urls = {
        preview: root.dataset.previewUrl,
        apply: root.dataset.applyUrl,
        status: root.dataset.statusUrl,
        cancel: root.dataset.cancelUrl,
        item: root.dataset.itemUrl
    };
    const csrf = root.dataset.csrf;

    const pending = safeStorage((storage) => storage.getItem(JOB_KEY));
    if (pending) {
        poll(root, pending, urls, csrf);
        return;
    }

    root.querySelector('.oer-batch-target').textContent = targetText();
    buildFields(root, governance);
    root.addEventListener('click', (event) => {
        if (event.target.closest('.oer-governance-add-author')) {
            addAuthorRow(root);
        }
        const remove = event.target.closest('.oer-governance-remove-author');
        if (remove) {
            remove.closest('.oer-governance-author-row').remove();
        }
    });

    root.querySelector('.oer-batch-preview-button').addEventListener('click', () => {
        const ticked = collectTicked(root);
        const errors = validateBatch(ticked);
        if (Object.keys(errors).length) {
            showErrors(root, errors);
            return;
        }
        showErrors(root, {});
        const mode = root.querySelector('input[name="oer-batch-mode"]:checked').value;
        const target = 'matching' === selection.scope
            ? { scope: 'matching', query: currentQuery() }
            : { scope: 'ids', ids: selectedIds() };
        post(urls.preview, previewPairs({ ticked, mode, csrf, selection: target })).then((response) => {
            if (response.error) {
                showErrors(root, response.errors || { _: response.error });
                return;
            }
            renderPreview(root, response, (token) => {
                post(urls.apply, [['csrf', csrf], ['token', token]]).then((applied) => {
                    if (applied.error) {
                        showErrors(root, { _: applied.error });
                        return;
                    }
                    safeStorage((storage) => storage.setItem(JOB_KEY, String(applied.jobId)));
                    root.querySelector('.oer-batch-preview').hidden = true;
                    root.querySelector('.oer-batch-actions').hidden = true;
                    poll(root, applied.jobId, urls, csrf);
                });
            });
        });
    });
}

export function initGovernanceBatch(config) {
    if (!config || !config.canBatchGovernance) {
        return;
    }
    selection = { scope: 'ids', pageCount: 0, checked: 0, totalMatching: config.totalResults };

    document.addEventListener('oer:selection-changed', (event) => {
        selection = selectionAfterToggle(
            { ...selection, pageCount: event.detail.pageCount },
            { type: 'check', checked: event.detail.checked }
        );
        paintStrip();
    });

    document.addEventListener('click', (event) => {
        const action = event.target.closest('.oer-select-matching-action');
        if (!action) {
            return;
        }
        if ('clear' === action.dataset.action) {
            document.querySelectorAll('.oer-row-select').forEach((input) => {
                input.checked = false;
            });
            document.querySelectorAll('.oer-select-all').forEach((input) => {
                input.checked = false;
            });
            selection = selectionAfterToggle(selection, { type: 'clear' });
            refreshSelectionUi();
        } else {
            selection = selectionAfterToggle(selection, { type: 'select-matching' });
        }
        paintStrip();
    });

    const sidebar = document.getElementById('oer-batch-sidebar');
    if (sidebar) {
        $(sidebar).on('o:sidebar-content-loaded', () => {
            const root = sidebar.querySelector('.oer-batch-governance');
            if (root) {
                mountForm(root);
            }
        });
    }

    // Reattach: a running batch reopens its progress after a reload.
    if (safeStorage((storage) => storage.getItem(JOB_KEY))) {
        const opener = document.querySelector('.oer-batch-governance-open');
        if (opener) {
            opener.closest('.oer-selection-bar').hidden = false;
            opener.click();
        }
    }
}
```

- [ ] **Step 5: Verify**

Run: `node --check asset/js/ui/governanceBatch.js && node --check asset/js/ui/visibility.js && make test-js`
Expected: syntax OK; JS tests pass.
Run: `vendor/bin/phpunit -c test/phpunit.xml --filter IndexControllerTest && make lint`
Expected: PASS; lint clean.
Run: `make generate-pot` if the Makefile has that target (`grep -n "generate-pot" Makefile`), then `git diff --stat language/` to confirm the new `@translate` strings were extracted.

- [ ] **Step 6: Commit**

```bash
git add asset/js/ui/governanceBatch.js asset/js/ui/visibility.js asset/js/config.js asset/js/main.js view/oer-manager/admin/index/index.phtml src/Controller/Admin/IndexController.php asset/css/oer-master-view.css test/Controller/IndexControllerTest.php language/
git commit -m "feat: batch licence and authorship in the master view

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

---

### Task 13: Container harness on disposable fixtures

**Files:**
- Create: `test/container/governance-batch-check.php`

**Interfaces:**
- Consumes: `BatchSelection`, `GovernanceBatchJob` (dispatched synchronously), `GovernanceBatchRunner`, `GovernanceService`, `RecatalogService::lastEvent()`, `UndoRouter`.
- Produces: a runnable harness; exit 1 on any FAIL.

- [ ] **Step 1: Write the harness.** Structure it on `test/container/governance-check.php` (bootstrap, `check()`/`skip()`, authentication with `--write <email>`), with these sections:

```php
<?php

/**
 * Container harness for TASK-028 slice 4: batch licence and authorship.
 *
 * DISPOSABLE FIXTURES ONLY. Creates N private REA of its own (title,
 * description), runs batches over them and deletes them at the end, even on
 * failure. Never touches a catalogue item. Requires --write <userEmail>.
 *
 *   php /var/www/html/modules/OERManager/test/container/governance-batch-check.php --write <email> [fixtures=30]
 *
 * Exits 1 if any check fails.
 */

require '/var/www/html/bootstrap.php';

use OERManager\Job\GovernanceBatchJob;
use OERManager\Service\Ai\ProposalStore;
use OERManager\Service\Curation\UndoRouter;
use OERManager\Service\CurationEvent;
use OERManager\Service\Governance\BatchSelection;
use OERManager\Service\Governance\GovernanceFields;
use OERManager\Service\GovernanceService;
use OERManager\Service\MasterViewQuery;
use OERManager\Service\RecatalogService;

$application = Omeka\Mvc\Application::init(require '/var/www/html/application/config/application.config.php');
$services = $application->getServiceManager();
$api = $services->get('Omeka\ApiManager');

$passed = 0;
$failed = 0;
function check(string $label, bool $condition, string $detail = ''): void
{
    global $passed, $failed;
    $condition ? $passed++ : $failed++;
    echo ($condition ? '  OK   ' : '  FAIL ') . $label . ($condition || '' === $detail ? '' : " — $detail") . "\n";
}

$args = array_slice($argv, 1);
if ('--write' !== ($args[0] ?? '')) {
    fwrite(STDERR, "usage: php governance-batch-check.php --write <userEmail> [fixtures]\n");
    exit(2);
}
$entityManager = $services->get('Omeka\EntityManager');
$user = $entityManager->getRepository(\Omeka\Entity\User::class)->findOneBy(['email' => (string) ($args[1] ?? '')]);
if (null === $user) {
    fwrite(STDERR, "user not found\n");
    exit(2);
}
$services->get('Omeka\AuthenticationService')->getStorage()->write($user);
$count = max(4, (int) ($args[2] ?? 30));

$classId = (int) $api->search('resource_classes', ['term' => MasterViewQuery::LEARNING_RESOURCE_CLASS_TERM])->getContent()[0]->id();
$pid = static fn (string $term): int => (int) $api->search('properties', ['term' => $term])->getContent()[0]->id();
$fixtures = [];
```

Then, inside `try { … } finally { foreach ($fixtures as $id) { $api->delete('items', $id); } }`:

1. **Create fixtures.** `$count` private REA titled `governance-batch-check fixture (safe to delete)`. Give the first quarter an existing `dcterms:creator` literal `Existing Author`. Record each item's title and description for the canary.
2. **Core assumptions (spec §10.1).** `$api->search('items', ['id' => $fixtures, 'resource_class_id' => $classId], ['returnScalar' => 'id'])->getContent()` sorted equals `$fixtures` sorted → `returnScalar` and the list-valued `id` parameter both work. If either fails, `check()` FAIL with the detail; the paginated fallback then needs implementing before the slice closes.
3. **Selection.** `$services->get(BatchSelection::class)->resolveIds($fixtures)` equals the fixtures; `countWithValue($fixtures, 'dcterms:creator')` equals the seeded quarter.
4. **Fill mode via the real job, dispatched synchronously.** `$job = $services->get('Omeka\Job\Dispatcher')->dispatch(GovernanceBatchJob::class, ['ids' => $fixtures, 'raw' => ['dcterms:creator' => ['Batch Author'], 'dcterms:rightsHolder' => ['Batch Holder']], 'mode' => 'fill', 'contributor' => $user->getEmail()], $services->get('Omeka\Job\DispatchStrategy\Synchronous'));` Measure `microtime(true)` and `memory_get_peak_usage(true)` around it. Read the state from `ProposalStore` for `$job->getId()`: status `completed`, `tallies.written === $count`. Every seeded item still has creator `Existing Author` (skipped for that field) and has rights holder `Batch Holder`; every other item has creator `Batch Author`.
5. **Events.** For every fixture, `RecatalogService::lastEvent($id)['payload']['batch'] === 'batch-' . $job->getId()`.
6. **Replace mode.** Dispatch again with `'mode' => 'replace'` and `['dcterms:creator' => ['Replaced']]`; every fixture's creator is exactly `['Replaced']`.
7. **Per-item undo.** `UndoRouter::undo($fixtures[0], $user->getEmail())` restores the first fixture's creator to what step 4 left (`Existing Author`).
8. **Canary.** Title and description of every fixture are identical to step 1.
9. **Cancel.** Run `GovernanceBatchRunner` directly over the fixtures with a `ProgressReporter` whose `shouldStop()` turns true after 3 checks and an applier that calls `GovernanceService::apply(..., onlyEmpty: false, batch: 'batch-cancel', reread: false)`: `done === 3`, `stopped === true`, and exactly 3 fixtures carry `batch-cancel` as their last event.
10. **Throughput.** Print `per item: X ms, peak memory: Y MB, extrapolated to 3000: Z min` from step 4's measurements; not a pass/fail check.

End with `printf("\n%d OK, %d FAIL\n", $passed, $failed); exit($failed ? 1 : 0);`

- [ ] **Step 2: Lint it on the host**

Run: `php -l test/container/governance-batch-check.php`
Expected: `No syntax errors detected`.

- [ ] **Step 3: Run it in the container** (ask the owner which admin email to use and confirm that the container is the development one; the harness only writes to its own fixtures)

Run: `docker compose exec <omeka-service> php /var/www/html/modules/OERManager/test/container/governance-batch-check.php --write <email> 30`
Expected: every check `OK`, exit 0, and the throughput line. If step 2 (core assumptions) fails, stop and implement the paginated fallback in `BatchSelection::ids()` (100 ids per page with `per_page`/`page`, reading `->id()` of each representation) before continuing.

- [ ] **Step 4: Commit**

```bash
git add test/container/governance-batch-check.php
git commit -m "test(container): harness for batch governance on disposable fixtures

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

---

### Task 14: Close the slice in the governance docs

**Files:**
- Modify: `docs/backlog.md` (TASK-028 row: description tail and the Estado cell)
- Modify: `docs/traceability.md` (RF-015 row, «Control de autoría y licencia»)
- Modify: `docs/requirements.md` (RF-015 acceptance note)
- Modify: `docs/project-memory.md` (new entry under the TASK-028 slice 3b entry, and the scale constraint)

- [ ] **Step 1: Measure the final suites**

Run: `make lint && make test && make test-js`
Record the PHP test/assertion counts and the JS test count from the output.

- [ ] **Step 2: Update the docs.** Append-only: never rewrite earlier history.
- `backlog.md`, TASK-028: append a «**Slice 4 done (YYYY-MM-DD):** …» paragraph (what shipped, the harness result and throughput from Task 13, the three spec deviations, what stays out: quality queue, resource-type normalisation, batch undo, browser verification = TASK-030 debt) and change the Estado cell to `rebanadas 1, 2, 3a, 3b y 4 **hechas**; 5 (cola de calidad, normalizar tipo, deshacer lote) pendiente de spec`.
- `traceability.md`, RF-015 row: append «**Batch (2026-…, TASK-028 slice 4):** …» with the acceptance criteria of spec §12 and the harness result.
- `requirements.md`, RF-015: append that batch assignment (Fill/Replace, Job, per-item audit with batch id) is implemented.
- `project-memory.md`: a slice 4 entry with lessons, and a standing constraint line: «**Production scale: ~3000 REA** (owner, 2026-09-24). The container's ~19 REA are a development sample. Size every design for 3000; `ComputedFilter::HARD_CAP` (2000) is below it.»

- [ ] **Step 3: Commit**

```bash
git add docs/backlog.md docs/traceability.md docs/requirements.md docs/project-memory.md
git commit -m "docs: close TASK-028 slice 4

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```
