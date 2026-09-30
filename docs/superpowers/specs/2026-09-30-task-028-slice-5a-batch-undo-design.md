# TASK-028 slice 5a — undo a whole governance batch (design)

Approved by the owner in session on 2026-09-30, section by section. Builds on slice 4
(`docs/superpowers/specs/2026-09-24-task-028-slice-4-batch-governance-design.md`), which records
`batch-<jobId>` in every event a batch writes precisely so that this slice can find them, and on the
typed event format of [ADR-0020](../../decisions/0020-curation-event-typed-values.md) and its
slice 4 addendum.

## 1. Problem

Slice 4 lets a curator write licence and authorship to up to 5000 REA in one Job. Today a batch can
only be reversed one REA at a time, through the per-item undo. On the production catalogue of about
**3000 REA** (owner, 2026-09-24) a mistaken batch — wrong licence, Replace instead of Fill — costs
thousands of clicks. Now that batch assignment is on `main`, this is the largest open risk of the
feature, and the smallest of the three pieces left in slice 5.

**Slice 5 is split** (owner, 2026-09-30): 5a batch undo (this spec), 5b quality queue, 5c
resource-type normalisation. Each gets its own spec and plan.

## 2. Owner decisions (2026-09-30)

| # | Question | Decision |
| --- | --- | --- |
| D1 | Order of slice 5 pieces | Batch undo first, then quality queue, then resource-type normalisation |
| D2 | An REA touched again after the batch | **Skip and report.** An REA is undone only if the batch event is still its last event and its values still match what the batch wrote — the same rule as per-item undo. Later work is never overwritten |
| D3 | Who may undo a batch | Its owner, and a site_admin (for any batch) |
| D4 | Where a batch is undone from | A «Lotes recientes» list in the batch sidebar, plus a button on the finished batch result |
| D5 | Preview | No synchronous preview. A confirmation with the batch's known facts; the Job checks every REA and reports what it did |
| D6 | Approach | A dedicated undo Job inside the existing batch controller (approach 1 of 3) |
| D7 | Minor rulings accepted with the approach | Undo events carry `batch: batch-<undoJobId>`; a running batch cannot be undone; a stopped or failed batch can (what it wrote); one undo per batch at a time; a stopped undo can be relaunched; the list shows the 20 most recent finished batches |

## 3. Scope

| In | Out |
| --- | --- |
| Undo a finished `GovernanceBatchJob` as a Job with progress, cancel and reattach | Redo a whole batch |
| «Lotes recientes» list and «Deshacer este lote» on the batch result | Undo of other batch kinds (none exist) |
| Per-REA classification and a result with links to REA needing manual review | Optimistic locking (debt already recorded in slice 4) |
| `batch` tag on batch-undo events, ADR-0020 addendum | Quality queue (5b), resource-type normalisation (5c) |
| Relaunching a stopped or failed undo | Undo of batches older than the Job table retains (Omeka keeps Jobs; no limit is imposed) |

## 4. Components

| Unit | Responsibility |
| --- | --- |
| `Service\Governance\BatchJobLookup::find()` | Also returns the Job's `args` (array) |
| `Service\Governance\BatchJobLookup::recent(?int $ownerId, int $limit = 20)` | Doctrine read of finished `GovernanceBatchJob` entities (`completed`, `stopped`, `error`), newest first, filtered by owner unless `null` (site_admin). Per batch: id, owner id and name, started/ended, the governance terms and mode from its args, the plan size (`count(ids)`), native status, and the undo state from the most recent `GovernanceBatchUndoJob` for that batch (`none`, `running`, `done`, `partial`). Undo jobs are read in one bounded query (the latest 200) and matched in PHP on `args.batchJobId` |
| `RecatalogService::events(int $itemId)` | Public wrapper over the existing private `eventsOf()`: raw events newest first, with the microsecond tie-break TASK-007 fixed. `history()` stays as it is |
| `GovernanceService::undoEvent(..., ?string $batch = null)` | Optional tag written into the undo event's payload as `batch`. Without it, behaviour is unchanged |
| `Service\Governance\BatchUndoRunner` (pure) | Iterates the plan ids, classifies each REA (§5), calls `undoEvent` only for `undone` candidates, reports progress every 25 REA and stops between REA when asked. Unit-testable without Omeka, like `GovernanceBatchRunner` |
| `Service\Governance\BatchUndoProgressReporter` | Same contract as `BatchProgressReporter`, with `kind: 'governance-batch-undo'`, written to the same `ProposalStore` directory |
| `Job\GovernanceBatchUndoJob` | Args `{batchJobId, contributor}`. Re-checks that `batchJobId` is a `GovernanceBatchJob`, reads its `ids` from the original Job's args, runs the runner, finishes with tallies and the list of REA needing review |
| `Controller\Admin\GovernanceBatchController` | New actions `recent` and `undo`; `status` and `cancel` also accept `GovernanceBatchUndoJob` (with state kind `governance-batch-undo`), still only for that Job's owner |
| ACL (`Module.php`) | Actions `recent` and `undo` for editor, site_admin and reviewer. New privilege `undo-any-batch` on the controller resource for site_admin only; global_admin already has every privilege |

## 5. Per-REA rule

B = `batch-<jobId>` of the original batch; U = `batch-<undoJobId>` of this undo. For each id in the
original plan, in order:

1. Read the REA's events (`RecatalogService::events`). A missing REA is `not_found`; a permission
   error is `denied`.
2. If the last event is a governance event whose payload has `batch == B`: call
   `GovernanceService::undoEvent(id, event, contributor, force: false, batch: U)`.
   - `error: stale` (values differ from what the batch wrote) → `modified_later`.
   - Success → `undone`.
3. Else, if the last event is an `op: undo` whose `undoOf` equals the second-to-last event's `when`,
   and that second-to-last event has `batch == B` → `already_undone`.
4. Else, if any event of the REA has `batch == B` → `modified_later`.
5. Else → `not_in_batch` (Fill-empty skipped it, or its values were already equal).
6. Any other exception → `unexpected`, logged without values; the Job carries on.

`undoEvent` is called directly rather than through `UndoRouter`: the router re-reads the last event
and cannot pass the tag, and a governance batch only contains governance events.

## 6. Endpoints and data flow

All actions are POST with CSRF (`oer_governance_batch`) and return JSON.

**`recent`** → `{batches: [...]}`. Owner-filtered unless the user has `undo-any-batch`. Each entry:
`{jobId, batch, owner, started, ended, terms, mode, planned, status, undo: {state, jobId|null}}`.

**`undo`** `{batchJobId}` → `{jobId}` or `{error}`:

| Code | When |
| --- | --- |
| `method` / `csrf` / `id` | Not POST, bad token, id ≤ 0 |
| `not_found` | No such Job, or the user is neither its owner nor has `undo-any-batch` (same answer, so foreign batches are not revealed) |
| `not_batch` | The Job is not a `GovernanceBatchJob` |
| `running` | The batch is not finished |
| `undo_running` | An undo of this batch is in progress |
| `dispatch` | Dispatch failed; logged without internals |

**`status` / `cancel`**: unchanged contract; the undo state adds `tallies` with the six categories
and `review: [{id, code}]` for `modified_later`, `denied`, `not_found` and `unexpected`. A finished
undo with unreadable batch args finishes as `error` with code `plan_unreadable` and writes nothing.

Flow: open the sidebar section → `recent` → «Deshacer lote» → inline confirmation → `undo` →
poll `status` (reusing slice 4's poller, progress, cancel and reattach) → result.

## 7. Interface

- **«Lotes recientes»** section in `#oer-batch-sidebar`, below the form, collapsed by default and
  loaded once on first expand. Row: `batch-123 · 29/09/2026 17:42` (plus owner name when a
  site_admin sees another user's batch); `Licencia, Autoría · Sustituir · 2950 REA en el plan ·
  <native status>`; then «Deshacer lote», or «Deshecho», «Deshaciendo…», or «Deshecho parcialmente»
  with «Reintentar deshacer». Empty: «No hay lotes que puedas deshacer».
- **Finished batch result** gains «Deshacer este lote».
- **Inline confirmation** (not a browser dialog): «Deshacer el lote batch-123 (Licencia, Autoría ·
  Sustituir · 2950 REA en el plan). Se restaurarán los valores anteriores al lote. Los REA
  modificados después del lote no se tocarán y aparecerán en el resultado.» [Deshacer lote] [Cancelar].
- **Progress**: the slice 4 block, «N de M REA» and «Cancelar».
- **Result**: «2870 deshechos · 60 modificados después del lote · 15 no escritos por el lote · 5 ya
  deshechos · 0 fallidos», then linked REA for modified-later and failed, then «Recargar la vista».
  `not_in_batch` is not listed.
- **Reattach**: the slice 4 `localStorage` key stores `{jobId, kind}`; one tracked Job per tab.
  A stored bare job id (slice 4 format) is read as a batch Job.
- Spanish source strings through `jsTranslate`; every button names its action and object.

Decisions stay in `core/governanceBatchModel.js` (pure, `node --test`): list-row model (state text,
which button) and undo-result model (figures, which REA are listed).

## 8. Errors, concurrency and scale

- **Relaunch is idempotent**: REA already reverted classify as `already_undone`; nothing is written.
- **Tampered args**: ids are never taken from the browser; they are read from the original Job,
  cast to positive integers, and the original Job's class is re-checked inside the undo Job.
- **Per-REA authorisation**: writes go through the API as the undoing user. A site_admin undoing
  another user's batch is still subject to each item's native ACL; a denial is `denied`, not a stop.
- **Concurrency with the batch**: excluded, the batch must be finished.
- **Concurrency with edits**: `undoEvent`'s stale check reads and compares right before writing. A
  millisecond window remains without optimistic locking (slice 4 debt).
- **Scale**: one events read per REA, one write per undone REA. Expected in the order of slice 4
  (≈10 ms, ≈12 KB per REA): 30–60 s and ≈35 MB for 3000 REA, no `EntityManager::clear()` (slice 4
  lesson: it detaches the authenticated owner). `recent()` is two bounded queries, never a
  catalogue scan. The harness measures it.

## 9. Verification

**Host (TDD):** `BatchUndoRunnerTest` (every category, progress every 25, stop between REA, one
failing REA does not stop the Job); `BatchJobLookupTest` (`recent` filters by class, finished status,
owner or all, order and limit; undo state from the cross; `find` returns args);
`GovernanceBatchUndoJobTest` (reads ids from the original Job, `plan_unreadable` writes nothing,
refuses a non-batch `batchJobId`); `GovernanceBatchControllerTest` (every `undo` refusal, owner vs
non-owner with and without `undo-any-batch`, foreign batch is `not_found`, `status`/`cancel` accept
the undo class for its owner only, `recent` scoping); `GovernanceServiceTest` (`undoEvent` with and
without the tag); `RecatalogService` `events()` order; JS model tests for list rows and undo result.

**Container** (`test/container/governance-batch-undo-check.php`, disposable fixtures only, same
pattern as `governance-batch-check.php`): 200 fixtures, a Replace batch, then 3 REA modified by hand
and 2 undone individually; the batch undo reports exact figures per category, restored values equal
the pre-batch ones, title and description untouched, every undo event tagged `U`; a relaunch writes
nothing; a cancelled undo shows as partial; a non-owner without the privilege gets `not_found`; time
and memory per REA measured and extrapolated to 3000; all fixtures deleted.

**Not verified here, declared:** the list, confirmation and result in a real browser (TASK-030).

## 10. Accepted risks

- Stale-check window without optimistic locking (inherited).
- `recent()` matches undo Jobs among the latest 200 only; a batch whose undo is older than that shows
  as not undone, and relaunching it is harmless (`already_undone`).
- A batch started before slice 4 shipped has no `batch` tags; there are none in production.

## 11. Acceptance criteria

1. A curator sees their 20 most recent finished batches; a site_admin sees everyone's.
2. Undoing a batch restores pre-batch values on every REA whose last event is still the batch's and
   whose values are unchanged, and touches no other REA.
3. The result counts `undone`, `modified_later`, `not_in_batch`, `already_undone` and failures, and
   links the REA needing manual review.
4. Relaunching an undo writes nothing new; a stopped undo can be relaunched to completion.
5. A non-owner without `undo-any-batch` cannot see, undo, poll or cancel another user's batch.
6. Every batch-undo event carries `batch-<undoJobId>`; per-item undo events carry no tag.
7. `make lint`, `make test`, `make test-js` pass; the container harness passes on disposable fixtures.

## 12. Governance at close

ADR-0020 addendum (batch-undo tag); backlog TASK-028 row (5a done, slice 5 split into 5a/5b/5c);
traceability; project memory.
