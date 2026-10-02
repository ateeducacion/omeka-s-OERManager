# TASK-028 slice 5a — Batch Undo Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Let a curator undo a whole licence/authorship batch from slice 4 in one background Job,
skipping and reporting every REA touched after the batch.

**Architecture:** A dedicated `GovernanceBatchUndoJob` reads the plan ids from the original
`GovernanceBatchJob`'s args. For each id, a pure `BatchUndoRunner` classifies the REA from its event
history and calls `GovernanceService::undoEvent()` only when the batch event is still the last one.
`GovernanceBatchController` gains `recent` and `undo` actions; `status`/`cancel` accept the undo Job.
The sidebar gains a «Lotes recientes» list, and the existing progress, poll and reattach code serves both
Job kinds.

**Tech Stack:** PHP 8.4, Omeka S 4.2 (Laminas MVC, Doctrine), PHPUnit 11 with host stubs in
`test/stubs/`, ES modules tested with `node --test`.

**Spec:** `docs/superpowers/specs/2026-09-30-task-028-slice-5a-batch-undo-design.md`

## Global Constraints

- Target PHP 8.4; PSR-12 via `make lint`; no PHPStan/Psalm; do not edit the `Makefile`.
- No custom Doctrine tables (NFR-002). Job entities, Job args and the private `ProposalStore` are the only storage.
- Never use `api()->read('jobs')` to authorise a curator's job: read the Job entity through `BatchJobLookup`.
- Another user's batch is always answered `not_found`, never a distinct code.
- Ids come from the original Job's args, never from the browser.
- `dcterms:provenance` is append-only; every write goes through `GovernanceService` (`isPartial` + `collectionAction=append`).
- Never call `EntityManager::clear()` inside a Job (slice 4 lesson: it detaches the authenticated owner).
- Batch-undo events carry `batch: batch-<undoJobId>`; per-item undo events carry no tag.
- Spanish UI source strings through `Omeka.jsTranslate` (`t()`); every button names its action and object.
- Commits: no `Co-Authored-By` trailer; end the body with a plain `Model: <model name>` line.
- Container harnesses only on disposable fixtures they create and delete themselves.
- Sized for ~3000 REA, plan cap `BatchPlanStore::BATCH_MAX` = 5000.

## Review Focus

1. **A REA undone by hand before the batch undo** (per-item undo of the batch event) must count as
   `already_undone`, not `modified_later` — Task 2 pins it.
2. **A Job killed mid-undo stays `in_progress` forever** in Omeka; that must not block every future
   undo of that batch with `undo_running`. Task 4 treats an undo Job running for more than an hour as
   `partial`, and pins it.
3. **A slice 4 tab reloaded after deploy** still holds a bare job id in `localStorage`; it must reattach
   as a batch Job, not crash — Task 6 pins `storedJob('123')`.
4. **Tampered or truncated args on the original Job** (non-array `ids`, zero, negative or non-numeric
   ids) must end the undo as `plan_unreadable` without writing anything — Task 3 pins it.
5. **A site_admin listing batches** must see other users' names but a curator must not see other
   users' batches at all — Task 5 pins both.

## Rulings taken while writing this plan (deviations from the spec, all small)

- **R1 — reporter:** the spec names a separate `BatchUndoProgressReporter`. The plan reuses
  `BatchProgressReporter` with an optional `$kind` argument and a new `UNDO_KIND` constant: identical
  contract, one class less. Cost if wrong: none; the state shape is the same.
- **R2 — container scope:** a non-owner request and the «Deshecho parcialmente» list state need a
  second user and a stopped Job entity. Both are authorisation/mapping logic, pinned by host tests in
  Tasks 4 and 5. The container harness checks cancel at runner level (exactly N undone) instead of
  through the list. Cost if wrong: one container check less; the host tests cover the logic.
- **R3 — dead undo Jobs:** the spec did not cover a killed undo Job. `undoState` reports an undo Job
  as `running` only within an hour of its start, otherwise as `partial`, so a relaunch stays possible
  (Review Focus 2).

---

## File Structure

| File | Action | Responsibility |
| --- | --- | --- |
| `src/Service/RecatalogService.php` | Modify | Public `events(int $itemId)` over private `eventsOf()` |
| `src/Service/GovernanceService.php` | Modify | `undoEvent(..., ?string $batch = null)` passes the tag to `apply()` |
| `src/Service/Governance/BatchUndoRunner.php` | Create | Pure per-REA classification and undo loop |
| `src/Service/Governance/BatchProgressReporter.php` | Modify | Optional `$kind`; `UNDO_KIND` |
| `src/Job/GovernanceBatchUndoJob.php` | Create | Reads the original plan, runs the runner |
| `src/Service/Governance/BatchJobLookup.php` | Modify | `find()` returns args; `recent()`; `undoState()` |
| `src/Controller/Admin/GovernanceBatchController.php` | Modify | `recent`, `undo`; `status`/`cancel` for the undo Job; Acl |
| `config/module.config.php` | Modify | Controller factory gets `Omeka\Acl` |
| `Module.php` | Modify | ACL for `recent`, `undo`, `undo-any-batch` |
| `asset/js/core/governanceBatchModel.js` | Modify | `storedJob`, `recentRowModel`, `undoResultModel` |
| `asset/js/ui/governanceBatch.js` | Modify | Recent list, confirm, undo dispatch, undo result, reattach by kind |
| `view/oer-manager/admin/governance-batch/form.phtml` | Modify | Recent section and two urls |
| `asset/css/oer-master-view.css` | Modify | Recent list styles |
| `test/container/governance-batch-undo-check.php` | Create | Disposable-fixture harness |
| `docs/decisions/0020-curation-event-typed-values.md` | Modify | Second addendum |
| `docs/backlog.md`, `docs/traceability.md`, `docs/project-memory.md` | Modify | Close 5a, split slice 5 |

Tests: `test/Service/RecatalogServiceTest.php`, `test/Service/GovernanceServiceTest.php`,
`test/Service/Governance/BatchUndoRunnerTest.php` (new), `test/Service/Governance/BatchProgressReporterTest.php`,
`test/Job/GovernanceBatchUndoJobTest.php` (new), `test/Service/Governance/BatchJobLookupTest.php`,
`test/Controller/GovernanceBatchControllerTest.php`, `test/ModuleRuntimeTest.php`, `test/js/governanceBatchModel.test.js`.

---

### Task 1: Raw events and a tagged undo

**Files:**
- Modify: `src/Service/RecatalogService.php` (add method after `lastEvent()`, ~line 216)
- Modify: `src/Service/GovernanceService.php:151-176` (`undoEvent`)
- Test: `test/Service/RecatalogServiceTest.php`, `test/Service/GovernanceServiceTest.php`

**Interfaces:**
- Produces: `RecatalogService::events(int $itemId): array` — list of `{when, contributor, summary, payload}`,
  newest first, same order and tie-break as `lastEvent()`.
- Produces: `GovernanceService::undoEvent(int $itemId, array $event, string $contributor, bool $force = false, ?string $batch = null): array`.

- [ ] **Step 1: Write the failing tests**

Append to `test/Service/RecatalogServiceTest.php` (before the final `}`):

```php
    public function testEventsListsEveryReadableEventNewestFirst(): void
    {
        $payload = CurationEvent::build(['lrmi:teaches' => ['before' => [99], 'after' => [2], 'why' => []]]);
        $this->items[1] = $this->item(1, '', ['dcterms:provenance' => [
            $this->value('Plain'),
            $this->event($payload, '2026-01-01'),
            $this->event($payload, '2026-03-01'),
            $this->event($payload, '2026-02-01'),
        ]]);

        $events = $this->service->events(1);

        $this->assertSame(['2026-03-01', '2026-02-01', '2026-01-01'], array_column($events, 'when'));
        $this->assertSame($payload, $events[0]['payload']);
        $this->assertSame([], $this->service->events(2));
    }
```

`setUp()` already defines item 2 with no provenance, so `events(2)` is `[]`.

Append to `test/Service/GovernanceServiceTest.php` (before the final `}`):

```php
    public function testUndoEventTagsTheUndoEventOnlyWhenABatchIsGiven(): void
    {
        $payload = CurationEvent::buildTyped([
            GovernanceFields::CREATOR => [
                'before' => [['type' => 'literal', 'value' => 'Ana']],
                'after' => [['type' => 'literal', 'value' => 'Batch Author']],
            ],
        ], null, 'batch-7');
        $event = ['when' => '2026-01-01T00:00:00.000000+00:00', 'payload' => $payload];
        $this->items[1] = $this->item(1, '', [GovernanceFields::CREATOR => [$this->value('Batch Author')]]);
        $service = $this->makeService();

        $service->undoEvent(1, $event, 'Curator', false, 'batch-9');
        $service->undoEvent(1, $event, 'Curator');

        $tagged = CurationEvent::decode(
            $this->writes[0]['dcterms:provenance'][0]['@annotation']['dcterms:replaces'][0]['@value']
        );
        $plain = CurationEvent::decode(
            $this->writes[1]['dcterms:provenance'][0]['@annotation']['dcterms:replaces'][0]['@value']
        );
        $this->assertSame('batch-9', $tagged['batch']);
        $this->assertSame(CurationEvent::OP_UNDO, $tagged['op']);
        $this->assertArrayNotHasKey('batch', $plain);
    }
```

The second call sees the same item: the test double records writes in `$this->writes` and never
changes `$this->items`.

- [ ] **Step 2: Run tests to verify they fail**

Run: `vendor/bin/phpunit -c test/phpunit.xml --filter 'testEventsListsEveryReadableEventNewestFirst|testUndoEventTagsTheUndoEventOnlyWhenABatchIsGiven'`
Expected: FAIL — `Call to undefined method ...::events()` and the missing `batch` key.

- [ ] **Step 3: Implement**

In `src/Service/RecatalogService.php`, after `lastEvent()`:

```php
    /**
     * Every readable curation event of the item, newest first, with the same
     * microsecond tie-break as lastEvent() (TASK-007). Raw payloads, not the
     * projected rows of history(): the batch undo (TASK-028 slice 5a) needs
     * the last two events and their `batch` keys.
     *
     * @return list<array{when:string,contributor:string,summary:string,payload:array<string,mixed>}>
     */
    public function events(int $itemId): array
    {
        return $this->eventsOf($this->api->read('items', $itemId)->getContent());
    }
```

In `src/Service/GovernanceService.php`, change `undoEvent`'s signature and its `apply` call:

```php
    public function undoEvent(
        int $itemId,
        array $event,
        string $contributor,
        bool $force = false,
        ?string $batch = null
    ): array {
```

```php
        $result = $this->apply($itemId, $restore, $contributor, $event['when'], false, $batch);
```

Add one line to the method's docblock: `@param string|null $batch tag of a batch undo (slice 5a); per-item undo passes none`.

- [ ] **Step 4: Run tests to verify they pass**

Run: `vendor/bin/phpunit -c test/phpunit.xml test/Service/RecatalogServiceTest.php test/Service/GovernanceServiceTest.php test/Service/Curation/UndoRouterTest.php`
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add src/Service/RecatalogService.php src/Service/GovernanceService.php test/Service/RecatalogServiceTest.php test/Service/GovernanceServiceTest.php
git commit -m "feat: raw curation events and a batch tag on undo events" -m "Model: <model name>"
```

---

### Task 2: BatchUndoRunner

**Files:**
- Create: `src/Service/Governance/BatchUndoRunner.php`
- Test: `test/Service/Governance/BatchUndoRunnerTest.php`

**Interfaces:**
- Consumes: event shape from Task 1 (`when`, `payload` with `op`, `undoOf`, `batch`);
  `OERManager\Service\Ai\ProgressReporter`; `CurationEvent::OP_GOVERNANCE`, `CurationEvent::OP_UNDO`.
- Produces: `BatchUndoRunner::classify(array $events, string $batch): string`, which returns one of
  `BatchUndoRunner::UNDO|ALREADY_UNDONE|MODIFIED_LATER|NOT_IN_BATCH`.
- Produces: `BatchUndoRunner::run(array $ids, string $batch, callable $events, callable $undo, ProgressReporter $progress, ?callable $log = null): array`,
  which returns `{undone, modified_later, not_in_batch, already_undone, failed: list<{id,code}>, review: list<{id,code}>, done, total, stopped}`.
  `$events(int $id): list<event>`; `$undo(int $id, array $event): array`, which is `undoEvent`'s result.

- [ ] **Step 1: Write the failing test**

```php
<?php

declare(strict_types=1);

namespace OERManager\Test\Service\Governance;

use OERManager\Service\Ai\ProgressReporter;
use OERManager\Service\CurationEvent;
use OERManager\Service\Governance\BatchUndoRunner;
use Omeka\Api\Exception\NotFoundException;
use Omeka\Permissions\Exception\PermissionDeniedException;
use PHPUnit\Framework\TestCase;

final class BatchUndoRunnerTest extends TestCase
{
    private const B = 'batch-7';

    private function write(string $when, ?string $batch = self::B): array
    {
        $payload = ['v' => 2, 'op' => CurationEvent::OP_GOVERNANCE, 'undoOf' => null, 'terms' => []];
        if (null !== $batch) {
            $payload['batch'] = $batch;
        }
        return ['when' => $when, 'payload' => $payload];
    }

    private function undo(string $when, string $undoOf, ?string $batch = null): array
    {
        $payload = ['v' => 2, 'op' => CurationEvent::OP_UNDO, 'undoOf' => $undoOf, 'terms' => []];
        if (null !== $batch) {
            $payload['batch'] = $batch;
        }
        return ['when' => $when, 'payload' => $payload];
    }

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

    public function testClassifiesFromTheLastTwoEvents(): void
    {
        $this->assertSame(BatchUndoRunner::UNDO, BatchUndoRunner::classify([$this->write('t2'), $this->write('t1', null)], self::B));
        // Undone by hand (per-item undo, no tag) or by an earlier batch undo (tagged).
        $this->assertSame(BatchUndoRunner::ALREADY_UNDONE, BatchUndoRunner::classify([$this->undo('t3', 't2'), $this->write('t2')], self::B));
        $this->assertSame(BatchUndoRunner::ALREADY_UNDONE, BatchUndoRunner::classify([$this->undo('t3', 't2', 'batch-9'), $this->write('t2')], self::B));
        // Touched after the batch: a later write, or an undo of something else.
        $this->assertSame(BatchUndoRunner::MODIFIED_LATER, BatchUndoRunner::classify([$this->write('t3', null), $this->write('t2')], self::B));
        $this->assertSame(BatchUndoRunner::MODIFIED_LATER, BatchUndoRunner::classify([$this->undo('t4', 't3'), $this->write('t3', null), $this->write('t2')], self::B));
        // Another batch, or nothing at all.
        $this->assertSame(BatchUndoRunner::NOT_IN_BATCH, BatchUndoRunner::classify([$this->write('t2', 'batch-8')], self::B));
        $this->assertSame(BatchUndoRunner::NOT_IN_BATCH, BatchUndoRunner::classify([], self::B));
    }

    public function testTalliesEveryOutcomeAndUndoesOnlyTheBatchEvent(): void
    {
        $history = [
            1 => [$this->write('t2')],
            2 => [$this->write('t2')],
            3 => [$this->undo('t3', 't2'), $this->write('t2')],
            4 => [$this->write('t3', null), $this->write('t2')],
            5 => [],
            6 => new PermissionDeniedException('private'),
            7 => new NotFoundException('gone'),
            8 => [$this->write('t2')],
            9 => [$this->write('t2')],
            10 => [$this->write('t2')],
        ];
        $answers = [
            1 => ['updated' => true],
            2 => ['updated' => false, 'error' => 'stale', 'terms' => ['dcterms:creator']],
            8 => ['updated' => false, 'errors' => ['dcterms:license' => 'not-http-uri']],
            9 => new \LogicException('secret detail'),
            10 => ['updated' => false, 'unchanged' => true],
        ];
        $undone = [];
        $logged = [];

        $result = (new BatchUndoRunner())->run(
            array_keys($history),
            self::B,
            function (int $id) use ($history): array {
                if ($history[$id] instanceof \Throwable) {
                    throw $history[$id];
                }
                return $history[$id];
            },
            function (int $id, array $event) use ($answers, &$undone): array {
                $undone[] = [$id, $event['when']];
                if ($answers[$id] instanceof \Throwable) {
                    throw $answers[$id];
                }
                return $answers[$id];
            },
            $this->progress(),
            function (string $message) use (&$logged): void {
                $logged[] = $message;
            }
        );

        $this->assertSame([[1, 't2'], [2, 't2'], [8, 't2'], [9, 't2'], [10, 't2']], $undone);
        $this->assertSame(1, $result['undone']);
        $this->assertSame(2, $result['modified_later']);
        $this->assertSame(1, $result['already_undone']);
        $this->assertSame(1, $result['not_in_batch']);
        $this->assertSame([
            ['id' => 6, 'code' => 'denied'],
            ['id' => 7, 'code' => 'not_found'],
            ['id' => 8, 'code' => 'invalid'],
            ['id' => 9, 'code' => 'unexpected'],
            ['id' => 10, 'code' => 'unexpected'],
        ], $result['failed']);
        $this->assertSame([
            ['id' => 2, 'code' => 'modified_later'],
            ['id' => 4, 'code' => 'modified_later'],
            ['id' => 6, 'code' => 'denied'],
            ['id' => 7, 'code' => 'not_found'],
            ['id' => 8, 'code' => 'invalid'],
            ['id' => 9, 'code' => 'unexpected'],
            ['id' => 10, 'code' => 'unexpected'],
        ], $result['review']);
        $this->assertSame(10, $result['done']);
        $this->assertFalse($result['stopped']);
        $this->assertCount(1, $logged);
        $this->assertStringContainsString('item 9', $logged[0]);
    }

    public function testReportsEvery25AndStopsBetweenItems(): void
    {
        $progress = $this->progress();
        (new BatchUndoRunner())->run(
            range(1, 60),
            self::B,
            fn (int $id): array => [],
            fn (int $id, array $event): array => ['updated' => true],
            $progress
        );
        $this->assertSame([[0, 60], [25, 60], [50, 60], [60, 60]], $progress->reports);

        $calls = 0;
        $stopping = $this->progress(3);
        $result = (new BatchUndoRunner())->run(
            range(1, 10),
            self::B,
            fn (int $id): array => [$this->write('t2')],
            function (int $id, array $event) use (&$calls): array {
                $calls++;
                return ['updated' => true];
            },
            $stopping
        );
        $this->assertTrue($result['stopped']);
        $this->assertSame(3, $result['done']);
        $this->assertSame(3, $calls);
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `vendor/bin/phpunit -c test/phpunit.xml test/Service/Governance/BatchUndoRunnerTest.php`
Expected: FAIL — class `BatchUndoRunner` not found.

- [ ] **Step 3: Implement**

```php
<?php

declare(strict_types=1);

namespace OERManager\Service\Governance;

use OERManager\Service\Ai\ProgressReporter;
use OERManager\Service\CurationEvent;
use Omeka\Api\Exception\NotFoundException;
use Omeka\Permissions\Exception\PermissionDeniedException;

/**
 * The batch undo loop (TASK-028 slice 5a), pure: reading an item's events and
 * undoing one are injected. An REA is undone only when the batch's event is
 * still its last one; the stale check inside the injected undo covers values
 * changed without an event. Anything touched after the batch is skipped and
 * reported, never overwritten (owner decision D2). One REA's failure never
 * aborts the undo; cancel is honoured between REA.
 */
final class BatchUndoRunner
{
    public const PROGRESS_EVERY = 25;

    public const UNDO = 'undo';
    public const ALREADY_UNDONE = 'already_undone';
    public const MODIFIED_LATER = 'modified_later';
    public const NOT_IN_BATCH = 'not_in_batch';

    /**
     * What to do with one REA, from its events (newest first).
     *
     * @param list<array<string,mixed>> $events
     */
    public static function classify(array $events, string $batch): string
    {
        $last = $events[0] ?? null;
        if (null === $last) {
            return self::NOT_IN_BATCH;
        }
        $payload = (array) ($last['payload'] ?? []);
        if (self::isBatchWrite($payload, $batch)) {
            return self::UNDO;
        }
        $previous = $events[1] ?? null;
        if (
            CurationEvent::OP_UNDO === ($payload['op'] ?? null)
            && null !== $previous
            && ($payload['undoOf'] ?? null) === ($previous['when'] ?? null)
            && self::isBatchWrite((array) ($previous['payload'] ?? []), $batch)
        ) {
            return self::ALREADY_UNDONE;
        }
        foreach ($events as $event) {
            if (self::isBatchWrite((array) ($event['payload'] ?? []), $batch)) {
                return self::MODIFIED_LATER;
            }
        }
        return self::NOT_IN_BATCH;
    }

    /** @param array<string,mixed> $payload */
    private static function isBatchWrite(array $payload, string $batch): bool
    {
        return CurationEvent::OP_GOVERNANCE === ($payload['op'] ?? null) && $batch === ($payload['batch'] ?? null);
    }

    /**
     * @param list<int> $ids
     * @param callable(int):array $events
     * @param callable(int, array):array $undo
     * @param callable(string):void|null $log
     * @return array<string,mixed>
     */
    public function run(
        array $ids,
        string $batch,
        callable $events,
        callable $undo,
        ProgressReporter $progress,
        ?callable $log = null
    ): array {
        $total = count($ids);
        $tallies = [
            'undone' => 0,
            'modified_later' => 0,
            'not_in_batch' => 0,
            'already_undone' => 0,
            'failed' => [],
            'review' => [],
            'done' => 0,
            'total' => $total,
            'stopped' => false,
        ];
        $progress->report('undo', 0, $total);

        foreach ($ids as $id) {
            if ($progress->shouldStop()) {
                $tallies['stopped'] = true;
                break;
            }
            $tallies = $this->tally($tallies, $id, $batch, $events, $undo, $log);
            $tallies['done']++;
            if (0 === $tallies['done'] % self::PROGRESS_EVERY) {
                $progress->report('undo', $tallies['done'], $total);
            }
        }

        if (0 !== $tallies['done'] % self::PROGRESS_EVERY) {
            $progress->report('undo', $tallies['done'], $total);
        }
        return $tallies;
    }

    /** @param array<string,mixed> $tallies */
    private function tally(array $tallies, int $id, string $batch, callable $events, callable $undo, ?callable $log): array
    {
        try {
            $history = $events($id);
            $verdict = self::classify($history, $batch);
            if (self::UNDO !== $verdict) {
                return $this->count($tallies, $id, $verdict);
            }
            $result = $undo($id, $history[0]);
        } catch (PermissionDeniedException $e) {
            return $this->fail($tallies, $id, 'denied');
        } catch (NotFoundException $e) {
            return $this->fail($tallies, $id, 'not_found');
        } catch (\Throwable $e) {
            if (null !== $log) {
                $log('OERManager governance batch undo item ' . $id . ': ' . $e->getMessage());
            }
            return $this->fail($tallies, $id, 'unexpected');
        }

        if ('stale' === ($result['error'] ?? null)) {
            return $this->count($tallies, $id, self::MODIFIED_LATER);
        }
        if (!empty($result['errors'])) {
            return $this->fail($tallies, $id, 'invalid');
        }
        if (true === ($result['updated'] ?? false)) {
            $tallies['undone']++;
            return $tallies;
        }
        return $this->fail($tallies, $id, 'unexpected');
    }

    /** @param array<string,mixed> $tallies */
    private function count(array $tallies, int $id, string $verdict): array
    {
        $tallies[$verdict]++;
        if (self::MODIFIED_LATER === $verdict) {
            $tallies['review'][] = ['id' => $id, 'code' => $verdict];
        }
        return $tallies;
    }

    /** @param array<string,mixed> $tallies */
    private function fail(array $tallies, int $id, string $code): array
    {
        $tallies['failed'][] = ['id' => $id, 'code' => $code];
        $tallies['review'][] = ['id' => $id, 'code' => $code];
        return $tallies;
    }
}
```

`review` lists REA in the order processed. The test's expected `review` order (2, 4, 6, 7, 8, 9, 10)
is that order.

- [ ] **Step 4: Run test to verify it passes**

Run: `vendor/bin/phpunit -c test/phpunit.xml test/Service/Governance/BatchUndoRunnerTest.php` and `make lint`
Expected: PASS, lint clean.

- [ ] **Step 5: Commit**

```bash
git add src/Service/Governance/BatchUndoRunner.php test/Service/Governance/BatchUndoRunnerTest.php
git commit -m "feat: pure batch undo runner that skips REA touched after the batch" -m "Model: <model name>"
```

---

### Task 3: The undo Job and its state kind

**Files:**
- Modify: `src/Service/Governance/BatchProgressReporter.php`
- Create: `src/Job/GovernanceBatchUndoJob.php`
- Test: `test/Service/Governance/BatchProgressReporterTest.php`, `test/Job/GovernanceBatchUndoJobTest.php`

**Interfaces:**
- Consumes: `BatchUndoRunner` (Task 2); `RecatalogService::events`, `GovernanceService::undoEvent(..., $batch)` (Task 1);
  `BatchJobLookup::find(int): ?array{class, ownerId, status, args}`. Task 4 adds `args`; this task's test mocks `find()`.
- Produces: `BatchProgressReporter::UNDO_KIND = 'governance-batch-undo'`;
  `new BatchProgressReporter($store, $shouldStop, $jobId, string $kind = self::KIND)`.
- Produces: `GovernanceBatchUndoJob` with args `{batchJobId:int, contributor:string}` and the public static helper
  `GovernanceBatchUndoJob::planIds(?array $original): ?array` (null = unreadable).
- State written: `{kind: UNDO_KIND, status, batch: 'batch-<undoJobId>', tallies}`; on unreadable plan
  `status: 'error', tallies: {code: 'plan_unreadable'}`.

- [ ] **Step 1: Write the failing tests**

Append to `test/Service/Governance/BatchProgressReporterTest.php`:

```php
    public function testUndoKindTagsTheState(): void
    {
        $dir = sys_get_temp_dir() . '/oer_batch_state_' . bin2hex(random_bytes(6));
        $store = new ProposalStore($dir);
        $reporter = new BatchProgressReporter($store, static fn (): bool => false, 13, BatchProgressReporter::UNDO_KIND);

        $reporter->report('undo', 1, 2);
        $this->assertSame('governance-batch-undo', $store->read(13)['kind']);
        $reporter->finish('completed', ['undone' => 2], 'batch-13');
        $this->assertSame('governance-batch-undo', $store->read(13)['kind']);

        array_map('unlink', glob($dir . '/*'));
        rmdir($dir);
    }
```

Create `test/Job/GovernanceBatchUndoJobTest.php`:

```php
<?php

declare(strict_types=1);

namespace OERManager\Test\Job;

use OERManager\Job\GovernanceBatchJob;
use OERManager\Job\GovernanceBatchUndoJob;
use OERManager\Service\Ai\ProposalStore;
use OERManager\Service\CurationEvent;
use OERManager\Service\Governance\BatchJobLookup;
use OERManager\Service\GovernanceService;
use OERManager\Service\RecatalogService;
use PHPUnit\Framework\TestCase;

final class GovernanceBatchUndoJobTest extends TestCase
{
    private string $dir;
    private ProposalStore $store;
    private $governance;
    private $recatalog;
    private $lookup;
    private array $undoCalls = [];

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/oer_undo_job_' . bin2hex(random_bytes(6));
        $this->store = new ProposalStore($this->dir);
        $this->governance = $this->createMock(GovernanceService::class);
        $this->governance->method('undoEvent')->willReturnCallback(function (...$args): array {
            $this->undoCalls[] = $args;
            return ['updated' => true];
        });
        $this->recatalog = $this->createMock(RecatalogService::class);
        $this->recatalog->method('events')->willReturn([[
            'when' => 't2',
            'payload' => ['v' => 2, 'op' => CurationEvent::OP_GOVERNANCE, 'batch' => 'batch-7', 'terms' => []],
        ]]);
        $this->lookup = $this->createMock(BatchJobLookup::class);
    }

    protected function tearDown(): void
    {
        array_map('unlink', glob($this->dir . '/*') ?: []);
        @rmdir($this->dir);
    }

    private function job(array $args): GovernanceBatchUndoJob
    {
        $services = $this->createMock(\Laminas\ServiceManager\ServiceLocatorInterface::class);
        $services->method('get')->willReturnMap([
            [GovernanceService::class, $this->governance],
            [RecatalogService::class, $this->recatalog],
            [ProposalStore::class, $this->store],
            [BatchJobLookup::class, $this->lookup],
            ['Omeka\Logger', $this->createMock(\Laminas\Log\LoggerInterface::class)],
        ]);
        $job = new class extends GovernanceBatchUndoJob {
            public array $args = [];

            public function getArg($name, $default = null)
            {
                return $this->args[$name] ?? $default;
            }
        };
        $job->args = $args;
        $job->job = new \Omeka\Entity\Job();
        $job->serviceLocator = $services;
        return $job;
    }

    public function testUndoesThePlanOfTheOriginalBatchWithItsOwnTag(): void
    {
        $this->lookup->method('find')->with(7)->willReturn([
            'class' => GovernanceBatchJob::class, 'ownerId' => 3, 'status' => 'completed',
            'args' => ['ids' => [4, '5'], 'raw' => [], 'mode' => 'replace'],
        ]);

        $this->job(['batchJobId' => 7, 'contributor' => 'Curator'])->perform();

        $this->assertSame([4, 5], array_column($this->undoCalls, 0));
        $this->assertSame('Curator', $this->undoCalls[0][2]);
        $this->assertFalse($this->undoCalls[0][3]);
        $this->assertSame('batch-1', $this->undoCalls[0][4]);
        $state = $this->store->read(1);
        $this->assertSame('governance-batch-undo', $state['kind']);
        $this->assertSame('completed', $state['status']);
        $this->assertSame('batch-1', $state['batch']);
        $this->assertSame(2, $state['tallies']['undone']);
    }

    public function testAnUnreadablePlanWritesNothing(): void
    {
        $cases = [
            null,
            ['class' => 'Omeka\Job\BatchUpdate', 'ownerId' => 3, 'status' => 'completed', 'args' => ['ids' => [4]]],
            ['class' => GovernanceBatchJob::class, 'ownerId' => 3, 'status' => 'completed', 'args' => ['ids' => 'x']],
            ['class' => GovernanceBatchJob::class, 'ownerId' => 3, 'status' => 'completed', 'args' => ['ids' => []]],
            ['class' => GovernanceBatchJob::class, 'ownerId' => 3, 'status' => 'completed', 'args' => ['ids' => [4, 0]]],
            ['class' => GovernanceBatchJob::class, 'ownerId' => 3, 'status' => 'completed', 'args' => ['ids' => [4, 'a']]],
            ['class' => GovernanceBatchJob::class, 'ownerId' => 3, 'status' => 'completed', 'args' => []],
        ];
        foreach ($cases as $i => $original) {
            $this->assertNull(GovernanceBatchUndoJob::planIds($original), "case $i");
        }
        $this->assertSame([4, 5], GovernanceBatchUndoJob::planIds(
            ['class' => GovernanceBatchJob::class, 'ownerId' => 3, 'status' => 'completed', 'args' => ['ids' => [4, '5']]]
        ));

        $this->lookup->method('find')->willReturn(null);
        $this->job(['batchJobId' => 7, 'contributor' => 'Curator'])->perform();

        $this->assertSame([], $this->undoCalls);
        $this->assertSame(
            ['kind' => 'governance-batch-undo', 'status' => 'error', 'batch' => 'batch-1', 'tallies' => ['code' => 'plan_unreadable']],
            $this->store->read(1)
        );
    }

    public function testAMissingBatchIdIsUnreadable(): void
    {
        $this->lookup->expects($this->never())->method('find');

        $this->job(['contributor' => 'Curator'])->perform();

        $this->assertSame('plan_unreadable', $this->store->read(1)['tallies']['code']);
    }
}
```

- [ ] **Step 2: Run tests to verify they fail**

Run: `vendor/bin/phpunit -c test/phpunit.xml test/Service/Governance/BatchProgressReporterTest.php test/Job/GovernanceBatchUndoJobTest.php`
Expected: FAIL — `UNDO_KIND` undefined, class `GovernanceBatchUndoJob` not found.

- [ ] **Step 3: Implement**

In `BatchProgressReporter.php`: add the constant, a constructor argument, and use it in both writes.

```php
    public const KIND = 'governance-batch';
    public const UNDO_KIND = 'governance-batch-undo';

    /** @param callable():bool $shouldStop */
    public function __construct(
        private ProposalStore $store,
        private $shouldStop,
        private int $jobId,
        private string $kind = self::KIND
    ) {
    }
```

Replace `'kind' => self::KIND,` with `'kind' => $this->kind,` in `report()` and `finish()`. Update the
class docblock: «…tagged with `kind` (`governance-batch` or, for the batch undo of slice 5a,
`governance-batch-undo`) so the status endpoint never serves another job's state as a batch.»

Create `src/Job/GovernanceBatchUndoJob.php`:

```php
<?php

declare(strict_types=1);

namespace OERManager\Job;

use OERManager\Service\Ai\ProposalStore;
use OERManager\Service\Governance\BatchJobLookup;
use OERManager\Service\Governance\BatchProgressReporter;
use OERManager\Service\Governance\BatchUndoRunner;
use OERManager\Service\GovernanceService;
use OERManager\Service\RecatalogService;
use Omeka\Job\AbstractJob;

/**
 * Undo of a whole governance batch (TASK-028 slice 5a). Thin glue: the loop and
 * the per-REA rule live in BatchUndoRunner; the write is
 * GovernanceService::undoEvent(), tagged `batch-<this job id>`. The ids are
 * read from the original GovernanceBatchJob's args, never from the request.
 * Runs as its owner, so the native edit ACL decides REA by REA. Does not clear
 * the EntityManager, for the reason GovernanceBatchJob gives.
 */
class GovernanceBatchUndoJob extends AbstractJob
{
    /**
     * The original batch's plan ids, or null when they cannot be trusted:
     * missing Job, another class, or args whose `ids` is not a non-empty list
     * of positive integers.
     *
     * @param array{class:string, args?:array}|null $original
     * @return list<int>|null
     */
    public static function planIds(?array $original): ?array
    {
        if (null === $original || GovernanceBatchJob::class !== ($original['class'] ?? null)) {
            return null;
        }
        $raw = $original['args']['ids'] ?? null;
        if (!is_array($raw) || [] === $raw) {
            return null;
        }
        $ids = [];
        foreach ($raw as $id) {
            if (!is_int($id) && !(is_string($id) && ctype_digit($id))) {
                return null;
            }
            if ((int) $id <= 0) {
                return null;
            }
            $ids[] = (int) $id;
        }
        return $ids;
    }

    public function perform(): void
    {
        $services = $this->getServiceLocator();
        /** @var GovernanceService $governance */
        $governance = $services->get(GovernanceService::class);
        /** @var RecatalogService $recatalog */
        $recatalog = $services->get(RecatalogService::class);
        /** @var ProposalStore $store */
        $store = $services->get(ProposalStore::class);
        /** @var BatchJobLookup $lookup */
        $lookup = $services->get(BatchJobLookup::class);
        $logger = $services->get('Omeka\Logger');

        $jobId = (int) $this->job->getId();
        $tag = 'batch-' . $jobId;
        $batchJobId = (int) $this->getArg('batchJobId', 0);
        $contributor = (string) $this->getArg('contributor', 'unknown');
        $progress = new BatchProgressReporter(
            $store,
            fn (): bool => $this->shouldStop(),
            $jobId,
            BatchProgressReporter::UNDO_KIND
        );

        $ids = $batchJobId > 0 ? self::planIds($lookup->find($batchJobId)) : null;
        if (null === $ids) {
            $progress->finish('error', ['code' => 'plan_unreadable'], $tag);
            return;
        }

        try {
            $tallies = (new BatchUndoRunner())->run(
                $ids,
                'batch-' . $batchJobId,
                static fn (int $id): array => $recatalog->events($id),
                static fn (int $id, array $event): array => $governance->undoEvent($id, $event, $contributor, false, $tag),
                $progress,
                static function (string $message) use ($logger): void {
                    $logger->err($message);
                }
            );
            $progress->finish($tallies['stopped'] ? 'stopped' : 'completed', $tallies, $tag);
        } catch (\Throwable $e) {
            $logger->err('OERManager governance batch undo job ' . $jobId . ': ' . $e->getMessage());
            $progress->finish('error', ['code' => 'unexpected'], $tag);
        }
    }
}
```

`find()` returning `args` is added in Task 4. Until then, the mocked `find()` in this test supplies
it; production wiring is exercised in Task 8.

- [ ] **Step 4: Run tests to verify they pass**

Run: `vendor/bin/phpunit -c test/phpunit.xml test/Service/Governance/BatchProgressReporterTest.php test/Job/` and `make lint`
Expected: PASS, lint clean.

- [ ] **Step 5: Commit**

```bash
git add src/Service/Governance/BatchProgressReporter.php src/Job/GovernanceBatchUndoJob.php test/Service/Governance/BatchProgressReporterTest.php test/Job/GovernanceBatchUndoJobTest.php
git commit -m "feat: batch undo job that reads the plan from the original batch" -m "Model: <model name>"
```

---

### Task 4: BatchJobLookup — args, recent batches and undo state

**Files:**
- Modify: `src/Service/Governance/BatchJobLookup.php`
- Test: `test/Service/Governance/BatchJobLookupTest.php`

**Interfaces:**
- Produces: `find(int): ?array{class:string, ownerId:?int, status:string, args:array}`.
- Produces: `recent(?int $ownerId, int $limit = 20): list<array{jobId:int, ownerId:?int, ownerName:?string, started:?string, ended:?string, terms:list<string>, mode:string, planned:int, status:string, undo:array{state:string, jobId:?int}}>`,
  where `undo.state` is one of `none|running|done|partial`.
- Produces: `undoState(int $batchJobId): array{state:string, jobId:?int}`.
- Constructor: `__construct(object $entityManager, ?\Closure $now = null)`. `$now` returns a
  `\DateTimeImmutable` and defaults to the current time.
- Constants: `FINISHED = ['completed', 'stopped', 'error']`, `UNDO_SCAN = 200`, `STALE_AFTER = 3600`.

- [ ] **Step 1: Write the failing tests**

Replace the private helpers in `test/Service/Governance/BatchJobLookupTest.php` so the fake entity
manager also offers `getRepository()->findBy()` and the fake job offers args, dates, id and owner
name. Keep the three existing tests, and change the first one's expected array to include `'args' => []`.

```php
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
```

Add these tests:

```php
    private const BATCH = \OERManager\Job\GovernanceBatchJob::class;
    private const UNDO = \OERManager\Job\GovernanceBatchUndoJob::class;

    public function testFindReturnsTheArgs(): void
    {
        $em = $this->entityManager($this->job(self::BATCH, 7, 'completed', ['ids' => [1, 2]]));

        $this->assertSame(['ids' => [1, 2]], (new BatchJobLookup($em))->find(4)['args']);
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
```

- [ ] **Step 2: Run tests to verify they fail**

Run: `vendor/bin/phpunit -c test/phpunit.xml test/Service/Governance/BatchJobLookupTest.php`
Expected: FAIL — missing `args` key, `recent()`/`undoState()` undefined.

- [ ] **Step 3: Implement**

Replace `src/Service/Governance/BatchJobLookup.php` with:

```php
<?php

declare(strict_types=1);

namespace OERManager\Service\Governance;

use OERManager\Job\GovernanceBatchJob;
use OERManager\Job\GovernanceBatchUndoJob;

/**
 * Reads the Job entities behind batches (TASK-028 slices 4 and 5a) without the
 * API. In Omeka 4.2 `api()->read('jobs')` is denied to editor and reviewer —
 * the curators the batch is for — and allowed to site_admin for every user's
 * job, so ownership is decided by the callers from the entity, not by the job
 * ACL. The AI propose status and cancel actions (IndexController) use it for
 * the same reason.
 */
class BatchJobLookup
{
    public const FINISHED = ['completed', 'stopped', 'error'];
    /** Undo Jobs read to answer «is this batch undone?»; older ones count as none. */
    public const UNDO_SCAN = 200;
    /** An undo still «running» after this many seconds was killed (Omeka never marks it). */
    public const STALE_AFTER = 3600;

    private \Closure $now;

    /** @param object $entityManager Doctrine EntityManager (duck-typed: find() and getRepository()->findBy()) */
    public function __construct(private object $entityManager, ?\Closure $now = null)
    {
        $this->now = $now ?? static fn (): \DateTimeImmutable => new \DateTimeImmutable();
    }

    /** @return array{class:string, ownerId:?int, status:string, args:array}|null */
    public function find(int $jobId): ?array
    {
        $job = $this->entityManager->find('Omeka\Entity\Job', $jobId);
        if (null === $job) {
            return null;
        }
        $owner = $job->getOwner();
        return [
            'class' => (string) $job->getClass(),
            'ownerId' => null === $owner ? null : (int) $owner->getId(),
            'status' => (string) $job->getStatus(),
            'args' => (array) ($job->getArgs() ?? []),
        ];
    }

    /**
     * The most recent finished governance batches, newest first: the owner's,
     * or everyone's when $ownerId is null (a site_admin). Two bounded queries,
     * never a catalogue scan.
     *
     * @return list<array<string,mixed>>
     */
    public function recent(?int $ownerId, int $limit = 20): array
    {
        $criteria = ['class' => GovernanceBatchJob::class, 'status' => self::FINISHED];
        if (null !== $ownerId) {
            $criteria['owner'] = $ownerId;
        }
        $jobs = $this->entityManager->getRepository('Omeka\Entity\Job')->findBy($criteria, ['id' => 'DESC'], $limit);
        $undo = $this->latestUndoByBatch();
        $rows = [];
        foreach ($jobs as $job) {
            $args = (array) ($job->getArgs() ?? []);
            $owner = $job->getOwner();
            $id = (int) $job->getId();
            $rows[] = [
                'jobId' => $id,
                'ownerId' => null === $owner ? null : (int) $owner->getId(),
                'ownerName' => null === $owner ? null : (string) $owner->getName(),
                'started' => self::iso($job->getStarted()),
                'ended' => self::iso($job->getEnded()),
                'terms' => array_values(array_map('strval', array_keys((array) ($args['raw'] ?? [])))),
                'mode' => (string) ($args['mode'] ?? ''),
                'planned' => count((array) ($args['ids'] ?? [])),
                'status' => (string) $job->getStatus(),
                'undo' => $undo[$id] ?? ['state' => 'none', 'jobId' => null],
            ];
        }
        return $rows;
    }

    /** @return array{state:string, jobId:?int} */
    public function undoState(int $batchJobId): array
    {
        return $this->latestUndoByBatch()[$batchJobId] ?? ['state' => 'none', 'jobId' => null];
    }

    /** @return array<int, array{state:string, jobId:int}> latest undo Job per batch id */
    private function latestUndoByBatch(): array
    {
        $jobs = $this->entityManager->getRepository('Omeka\Entity\Job')
            ->findBy(['class' => GovernanceBatchUndoJob::class], ['id' => 'DESC'], self::UNDO_SCAN);
        $latest = [];
        foreach ($jobs as $job) {
            $batchJobId = ((array) ($job->getArgs() ?? []))['batchJobId'] ?? null;
            if (!is_int($batchJobId) || $batchJobId <= 0 || isset($latest[$batchJobId])) {
                continue;
            }
            $latest[$batchJobId] = ['state' => $this->stateOf($job), 'jobId' => (int) $job->getId()];
        }
        return $latest;
    }

    private function stateOf(object $job): string
    {
        $status = (string) $job->getStatus();
        if ('completed' === $status) {
            return 'done';
        }
        if (in_array($status, ['stopped', 'error'], true)) {
            return 'partial';
        }
        $started = $job->getStarted();
        if (null !== $started && ($this->now)()->getTimestamp() - $started->getTimestamp() > self::STALE_AFTER) {
            return 'partial';
        }
        return 'running';
    }

    private static function iso(?\DateTimeInterface $date): ?string
    {
        return $date?->format(DATE_ATOM);
    }
}
```

`latestUndoByBatch()` skips a `batchJobId` that is not an int. The controller always dispatches an
int (Task 5), so a string there means tampered args, which never count.

- [ ] **Step 4: Run tests to verify they pass**

Run: `vendor/bin/phpunit -c test/phpunit.xml test/Service/Governance/ test/Job/` and `make lint`
Expected: PASS, lint clean.

- [ ] **Step 5: Commit**

```bash
git add src/Service/Governance/BatchJobLookup.php test/Service/Governance/BatchJobLookupTest.php
git commit -m "feat: recent governance batches and their undo state from Job entities" -m "Model: <model name>"
```

---

### Task 5: Controller actions, ACL and wiring

**Files:**
- Modify: `src/Controller/Admin/GovernanceBatchController.php`
- Modify: `config/module.config.php` (controller factory, ~line 68)
- Modify: `Module.php` (batch ACL block, ~line 141)
- Test: `test/Controller/GovernanceBatchControllerTest.php`, `test/ModuleRuntimeTest.php`

**Interfaces:**
- Consumes: `BatchJobLookup::find/recent/undoState` (Task 4), `GovernanceBatchUndoJob` (Task 3),
  `BatchProgressReporter::UNDO_KIND` (Task 3).
- Produces: POST `recent` → `{batches: [{jobId, batch, owner:?string, started, ended, terms, mode, planned, status, undo:{state, jobId}}]}`;
  `owner` is null for the caller's own batches.
- Produces: POST `undo` `{batchJobId}` → `{jobId}` or `{error: method|csrf|id|not_found|not_batch|running|undo_running|dispatch|unexpected}`.
- Produces: `status`/`cancel` accept a `GovernanceBatchUndoJob` whose state kind is `governance-batch-undo`.
- Produces: `GovernanceBatchController::PRIVILEGE_UNDO_ANY = 'undo-any-batch'`; constructor gains a
  final `\Omeka\Permissions\Acl $acl` parameter. The form view gains `urls.recent` and `urls.undo`.

- [ ] **Step 1: Write the failing tests**

In `test/Controller/GovernanceBatchControllerTest.php`:

1. Add properties:

```php
    private bool $undoAny = false;
    private array $recentRows = [];
    private array $undoStates = [];
    private ?array $recentOwnerAsked = [];
```

2. Replace the `$jobs` mock setup in `setUp()` with:

```php
        $jobs = $this->createMock(BatchJobLookup::class);
        $jobs->method('find')->willReturnCallback(fn (int $id) => $this->jobMissing ? null : [
            'class' => $this->jobClass,
            'ownerId' => $this->jobOwner,
            'status' => $this->jobStatus,
            'args' => [],
        ]);
        $jobs->method('recent')->willReturnCallback(function (?int $ownerId) {
            $this->recentOwnerAsked = [$ownerId];
            return $this->recentRows;
        });
        $jobs->method('undoState')->willReturnCallback(
            fn (int $id) => $this->undoStates[$id] ?? ['state' => 'none', 'jobId' => null]
        );
        $acl = $this->createMock(\Omeka\Permissions\Acl::class);
        $acl->method('userIsAllowed')->willReturnCallback(
            fn ($resource, $privilege) => GovernanceBatchController::PRIVILEGE_UNDO_ANY === $privilege ? $this->undoAny : true
        );
```

and pass `$acl` as the last constructor argument (after `$jobs`).

3. Add `'recent'` and `'undo'` to the action list in `testPostActionsRejectGetAndInvalidCsrf`.

4. In `testFormServesOptionsCsrfAndUrls` add:

```php
        $this->assertSame('admin/oer-manager-batch/recent', $view->getVariable('urls')['recent']);
        $this->assertSame('admin/oer-manager-batch/undo', $view->getVariable('urls')['undo']);
```

5. Add tests:

```php
    public function testRecentScopesToTheOwnerUnlessAllowedToUndoAny(): void
    {
        $this->recentRows = [
            ['jobId' => 10, 'ownerId' => 3, 'ownerName' => 'Curator', 'started' => null, 'ended' => null,
                'terms' => ['dcterms:license'], 'mode' => 'fill', 'planned' => 2, 'status' => 'completed',
                'undo' => ['state' => 'none', 'jobId' => null]],
            ['jobId' => 12, 'ownerId' => 9, 'ownerName' => 'Other', 'started' => null, 'ended' => null,
                'terms' => [], 'mode' => 'replace', 'planned' => 5, 'status' => 'stopped',
                'undo' => ['state' => 'done', 'jobId' => 20]],
        ];

        $batches = $this->data('recent')['batches'];
        $this->assertSame([3], $this->recentOwnerAsked);
        $this->assertSame('batch-10', $batches[0]['batch']);
        $this->assertNull($batches[0]['owner']);
        $this->assertSame('Other', $batches[1]['owner']);
        $this->assertArrayNotHasKey('ownerId', $batches[0]);

        $this->undoAny = true;
        $this->data('recent');
        $this->assertSame([null], $this->recentOwnerAsked);
    }

    public function testUndoRefusalsAreCodes(): void
    {
        $this->params->post = ['csrf' => 'valid', 'batchJobId' => 0];
        $this->assertSame('id', $this->data('undo')['error']);

        $this->params->post['batchJobId'] = 10;
        $this->jobMissing = true;
        $this->assertSame('not_found', $this->data('undo')['error']);
        $this->jobMissing = false;

        $this->jobOwner = 99;
        $this->assertSame('not_found', $this->data('undo')['error']);
        $this->jobOwner = 3;

        $this->jobClass = \OERManager\Job\GovernanceBatchUndoJob::class;
        $this->assertSame('not_batch', $this->data('undo')['error']);
        $this->jobClass = \OERManager\Job\GovernanceBatchJob::class;

        $this->jobStatus = 'in_progress';
        $this->assertSame('running', $this->data('undo')['error']);
        $this->jobStatus = 'completed';

        $this->undoStates[10] = ['state' => 'running', 'jobId' => 20];
        $this->assertSame('undo_running', $this->data('undo')['error']);
    }

    public function testUndoDispatchesForTheOwnerOrAnyoneAllowedToUndoAny(): void
    {
        $this->params->post = ['csrf' => 'valid', 'batchJobId' => 10];
        $this->jobStatus = 'completed';
        $this->dispatcher->expects($this->exactly(2))->method('dispatch')
            ->with(\OERManager\Job\GovernanceBatchUndoJob::class, ['batchJobId' => 10, 'contributor' => 'Curator'])
            ->willReturn(new \Omeka\Entity\Job());

        $this->assertSame(1, $this->data('undo')['jobId']);

        $this->jobOwner = 99;
        $this->undoAny = true;
        $this->undoStates[10] = ['state' => 'partial', 'jobId' => 20];
        $this->assertSame(1, $this->data('undo')['jobId']);
    }

    public function testUndoDispatchFailureIsSanitised(): void
    {
        $this->params->post = ['csrf' => 'valid', 'batchJobId' => 10];
        $this->jobStatus = 'completed';
        $this->dispatcher->method('dispatch')->willThrowException(new \RuntimeException('secret'));

        $this->assertSame('dispatch', $this->data('undo')['error']);
    }

    public function testStatusAndCancelServeAnUndoJobToItsOwnerOnly(): void
    {
        $this->params->post = ['csrf' => 'valid', 'jobId' => 5];
        $this->jobClass = \OERManager\Job\GovernanceBatchUndoJob::class;
        $this->states->write(5, ['kind' => 'governance-batch', 'status' => 'in_progress']);
        $this->assertSame('not_batch', $this->data('status')['error']);

        $this->states->write(5, ['kind' => 'governance-batch-undo', 'status' => 'in_progress', 'done' => 1, 'total' => 4]);
        $this->assertSame(1, $this->data('status')['done']);

        $this->dispatcher->expects($this->once())->method('stop')->with(5);
        $this->assertTrue($this->data('cancel')['stopped']);

        $this->jobOwner = 99;
        $this->undoAny = true;
        $this->assertSame('not_found', $this->data('status')['error']);
    }
```

The last assertion pins that `undo-any-batch` lets a site_admin *start* an undo of another user's
batch, but never poll or stop another user's Job: the undo Job they start is their own.

6. In `test/ModuleRuntimeTest.php`, change `assertCount(5, $calls)` to `assertCount(6, $calls)`.
Change the slice 4 privileges assertion to
`['form', 'preview', 'apply', 'status', 'cancel', 'recent', 'undo']`, then add:

```php
        // TASK-028 slice 5a: deshacer el lote de otro usuario, solo site_admin.
        $this->assertSame(['site_admin'], $calls[5][0]);
        $this->assertSame([\OERManager\Controller\Admin\GovernanceBatchController::class], $calls[5][1]);
        $this->assertSame(['undo-any-batch'], $calls[5][2]);
```

- [ ] **Step 2: Run tests to verify they fail**

Run: `vendor/bin/phpunit -c test/phpunit.xml test/Controller/GovernanceBatchControllerTest.php test/ModuleRuntimeTest.php`
Expected: FAIL — unknown constructor argument / undefined actions / ACL count.

- [ ] **Step 3: Implement**

In `GovernanceBatchController.php`:

- Add `use OERManager\Job\GovernanceBatchUndoJob;` and `use Omeka\Permissions\Acl;`.
- Add constants and the constructor parameter:

```php
    public const PRIVILEGE_UNDO_ANY = 'undo-any-batch';

    /** Job class → the state kind its reporter writes. */
    private const KINDS = [
        GovernanceBatchJob::class => BatchProgressReporter::KIND,
        GovernanceBatchUndoJob::class => BatchProgressReporter::UNDO_KIND,
    ];
```

```php
        private BatchJobLookup $jobs,
        private Acl $acl
    ) {
```

- In `formAction()` urls, after `'cancel'`:

```php
                'recent' => $this->url()->fromRoute(self::ROUTE, ['action' => 'recent']),
                'undo' => $this->url()->fromRoute(self::ROUTE, ['action' => 'undo']),
```

- In `batchJob()` replace the class and kind checks with:

```php
        $kind = self::KINDS[$job['class']] ?? null;
        if (null === $kind) {
            return [null, null, new JsonModel(['error' => 'not_batch'])];
        }
        $state = $this->states->read($jobId);
        if (null !== $state && $kind !== ($state['kind'] ?? null)) {
            return [null, null, new JsonModel(['error' => 'not_batch'])];
        }
```

  and add to its docblock: «Serves the batch and its undo (slice 5a), each only to the Job's own
  owner: `undo-any-batch` lets a site_admin start an undo of someone else's batch, and the undo Job
  so started is theirs.»

- Add the two actions:

```php
    /**
     * The most recent finished batches the user may undo (slice 5a): their
     * own, or everyone's with `undo-any-batch`. `owner` names the author only
     * for someone else's batch.
     */
    public function recentAction()
    {
        if ($refusal = $this->guard()) {
            return $refusal;
        }
        $identity = $this->identity();
        if (null === $identity) {
            return new JsonModel(['error' => 'not_found']);
        }
        $me = (int) $identity->getId();
        $all = (bool) $this->acl->userIsAllowed(self::class, self::PRIVILEGE_UNDO_ANY);
        try {
            $rows = $this->jobs->recent($all ? null : $me);
        } catch (\Throwable $e) {
            $this->logger->err('OERManager governance batch recent: ' . $e->getMessage());
            return new JsonModel(['error' => 'unexpected']);
        }
        $batches = array_map(static fn (array $row): array => [
            'jobId' => $row['jobId'],
            'batch' => 'batch-' . $row['jobId'],
            'owner' => $row['ownerId'] === $me ? null : $row['ownerName'],
            'started' => $row['started'],
            'ended' => $row['ended'],
            'terms' => $row['terms'],
            'mode' => $row['mode'],
            'planned' => $row['planned'],
            'status' => $row['status'],
            'undo' => $row['undo'],
        ], $rows);
        return new JsonModel(['batches' => $batches]);
    }

    /**
     * Starts the undo of a finished batch (slice 5a). Only its owner, or a user
     * with `undo-any-batch`; anyone else is told `not_found`. The ids are not
     * posted: the undo Job reads them from the batch's own args.
     */
    public function undoAction()
    {
        if ($refusal = $this->guard()) {
            return $refusal;
        }
        $batchJobId = (int) $this->params()->fromPost('batchJobId');
        if ($batchJobId <= 0) {
            return new JsonModel(['error' => 'id']);
        }
        $identity = $this->identity();
        $job = $this->jobs->find($batchJobId);
        if (
            null === $job
            || null === $identity
            || ($job['ownerId'] !== (int) $identity->getId()
                && !$this->acl->userIsAllowed(self::class, self::PRIVILEGE_UNDO_ANY))
        ) {
            return new JsonModel(['error' => 'not_found']);
        }
        if (GovernanceBatchJob::class !== $job['class']) {
            return new JsonModel(['error' => 'not_batch']);
        }
        if (!in_array($job['status'], BatchJobLookup::FINISHED, true)) {
            return new JsonModel(['error' => 'running']);
        }
        if ('running' === $this->jobs->undoState($batchJobId)['state']) {
            return new JsonModel(['error' => 'undo_running']);
        }
        $this->states->sweepOld(86400);
        try {
            $undo = $this->jobDispatcher->dispatch(GovernanceBatchUndoJob::class, [
                'batchJobId' => $batchJobId,
                'contributor' => (string) $identity->getName(),
            ]);
        } catch (\Exception $e) {
            $this->logger->err('OERManager governance batch undo dispatch: ' . $e->getMessage());
            return new JsonModel(['error' => 'dispatch']);
        }
        return new JsonModel(['jobId' => (int) $undo->getId()]);
    }
```

  Update the class docblock's last sentence: «Preview writes nothing; apply and undo only dispatch;
  the Jobs do the writing.»

In `config/module.config.php`, the controller factory's last argument becomes:

```php
                    $container->get(Service\Governance\BatchJobLookup::class),
                    $container->get('Omeka\Acl')
```

In `Module.php`, extend the batch block:

```php
        $acl->allow(
            ['editor', 'site_admin', 'reviewer'],
            [Controller\Admin\GovernanceBatchController::class],
            ['form', 'preview', 'apply', 'status', 'cancel', 'recent', 'undo']
        );

        // Deshacer un lote ajeno (TASK-028 slice 5a): solo site_admin, para
        // cuando el autor del lote no está. `global_admin` ya lo tiene todo.
        // El Job de deshacer corre como quien lo lanza: el ACL nativo de cada
        // item sigue decidiendo.
        $acl->allow(
            ['site_admin'],
            [Controller\Admin\GovernanceBatchController::class],
            [Controller\Admin\GovernanceBatchController::PRIVILEGE_UNDO_ANY]
        );
```

- [ ] **Step 4: Run tests to verify they pass**

Run: `make test` and `make lint`
Expected: PASS (all suites), lint clean.

- [ ] **Step 5: Commit**

```bash
git add src/Controller/Admin/GovernanceBatchController.php config/module.config.php Module.php test/Controller/GovernanceBatchControllerTest.php test/ModuleRuntimeTest.php
git commit -m "feat: list recent batches and start a batch undo, owner or site_admin only" -m "Model: <model name>"
```

---

### Task 6: JS model for the recent list, the stored job and the undo result

**Files:**
- Modify: `asset/js/core/governanceBatchModel.js`
- Test: `test/js/governanceBatchModel.test.js`

**Interfaces:**
- Produces: `storedJob(raw): {jobId:number, kind:'batch'|'undo'} | null`. Reads the slice 5a JSON format
  and the slice 4 bare id.
- Produces: `rememberedJob(jobId, kind): string`, the JSON to store.
- Produces: `recentRowModel(batch, labels): {jobId, title, owner, terms: string[], mode: 'fill'|'replace'|'', planned, status, action: 'undo'|'retry'|null, badge: 'done'|'running'|'partial'|null}`.
- Produces: `undoResultModel(state): {finished:false, percent, status} | {finished:true, status, code, batch, figures:{undone, modifiedLater, notInBatch, alreadyUndone, failed}, review: list<{id, code}>}`.
- Produces: `UNDO_KIND = 'governance-batch-undo'`.

- [ ] **Step 1: Write the failing tests**

Add the five names to the test's import list, then append:

```js
test('a stored job reads the slice 5a format and the slice 4 bare id', () => {
    assert.deepEqual(storedJob(rememberedJob(12, 'undo')), { jobId: 12, kind: 'undo' });
    assert.deepEqual(storedJob(rememberedJob(7, 'batch')), { jobId: 7, kind: 'batch' });
    assert.deepEqual(storedJob('123'), { jobId: 123, kind: 'batch' });
    assert.equal(storedJob(null), null);
    assert.equal(storedJob(''), null);
    assert.equal(storedJob('{oops'), null);
    assert.equal(storedJob('0'), null);
    assert.equal(storedJob('{"jobId":5,"kind":"other"}'), null);
    assert.equal(storedJob('{"jobId":"5","kind":"undo"}'), null);
});

test('a recent batch row names its fields and offers only the possible action', () => {
    const labels = { 'dcterms:license': 'Licencia', 'dcterms:creator': 'Autoría' };
    const base = {
        jobId: 10, batch: 'batch-10', owner: null, terms: ['dcterms:license', 'dcterms:creator', 'x:unknown'],
        mode: 'replace', planned: 2950, status: 'completed', undo: { state: 'none', jobId: null }
    };

    const row = recentRowModel(base, labels);
    assert.deepEqual(row.terms, ['Licencia', 'Autoría', 'x:unknown']);
    assert.equal(row.title, 'batch-10');
    assert.equal(row.mode, 'replace');
    assert.equal(row.action, 'undo');
    assert.equal(row.badge, null);

    assert.deepEqual(
        ['none', 'running', 'done', 'partial', 'bogus'].map((state) => {
            const model = recentRowModel({ ...base, undo: { state, jobId: 3 } }, labels);
            return [model.action, model.badge];
        }),
        [['undo', null], [null, 'running'], [null, 'done'], ['retry', 'partial'], [null, null]]
    );
    assert.equal(recentRowModel({ ...base, mode: 'weird', undo: undefined }, labels).mode, '');
    assert.equal(recentRowModel({ ...base, undo: undefined }, labels).action, 'undo');
});

test('the undo result counts every category and lists only REA to review', () => {
    assert.deepEqual(
        undoResultModel({ status: 'in_progress', done: 30, total: 120 }),
        { finished: false, percent: 25, status: 'in_progress' }
    );
    const done = undoResultModel({
        kind: UNDO_KIND,
        status: 'completed',
        batch: 'batch-21',
        tallies: {
            undone: 2870, modified_later: 60, not_in_batch: 15, already_undone: 5,
            failed: [{ id: 9, code: 'denied' }],
            review: [{ id: 4, code: 'modified_later' }, { id: 9, code: 'denied' }]
        }
    });
    assert.equal(done.finished, true);
    assert.deepEqual(done.figures, { undone: 2870, modifiedLater: 60, notInBatch: 15, alreadyUndone: 5, failed: 1 });
    assert.deepEqual(done.review, [{ id: 4, code: 'modified_later' }, { id: 9, code: 'denied' }]);
    assert.equal(done.batch, 'batch-21');

    const unreadable = undoResultModel({ status: 'error', tallies: { code: 'plan_unreadable' } });
    assert.equal(unreadable.code, 'plan_unreadable');
    assert.equal(undoResultModel({ status: 'error', code: 'job_died' }).code, 'job_died');
    assert.deepEqual(undoResultModel({ status: 'completed' }).figures,
        { undone: 0, modifiedLater: 0, notInBatch: 0, alreadyUndone: 0, failed: 0 });
});
```

- [ ] **Step 2: Run tests to verify they fail**

Run: `node --test test/js/governanceBatchModel.test.js`
Expected: FAIL — imports not exported.

- [ ] **Step 3: Implement**

Append to `asset/js/core/governanceBatchModel.js`:

```js
export const UNDO_KIND = 'governance-batch-undo';

const JOB_KINDS = ['batch', 'undo'];

/** What localStorage keeps for the tracked Job (slice 5a: the kind too). */
export function rememberedJob(jobId, kind) {
    return JSON.stringify({ jobId, kind });
}

/**
 * The tracked Job, or null. A slice 4 tab stored the bare id: it is a batch.
 */
export function storedJob(raw) {
    if (null === raw || undefined === raw || '' === raw) {
        return null;
    }
    let parsed;
    try {
        parsed = JSON.parse(raw);
    } catch (error) {
        return null;
    }
    if ('number' === typeof parsed) {
        return Number.isInteger(parsed) && parsed > 0 ? { jobId: parsed, kind: 'batch' } : null;
    }
    if (parsed && Number.isInteger(parsed.jobId) && parsed.jobId > 0 && JOB_KINDS.includes(parsed.kind)) {
        return { jobId: parsed.jobId, kind: parsed.kind };
    }
    return null;
}

const UNDO_ACTION = { none: 'undo', partial: 'retry' };
const UNDO_BADGE = { running: 'running', done: 'done', partial: 'partial' };

/** One row of «Lotes recientes»: labels for its fields, and which action or badge it shows. */
export function recentRowModel(batch, labels) {
    const state = (batch.undo && batch.undo.state) || 'none';
    return {
        jobId: batch.jobId,
        title: batch.batch,
        owner: batch.owner || null,
        terms: (batch.terms || []).map((term) => labels[term] || term),
        mode: ['fill', 'replace'].includes(batch.mode) ? batch.mode : '',
        planned: batch.planned,
        status: batch.status,
        action: UNDO_ACTION[state] || null,
        badge: UNDO_BADGE[state] || null
    };
}

/** Progress and outcome of a batch undo. */
export function undoResultModel(state) {
    if ('in_progress' === state.status) {
        const percent = state.total > 0 ? Math.floor((state.done / state.total) * 100) : 0;
        return { finished: false, percent, status: 'in_progress' };
    }
    const tallies = state.tallies || {};
    return {
        finished: true,
        status: state.status,
        code: state.code || tallies.code,
        batch: state.batch,
        figures: {
            undone: tallies.undone || 0,
            modifiedLater: tallies.modified_later || 0,
            notInBatch: tallies.not_in_batch || 0,
            alreadyUndone: tallies.already_undone || 0,
            failed: (tallies.failed || []).length
        },
        review: tallies.review || []
    };
}
```

- [ ] **Step 4: Run tests to verify they pass**

Run: `make test-js`
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add asset/js/core/governanceBatchModel.js test/js/governanceBatchModel.test.js
git commit -m "feat(js): model for recent batches, the tracked job and the undo result" -m "Model: <model name>"
```

---

### Task 7: Sidebar UI — recent list, confirmation, undo progress and reattach

**Files:**
- Modify: `view/oer-manager/admin/governance-batch/form.phtml`
- Modify: `asset/js/ui/governanceBatch.js`
- Modify: `asset/css/oer-master-view.css`

**Interfaces:**
- Consumes: Task 5 endpoints (`urls.recent`, `urls.undo`, `status`, `cancel`) and Task 6 model functions.
- Produces: no new exports. `initGovernanceBatch(config)` keeps its signature.

There is no DOM test harness in this repository (`node --test` runs pure modules only). The logic
lives in Task 6's tested model. This task is wiring, verified by `make test-js` (syntax and imports),
by `node --check asset/js/ui/governanceBatch.js`, and in the browser under TASK-030.

- [ ] **Step 1: Form markup**

In `form.phtml`, add two data attributes after `data-cancel-url`:

```php
    data-recent-url="<?php echo $escape($urls['recent']); ?>"
    data-undo-url="<?php echo $escape($urls['undo']); ?>"
```

and after `<div class="oer-batch-progress" hidden></div>`:

```php
    <details class="oer-batch-recent">
        <summary><?php echo $translate('Lotes recientes'); /* @translate */ ?></summary>
        <p class="oer-batch-recent-error" hidden></p>
        <ul class="oer-batch-recent-list"></ul>
        <div class="oer-batch-undo-confirm" hidden></div>
    </details>
```

Update the file docblock's first sentence to «Batch licence and authorship form (TASK-028 slice 4)
and the undo of recent batches (slice 5a), served into the master view's batch sidebar.»

- [ ] **Step 2: Wire the JS**

In `asset/js/ui/governanceBatch.js`:

1. Extend the model import with `storedJob, rememberedJob, recentRowModel, undoResultModel, UNDO_KIND`.

2. Add error texts. In `PREVIEW_ERROR_TEXT`:

```js
    running: 'El lote todavía no ha terminado.',
    undo_running: 'Ya se está deshaciendo este lote.',
```

In `JOB_ERROR_TEXT`:

```js
    plan_unreadable: 'No se puede leer qué REA tocó este lote; no se ha deshecho nada.',
```

Add:

```js
const MODE_TEXT = { fill: 'Rellenar solo vacíos', replace: 'Sustituir' };
const JOB_STATUS_TEXT = { completed: 'completado', stopped: 'cancelado', error: 'con error' };
const UNDO_BADGE_TEXT = { done: 'Deshecho', running: 'Deshaciendo…', partial: 'Deshecho parcialmente' };
const REVIEW_TEXT = {
    modified_later: 'modificado después del lote',
    denied: 'sin permiso',
    not_found: 'ya no existe',
    invalid: 'valor no válido',
    unexpected: 'error inesperado'
};
```

3. Replace the tracked-Job storage. Every `storage.setItem(JOB_KEY, String(applied.jobId))` becomes
`storage.setItem(JOB_KEY, rememberedJob(applied.jobId, 'batch'))`. Read it through:

```js
function trackedJob() {
    return storedJob(safeStorage((storage) => storage.getItem(JOB_KEY)));
}

function track(jobId, kind) {
    safeStorage((storage) => storage.setItem(JOB_KEY, rememberedJob(jobId, kind)));
}

function untrack() {
    safeStorage((storage) => storage.removeItem(JOB_KEY));
}
```

Replace the existing `storage.removeItem(JOB_KEY)` calls with `untrack()`. Replace the `apply` path's
`setItem` with `track(applied.jobId, 'batch')`. In the reattach block at the end of
`initGovernanceBatch`, test `trackedJob()` instead of the raw `getItem`.

4. Make `poll` and `renderProgress` kind-aware. Change the signature to
`poll(root, job, urls, csrf, retries = 0)` where `job = {jobId, kind}`. Post `['jobId', String(job.jobId)]`,
recurse with `job`, call `renderProgress(root, { ...state, jobId: job.jobId }, urls, csrf, job.kind)`, and
finish with `resultModel` or `undoResultModel` by kind:

```js
        const finished = ('undo' === job.kind ? undoResultModel(state) : resultModel(state)).finished;
        renderProgress(root, { ...state, jobId: job.jobId }, urls, csrf, job.kind);
        if (finished) {
            untrack();
            return;
        }
        pollTimer = window.setTimeout(() => poll(root, job, urls, csrf), POLL_MS);
```

In `renderProgress(root, state, urls, csrf, kind = 'batch')`, branch at the top:

```js
    if ('undo' === kind) {
        renderUndoProgress(root, state, urls, csrf);
        return;
    }
```

In the finished batch branch, after the batch id paragraph, offer the undo when something may have
been written:

```js
    if (model.batch && 'error' !== model.status) {
        const undo = document.createElement('button');
        undo.type = 'button';
        undo.className = 'button oer-batch-undo-this';
        undo.textContent = t('Deshacer este lote');
        undo.addEventListener('click', () => confirmUndo(root, {
            jobId: state.jobId, title: model.batch, terms: [], mode: '', planned: state.tallies ? state.tallies.total : null
        }, urls, csrf));
        box.appendChild(undo);
    }
```

5. Add the undo rendering and flow:

```js
function renderUndoProgress(root, state, urls, csrf) {
    const box = root.querySelector('.oer-batch-progress');
    box.hidden = false;
    box.textContent = '';
    const model = undoResultModel(state);
    if (!model.finished) {
        const bar = document.createElement('progress');
        bar.max = 100;
        bar.value = model.percent;
        const label = document.createElement('p');
        label.textContent = state.total
            ? t('Deshaciendo: %1$s de %2$s REA').replace('%1$s', state.done).replace('%2$s', state.total)
            : t('Iniciando…');
        const cancel = document.createElement('button');
        cancel.type = 'button';
        cancel.textContent = t('Cancelar el deshacer');
        cancel.addEventListener('click', () => {
            cancel.disabled = true;
            post(urls.cancel, [['csrf', csrf], ['jobId', String(state.jobId)]]);
        });
        box.append(bar, label, cancel);
        return;
    }
    const summary = document.createElement('p');
    if ('error' === model.status) {
        summary.textContent = t(JOB_ERROR_TEXT[model.code] || JOB_ERROR_TEXT.unexpected);
        box.appendChild(summary);
    } else {
        const f = model.figures;
        summary.textContent = t('%1$s deshechos · %2$s modificados después del lote · %3$s no escritos por el lote · %4$s ya deshechos · %5$s fallidos')
            .replace('%1$s', f.undone)
            .replace('%2$s', f.modifiedLater)
            .replace('%3$s', f.notInBatch)
            .replace('%4$s', f.alreadyUndone)
            .replace('%5$s', f.failed);
        if ('stopped' === model.status) {
            summary.textContent = `${t('Deshacer cancelado.')} ${summary.textContent}`;
        }
        box.appendChild(summary);
        if (model.review.length) {
            const intro = document.createElement('p');
            intro.textContent = t('Revisa a mano estos REA:');
            const list = document.createElement('ul');
            model.review.forEach(({ id, code }) => {
                const li = document.createElement('li');
                const link = document.createElement('a');
                link.href = urls.item.replace('__ID__', String(id));
                link.target = '_blank';
                link.textContent = `#${id}`;
                li.append(link, ` — ${t(REVIEW_TEXT[code] || REVIEW_TEXT.unexpected)}`);
                list.appendChild(li);
            });
            box.append(intro, list);
        }
    }
    const reload = document.createElement('button');
    reload.type = 'button';
    reload.className = 'button';
    reload.textContent = t('Recargar la vista');
    reload.addEventListener('click', () => window.location.reload());
    box.appendChild(reload);
}

/** Hides the form while a Job is tracked; progress takes its place. */
function hideForm(root) {
    ['.oer-batch-target', '.oer-batch-fields', '.oer-batch-mode', '.oer-batch-actions', '.oer-batch-preview', '.oer-batch-recent']
        .forEach((selector) => {
            const el = root.querySelector(selector);
            if (el) {
                el.hidden = true;
            }
        });
}

function rowSummary(row) {
    const parts = [];
    if (row.terms.length) {
        parts.push(row.terms.map((term) => t(term)).join(', '));
    }
    if (row.mode) {
        parts.push(t(MODE_TEXT[row.mode]));
    }
    if (null !== row.planned && undefined !== row.planned) {
        parts.push(t('%1$s REA en el plan').replace('%1$s', row.planned));
    }
    return parts.join(' · ');
}

function confirmUndo(root, row, urls, csrf) {
    const box = root.querySelector('.oer-batch-undo-confirm');
    const details = root.querySelector('.oer-batch-recent');
    details.open = true;
    box.textContent = '';
    box.hidden = false;
    const text = document.createElement('p');
    const summary = rowSummary(row);
    text.textContent = `${t('Deshacer el lote %1$s').replace('%1$s', row.title)}${summary ? ` (${summary})` : ''}. `
        + t('Se restaurarán los valores anteriores al lote. Los REA modificados después del lote no se tocarán y aparecerán en el resultado.');
    const go = document.createElement('button');
    go.type = 'button';
    go.className = 'button oer-batch-undo-go';
    go.textContent = t('Deshacer lote');
    const cancel = document.createElement('button');
    cancel.type = 'button';
    cancel.textContent = t('Cancelar');
    cancel.addEventListener('click', () => {
        box.hidden = true;
        box.textContent = '';
    });
    go.addEventListener('click', () => {
        go.disabled = true;
        post(urls.undo, [['csrf', csrf], ['batchJobId', String(row.jobId)]]).then((response) => {
            if (response.error) {
                go.disabled = false;
                showErrors(root, { _: response.error });
                return;
            }
            box.hidden = true;
            track(response.jobId, 'undo');
            hideForm(root);
            poll(root, { jobId: response.jobId, kind: 'undo' }, urls, csrf);
        });
    });
    box.append(text, go, cancel);
}

function renderRecent(root, batches, urls, csrf) {
    const list = root.querySelector('.oer-batch-recent-list');
    list.textContent = '';
    if (!batches.length) {
        const li = document.createElement('li');
        li.textContent = t('No hay lotes que puedas deshacer.');
        list.appendChild(li);
        return;
    }
    batches.forEach((batch) => {
        const row = recentRowModel(batch, TERM_LABELS);
        const li = document.createElement('li');
        li.className = 'oer-batch-recent-row';
        const head = document.createElement('p');
        const when = batch.started ? new Date(batch.started).toLocaleString() : '';
        head.textContent = [row.title, when, row.owner].filter(Boolean).join(' · ');
        const meta = document.createElement('p');
        meta.className = 'oer-batch-recent-meta';
        meta.textContent = [rowSummary(row), t(JOB_STATUS_TEXT[row.status] || row.status)].filter(Boolean).join(' · ');
        li.append(head, meta);
        if (row.badge) {
            const badge = document.createElement('span');
            badge.className = `oer-batch-undo-badge oer-batch-undo-${row.badge}`;
            badge.textContent = t(UNDO_BADGE_TEXT[row.badge]);
            li.appendChild(badge);
        }
        if (row.action) {
            const button = document.createElement('button');
            button.type = 'button';
            button.className = 'button oer-batch-undo-open';
            button.textContent = 'retry' === row.action ? t('Reintentar deshacer') : t('Deshacer lote');
            button.addEventListener('click', () => confirmUndo(root, row, urls, csrf));
            li.appendChild(button);
        }
        list.appendChild(li);
    });
}

function mountRecent(root, urls, csrf) {
    const details = root.querySelector('.oer-batch-recent');
    if (!details) {
        return;
    }
    let loaded = false;
    details.addEventListener('toggle', () => {
        if (!details.open || loaded) {
            return;
        }
        loaded = true;
        const error = root.querySelector('.oer-batch-recent-error');
        error.hidden = true;
        post(urls.recent, [['csrf', csrf]]).then((response) => {
            if (response.error) {
                loaded = false;
                error.textContent = t(PREVIEW_ERROR_TEXT[response.error] || PREVIEW_ERROR_TEXT.unexpected);
                error.hidden = false;
                return;
            }
            renderRecent(root, response.batches || [], urls, csrf);
        });
    });
}
```

`rowSummary` passes the field labels through `t()` because `TERM_LABELS` holds Spanish source strings,
the same way `renderPreview` does with `t(TERM_LABELS[row.term] || row.term)`.

6. In `mountForm`:
   - Add `recent: root.dataset.recentUrl, undo: root.dataset.undoUrl` to `urls`.
   - Replace the pending block with:

```js
    const pending = trackedJob();
    if (pending) {
        onSelectionChange = null;
        hideForm(root);
        poll(root, pending, urls, csrf);
        return;
    }
```

   - After `buildFields(root, governance);` add `mountRecent(root, urls, csrf);`.
   - In the apply callback, replace the two `.hidden = true` lines and `poll(...)` with
     `track(applied.jobId, 'batch'); hideForm(root); poll(root, { jobId: applied.jobId, kind: 'batch' }, urls, csrf);`.

- [ ] **Step 3: Styles**

Append to the slice 4 block of `asset/css/oer-master-view.css`:

```css
.oer-batch-governance .oer-batch-recent { margin-top: 1.5em; }
.oer-batch-governance .oer-batch-recent summary { cursor: pointer; font-weight: bold; }
.oer-batch-governance .oer-batch-recent-list { list-style: none; margin: .5em 0 0; padding: 0; }
.oer-batch-governance .oer-batch-recent-row { padding: .5em 0; border-top: 1px solid var(--oer-border, #ddd); }
.oer-batch-governance .oer-batch-recent-row p { margin: 0; }
.oer-batch-governance .oer-batch-recent-meta { font-size: .9em; }
.oer-batch-governance .oer-batch-undo-badge { display: inline-block; margin: .25em .5em .25em 0; font-size: .9em; }
.oer-batch-governance .oer-batch-undo-partial { color: var(--oer-warn, #955c0d); }
.oer-batch-governance .oer-batch-undo-confirm { margin-top: .75em; padding: .5em .75em; background: var(--oer-surface, #f6f6f6); }
```

- [ ] **Step 4: Verify**

Run: `node --check asset/js/ui/governanceBatch.js && make test-js && make lint && make test`
Expected: all PASS. Then `grep -n "JOB_KEY" asset/js/ui/governanceBatch.js`: the only uses must be the
constant and the bodies of `trackedJob`, `track` and `untrack`.

- [ ] **Step 5: Commit**

```bash
git add view/oer-manager/admin/governance-batch/form.phtml asset/js/ui/governanceBatch.js asset/css/oer-master-view.css
git commit -m "feat(ui): recent batches, undo confirmation and undo progress in the batch sidebar" -m "Model: <model name>"
```

---

### Task 8: Container harness on disposable fixtures

**Files:**
- Create: `test/container/governance-batch-undo-check.php`

**Interfaces:**
- Consumes: everything above, through real services and the real Job dispatcher (synchronous strategy).

- [ ] **Step 1: Write the harness**

```php
<?php

/**
 * Container harness for TASK-028 slice 5a: undo a whole governance batch.
 *
 * DISPOSABLE FIXTURES ONLY. Creates N private REA of its own, runs a batch over
 * them, undoes it and deletes every fixture at the end, even on failure. Never
 * touches a catalogue item. Requires --write <userEmail>.
 *
 *   php /var/www/html/modules/OERManager/test/container/governance-batch-undo-check.php --write <email> [fixtures=200]
 *
 * Exits 1 if any check fails.
 */

require '/var/www/html/bootstrap.php';

use OERManager\Job\GovernanceBatchJob;
use OERManager\Job\GovernanceBatchUndoJob;
use OERManager\Service\Ai\ProgressReporter;
use OERManager\Service\Ai\ProposalStore;
use OERManager\Service\Curation\UndoRouter;
use OERManager\Service\Governance\BatchJobLookup;
use OERManager\Service\Governance\BatchUndoRunner;
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
    if ($condition) {
        $passed++;
        echo "  OK   $label\n";
        return;
    }
    $failed++;
    echo "  FAIL $label" . ('' !== $detail ? " — $detail" : '') . "\n";
}

$args = array_slice($argv, 1);
if ('--write' !== ($args[0] ?? '')) {
    fwrite(STDERR, "usage: php governance-batch-undo-check.php --write <userEmail> [fixtures]\n");
    exit(2);
}
$entityManager = $services->get('Omeka\EntityManager');
$user = $entityManager->getRepository(\Omeka\Entity\User::class)->findOneBy(['email' => (string) ($args[1] ?? '')]);
if (null === $user) {
    fwrite(STDERR, "user not found: '" . ($args[1] ?? '') . "'\n");
    exit(2);
}
$services->get('Omeka\AuthenticationService')->getStorage()->write($user);
$contributor = $user->getEmail();
$userId = (int) $user->getId();
$count = max(20, (int) ($args[2] ?? 200));
printf("authenticated as %s (%s); %d fixtures\n", $user->getEmail(), $user->getRole(), $count);

/** @var GovernanceService $governance */
$governance = $services->get(GovernanceService::class);
/** @var RecatalogService $recatalog */
$recatalog = $services->get(RecatalogService::class);
/** @var ProposalStore $states */
$states = $services->get(ProposalStore::class);

$classes = $api->search('resource_classes', ['term' => MasterViewQuery::LEARNING_RESOURCE_CLASS_TERM])->getContent();
if (!$classes) {
    fwrite(STDERR, "The lrmi:LearningResource class does not exist in this installation.\n");
    exit(1);
}
$classId = (int) $classes[0]->id();
$pid = static fn (string $term): int => (int) $api->search('properties', ['term' => $term])->getContent()[0]->id();
$creatorOf = static function (int $id) use ($api, $governance): array {
    $values = $governance->read($api->read('items', $id)->getContent())['values'];
    return array_column($values[GovernanceFields::CREATOR] ?? [], 'value');
};

/**
 * Runs a Job synchronously and returns [jobId, state, seconds, memoryGrowth].
 * Same one-process precautions as governance-batch-check.php: start from a
 * clean unit of work and re-authenticate after each Job.
 */
$runJob = static function (string $class, array $jobArgs) use ($services, $states, $userId): array {
    $dispatcher = $services->get('Omeka\Job\Dispatcher');
    $strategy = $services->has('Omeka\Job\DispatchStrategy\Synchronous')
        ? $services->get('Omeka\Job\DispatchStrategy\Synchronous')
        : null;
    $em = $services->get('Omeka\EntityManager');
    $em->clear();
    $services->get('Omeka\AuthenticationService')->getStorage()->write($em->find(\Omeka\Entity\User::class, $userId));
    gc_collect_cycles();
    $before = memory_get_usage();
    $start = microtime(true);
    $job = null === $strategy
        ? $dispatcher->dispatch($class, $jobArgs)
        : $dispatcher->dispatch($class, $jobArgs, $strategy);
    $seconds = microtime(true) - $start;
    $jobId = (int) $job->getId();
    $services->get('Omeka\AuthenticationService')->getStorage()
        ->write($services->get('Omeka\EntityManager')->find(\Omeka\Entity\User::class, $userId));
    return [$jobId, $states->read($jobId), $seconds, memory_get_usage() - $before];
};

$fixtures = [];
$canary = [];

try {
    echo "\n1. fixtures\n";
    for ($i = 0; $i < $count; $i++) {
        $data = [
            'o:is_public' => false,
            'o:resource_class' => ['o:id' => $classId],
            'dcterms:title' => [['type' => 'literal', 'property_id' => $pid('dcterms:title'),
                '@value' => "governance-batch-undo-check fixture $i (safe to delete)"]],
            'dcterms:description' => [['type' => 'literal', 'property_id' => $pid('dcterms:description'),
                '@value' => 'Temporary REA created by test/container/governance-batch-undo-check.php.']],
            'dcterms:creator' => [['type' => 'literal', 'property_id' => $pid('dcterms:creator'),
                '@value' => 'Original Author']],
        ];
        $id = (int) $api->create('items', $data)->getContent()->id();
        $fixtures[] = $id;
        $item = $api->read('items', $id)->getContent();
        $canary[$id] = [(string) $item->value('dcterms:title'), (string) $item->value('dcterms:description')];
    }
    sort($fixtures);
    check('fixtures created', $count === count($fixtures));

    echo "\n2. a replace batch\n";
    [$batchJob, $batchState] = $runJob(GovernanceBatchJob::class, [
        'ids' => $fixtures,
        'raw' => [GovernanceFields::CREATOR => ['Batch Author']],
        'mode' => 'replace',
        'contributor' => $contributor,
    ]);
    check('batch completed and wrote every fixture',
        'completed' === ($batchState['status'] ?? null) && $count === (int) ($batchState['tallies']['written'] ?? -1),
        json_encode($batchState['tallies'] ?? null));

    echo "\n3. work after the batch\n";
    $modified = array_slice($fixtures, 0, 3);
    foreach ($modified as $id) {
        $governance->apply($id, [GovernanceFields::CREATOR => ['Later Edit']], $contributor);
    }
    $byHand = array_slice($fixtures, 3, 2);
    $router = $services->get(UndoRouter::class);
    foreach ($byHand as $id) {
        $router->undo($id, $contributor);
    }
    check('3 REA edited and 2 undone by hand after the batch',
        ['Later Edit'] === $creatorOf($modified[0]) && ['Original Author'] === $creatorOf($byHand[0]));

    echo "\n4. recent and undo state\n";
    $lookup = $services->get(BatchJobLookup::class);
    $recent = $lookup->recent($userId);
    check('the batch is listed as recent and not undone',
        $batchJob === ($recent[0]['jobId'] ?? null) && 'none' === ($recent[0]['undo']['state'] ?? null)
        && $count === ($recent[0]['planned'] ?? null), json_encode($recent[0] ?? null));

    echo "\n5. batch undo through the real job\n";
    [$undoJob, $undoState, $undoSeconds, $undoGrowth] = $runJob(GovernanceBatchUndoJob::class, [
        'batchJobId' => $batchJob,
        'contributor' => $contributor,
    ]);
    $tallies = $undoState['tallies'] ?? [];
    check('undo completed', 'completed' === ($undoState['status'] ?? null), json_encode($undoState));
    check('exact figures: undone, modified later, already undone',
        $count - 5 === ($tallies['undone'] ?? -1) && 3 === ($tallies['modified_later'] ?? -1)
        && 2 === ($tallies['already_undone'] ?? -1) && 0 === ($tallies['not_in_batch'] ?? -1)
        && [] === ($tallies['failed'] ?? null), json_encode($tallies));
    $restored = true;
    foreach (array_slice($fixtures, 5) as $id) {
        $restored = $restored && ['Original Author'] === $creatorOf($id);
    }
    check('pre-batch values restored on every undone REA', $restored);
    check('later edits untouched', ['Later Edit'] === $creatorOf($modified[0]) && ['Later Edit'] === $creatorOf($modified[2]));
    $tagged = true;
    foreach (array_slice($fixtures, 5) as $id) {
        $tagged = $tagged && ('batch-' . $undoJob) === ($recatalog->lastEvent($id)['payload']['batch'] ?? null);
    }
    check('every batch-undo event carries batch-' . $undoJob, $tagged);
    $intact = true;
    foreach ($fixtures as $id) {
        $item = $api->read('items', $id)->getContent();
        $intact = $intact && $canary[$id] === [(string) $item->value('dcterms:title'), (string) $item->value('dcterms:description')];
    }
    check('titles and descriptions untouched', $intact);
    check('the list now shows the batch as undone', 'done' === ($lookup->undoState($batchJob)['state'] ?? null),
        json_encode($lookup->undoState($batchJob)));

    echo "\n6. relaunch writes nothing\n";
    [, $againState] = $runJob(GovernanceBatchUndoJob::class, ['batchJobId' => $batchJob, 'contributor' => $contributor]);
    check('a second undo undoes nothing', 0 === ($againState['tallies']['undone'] ?? -1)
        && $count - 3 === ($againState['tallies']['already_undone'] ?? -1), json_encode($againState['tallies'] ?? null));

    echo "\n7. cancel between REA (runner level, spec R2)\n";
    [$secondBatch] = $runJob(GovernanceBatchJob::class, [
        'ids' => $fixtures,
        'raw' => [GovernanceFields::CREATOR => ['Second Batch']],
        'mode' => 'replace',
        'contributor' => $contributor,
    ]);
    $stopper = new class implements ProgressReporter {
        private int $checks = 0;

        public function report(string $step, int $done, int $total): void
        {
        }

        public function shouldStop(): bool
        {
            return ++$this->checks > 3;
        }
    };
    $partial = (new BatchUndoRunner())->run(
        $fixtures,
        'batch-' . $secondBatch,
        static fn (int $id): array => $recatalog->events($id),
        static fn (int $id, array $event): array => $governance->undoEvent($id, $event, $contributor, false, 'batch-cancel'),
        $stopper
    );
    check('cancel stopped after 3 REA, all 3 undone',
        true === $partial['stopped'] && 3 === $partial['done'] && 3 === $partial['undone'], json_encode($partial));

    echo "\n8. throughput (not a check)\n";
    $perItem = $undoSeconds / max(1, $count);
    $perItemBytes = $undoGrowth / max(1, $count);
    printf(
        "   per REA: %.1f ms · memory growth: %.1f KB/REA\n   extrapolated to 3000: %.1f min, +%.0f MB\n",
        $perItem * 1000,
        $perItemBytes / 1024,
        ($perItem * 3000) / 60,
        ($perItemBytes * 3000) / 1048576
    );
} finally {
    $entityManager->clear();
    $services->get('Omeka\AuthenticationService')->getStorage()
        ->write($entityManager->find(\Omeka\Entity\User::class, $userId));
    $deleted = 0;
    foreach ($fixtures as $id) {
        try {
            $api->delete('items', $id);
            $deleted++;
        } catch (\Throwable $e) {
            echo "   could not delete fixture #$id: " . $e->getMessage() . "\n";
        }
    }
    printf("\n   deleted %d of %d fixtures\n", $deleted, count($fixtures));
    check('every fixture deleted', $deleted === count($fixtures),
        'delete leftovers whose title starts with "governance-batch-undo-check fixture"');
}

printf("\n%d OK, %d FAIL\n", $passed, $failed);
exit($failed ? 1 : 0);
```

Why the relaunch expects `already_undone` = `$count - 3`: the 2 REA undone by hand were already
`already_undone`; the `$count - 5` undone in step 5 now are too; the 3 edited later stay `modified_later`.

- [ ] **Step 2: Lint and syntax**

Run: `php -l test/container/governance-batch-undo-check.php && make lint`
Expected: no syntax errors, lint clean.

- [ ] **Step 3: Run in the container**

Running needs the module mounted in the development container and a user email. The owner
authorised `admin@example.com` for slice 4's harness. Ask before running if the owner has not
re-confirmed it for this slice; the harness only writes its own fixtures. Command:

`docker exec <container> php /var/www/html/modules/OERManager/test/container/governance-batch-undo-check.php --write admin@example.com 200`

Expected: every check `OK`, `0 FAIL`, every fixture deleted. Record the per-REA time and memory in
the report.

- [ ] **Step 4: Commit**

```bash
git add test/container/governance-batch-undo-check.php
git commit -m "test(container): batch undo harness on disposable fixtures" -m "Model: <model name>"
```

---

### Task 9: Governance documents

**Files:**
- Modify: `docs/decisions/0020-curation-event-typed-values.md` (append)
- Modify: `docs/backlog.md` (TASK-028 row, line starting `| TASK-028 `)
- Modify: `docs/traceability.md` (row containing `RF-015, RF-006`)
- Modify: `docs/project-memory.md` (after the slice 4 entry)

- [ ] **Step 1: ADR-0020 second addendum**

Append to the end of the file:

```markdown

## Addendum (2026-09-30, TASK-028 slice 5a): batch-undo events are tagged too

An undo written by the batch undo carries `"batch": "batch-<undoJobId>"`, the id of the
`GovernanceBatchUndoJob` that wrote it, so a mass reversal is identifiable in an item's history.
A per-item undo still carries no tag. Replay is unchanged: `undoEvent()` restores `before`, and an
undo of a batch-undo event (a per-item redo) is an ordinary untagged undo. The batch undo finds a
batch's REA from the original Job's `ids` arg, never by scanning the catalogue. It undoes an REA
only while the batch's event is still its last event and its values are unchanged (owner decision
D2 of the slice 5a design); everything else is skipped and reported.
```

- [ ] **Step 2: Backlog**

In the TASK-028 row:
- Append to the description cell, before the `| rebanadas 1, 2, 3a, 3b y 4 **hechas**` cell, a
  paragraph that starts `**SLICE 5a DONE AND VERIFIED (<date>)**`. It should give:
  - the spec and plan paths;
  - the owner decisions D1–D3 in one line each;
  - rulings R1–R3;
  - the suite counts and the container figures (checks, per-REA time and memory, extrapolation to 3000);
  - `**Not verified, declared:** the list, confirmation and result in a real browser (TASK-030 debt).`
- Change the status cell to: `rebanadas 1, 2, 3a, 3b, 4 y 5a **hechas**; 5b (cola de calidad) y 5c (normalizar tipo de recurso) pendientes de spec`.

- [ ] **Step 3: Traceability and memory**

- In `docs/traceability.md`, in the RF-015 row, append to the implementation cell: `TASK-028 slice 5a: batch undo (GovernanceBatchUndoJob, BatchUndoRunner, recent batches), ADR-0020 second addendum.`
- In `docs/project-memory.md`, after the slice 4 entry, add a `**TASK-028 slice 5a (batch undo): done (<date>).**`
  entry with lessons learned during execution. Always include this one: «An Omeka Job killed
  mid-run stays `in_progress` forever; any "is it running?" check needs a staleness bound
  (`BatchJobLookup::STALE_AFTER`).»

- [ ] **Step 4: Verify and commit**

Run: `make lint && make test && make test-js`
Expected: PASS.

```bash
git add docs/decisions/0020-curation-event-typed-values.md docs/backlog.md docs/traceability.md docs/project-memory.md
git commit -m "docs: close TASK-028 slice 5a" -m "Model: <model name>"
```

---

## Self-review notes

- Spec coverage: §4 components → Tasks 1–5; §5 rule → Task 2; §6 endpoints → Task 5; §7 UI → Tasks 6–7;
  §8 scale and errors → Tasks 2, 4, 5 and 8; §9 verification → every task plus Task 8; §12 governance → Task 9.
- Container checks the spec listed but this plan moves to host tests (non-owner, partial list state): ruling R2.
- Names used across tasks: `BatchUndoRunner::run/classify` with constants `UNDO`, `ALREADY_UNDONE`,
  `MODIFIED_LATER`, `NOT_IN_BATCH`; `BatchProgressReporter::UNDO_KIND`; `GovernanceBatchUndoJob::planIds`;
  `BatchJobLookup::find/recent/undoState/FINISHED/STALE_AFTER`;
  `GovernanceBatchController::PRIVILEGE_UNDO_ANY`; JS `storedJob`, `rememberedJob`, `recentRowModel`,
  `undoResultModel`, `UNDO_KIND`.
