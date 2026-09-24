# TASK-028 slice 4 — batch assignment of licence and authorship (design)

Approved by the owner in session on 2026-09-24, section by section. Builds on slice 3b
(`docs/superpowers/specs/2026-09-16-task-028-slice-3b-design.md`) and its event format,
[ADR-0020](../../decisions/0020-curation-event-typed-values.md). Field catalogue and the batch
actions it marks as essential: [ADR-0013](../../decisions/0013-vista-maestra-v2-catalogo-campos.md)
and `docs/superpowers/specs/2026-07-27-campos-ui-design.md` §6.1. Licence model:
[ADR-0019](../../decisions/0019-licencia-dcterms-license-uri.md).

## 1. Problem

Slice 3b made the five governance fields editable from the panel, one REA at a time, audited and
reversible. The tool exists; the data does not. Measured at the close of slice 3b on the development
sample: 16 of 19 REA without `dcterms:license` and 0 of 19 with any authorship field.

**Scale is the design constraint.** The container's catalogue is a development sample of about 19
REA. The production catalogue will hold on the order of **3000 REA** (owner, 2026-09-24). If the
sample's proportions hold, about 2500 REA need a licence and all of them need authorship. Filling
that one panel at a time is not a workflow. Every choice below is sized for 3000 items, not 19.

## 2. Owner decisions (2026-09-24)

| # | Question | Decision |
| --- | --- | --- |
| D1 | Scope | Batch assignment of licence and authorship only. The quality queue (counters and presets) and resource-type normalisation are later slices |
| D2 | Which set a batch acts on | The rows ticked on the current page **plus** «select all N matching the current filter» |
| D3 | Existing values | Per operation: **Fill empty only** (default) or **Replace**. Replace needs an explicit confirmation showing how many values will be overwritten. Authorship has no «append» mode |
| D4 | Undo | Per-item undo only in this slice (it already exists). Every event of a batch records a batch identifier, so that a later slice can undo a whole batch |
| D5 | Execution | Every batch, whatever its size, runs as an Omeka Job. One code path |
| D6 | Computed filters | «All matching» is refused when the active filter includes a computed predicate whose candidate set is truncated at `ComputedFilter::HARD_CAP` (2000) |

## 3. Scope

| In | Out |
| --- | --- |
| Batch assignment of `dcterms:license`, `dcterms:creator`, `dcterms:publisher`, `dcterms:rightsHolder` | `dcterms:source`: it is the URI a single REA came from, never a shared value |
| Ticked rows and «all matching the filter» | Quality queue (counters, presets) — own slice; it shares aggregation with TASK-006 |
| Fill-empty and Replace modes | Append mode for authorship |
| Preview with counts, confirmation, Job with progress, cancel, reattach | Batch undo (a later slice; this one records the batch id it needs) |
| Batch id in the event payload, ADR-0020 addendum | Resource-type normalisation (not a governance field; needs its own write path) |
| | Optimistic locking between concurrent batches (last writer wins, as everywhere else in the module) |

## 4. Fields, modes and written values

The batch writes the same values, in the same shapes, as the individual panel. It goes through
`GovernanceFields::normalise()` for validation and through `GovernanceService::withVocabularyType()`
for the CustomVocab upgrade: the core silently turns an `@value` sent to a URI-typed vocabulary into
a bare literal and drops the URI (slice 3b lesson). No second payload builder exists.

A field is written only if the curator ticked «Assign this field». An absent field means «do not
touch», which is already the contract of `apply()`.

| Mode | Item has no value in the field | Item already has a value |
| --- | --- | --- |
| Fill empty only | Written | Skipped, counted as `skipped_has_value` |
| Replace | Written | Overwritten (the whole list, for authorship) |

In Fill mode the emptiness check is evaluated **again at write time**, per item and per field, inside
the same read that `apply()` already does. A field somebody filled between preview and execution is
skipped, not overwritten. If every ticked field is skipped for an item, nothing is written and no
event is recorded, which is the existing no-op rule.

## 5. Writing and audit

`GovernanceService::apply()` gains two optional named parameters. Existing callers do not change.

```php
public function apply(
    int $itemId,
    array $raw,
    string $contributor,
    ?string $undoOf = null,
    bool $onlyEmpty = false,
    ?string $batch = null,
    bool $reread = true
): array
```

- `onlyEmpty`: drop from the normalised input every term whose current value list is not empty,
  and report which terms were dropped (`skipped: list<term>`).
- `batch`: passed to `CurationEvent::buildTyped()`, which adds it to the payload as
  `"batch": "<id>"`. The id is `batch-<jobId>`.
- `reread: false`: skip the post-write re-read that only feeds the individual panel's repaint.
  A batch never uses that response.

One event per item, one timestamp per item, as today. Per-item undo stays LIFO and unchanged:
`undoEvent()` replays one event and writes its own `undo` event, which carries no batch id.

**ADR-0020 addendum.** A v2 payload may carry an optional `batch` string. It identifies the batch
that produced the event and has no effect on replay. `CurationEvent::decode()` already accepts it,
because it only validates `v` and `terms`; a test pins that. The addendum records why the key exists
now: a batch id cannot be added retroactively to events already written.

## 6. Components

| Unit | Kind | Responsibility |
| --- | --- | --- |
| `Governance\BatchRequest` | Pure | Validates the curator's request: at least one field ticked, mode, values through `normalise()`. Returns errors keyed by field |
| `Governance\BatchPlan` | Pure | Immutable plan: ids, raw values per field, mode, owner id. Builds the preview summary from counts |
| `Governance\BatchPlanStore` | File store | Plan JSON under a random token, private directory, atomic write, 30-minute TTL, **one-shot** `take()` |
| `Governance\BatchSelection` | Service | Resolves the target set: explicit ids, or the page query rebuilt through `MasterViewQuery`; ids fetched as scalars; field counts by `property[ex]` queries |
| `Governance\GovernanceBatchRunner` | Pure loop | Iterates ids with an injected per-item applier; progress every 25 items; cancel check between items; per-item failure codes; tallies |
| `Job\GovernanceBatchJob` | Glue | Wires the runner to `GovernanceService::apply()`, the job store and `EntityManager::clear()` every 50 items |
| `IndexController` | Glue | Five actions (§7) |
| `ui/governanceBatch.js` | UI | Selection strip, sidebar form, preview, confirmation, progress, reattach |
| `core/governanceBatchModel.js` | Pure JS | Selection state, request building, preview and result view models |

The field widgets (licence `<select>`, ordered author list, publisher `<select>`, rights holder with
its default) are extracted from `ui/governance.js` into reusable functions shared by both editors, not
copied. `drawerDetails.js` does not change: the batch lives in the master view's batch bar, not in an
item's panel.

Job state reuses the `ProposalStore` pattern: JSON per job id, atomic write, TTL sweep. The stored
state carries the owner's user id, and `status`/`cancel` refuse other users.

## 7. Endpoints and data flow

All under `admin/oer-manager`. Every POST carries the CSRF token. ACL: five new privileges, granted
to exactly the roles that hold `governance-apply` (editor, site_admin, and above), through the
per-privilege `allow` of TASK-029.

| Action | Method | Does |
| --- | --- | --- |
| `governance-batch-form` | GET | Sidebar partial with the form, vocabulary entries and notices, CSRF hash, endpoint URLs |
| `governance-batch-preview` | POST | Validates, resolves the set, counts, stores the plan; returns the summary and a token. Writes nothing to the catalogue |
| `governance-batch-apply` | POST | Takes the plan by token (one-shot, same user), dispatches `GovernanceBatchJob`; returns `jobId` |
| `governance-batch-status` | POST | Job state for its owner: `in_progress` (done/total), `completed` (tallies, failures), `stopped`, `error`, or `job_died` |
| `governance-batch-cancel` | POST | Asks the dispatcher to stop the job; the runner stops between items |

**Preview input.** Either `ids[]` (ticked rows) or `scope=matching` with the page's query string.
With `scope=matching`, the server rebuilds the search with `MasterViewQuery::buildSearchParams()` and
never trusts ids from the client.

**Preview cost.** Preview does not read 3000 representations:

- ids: one search with `return_scalar=id`. **To verify in the container** that Omeka 4.2 honours it
  on `items`; the fallback is id-only pages of 100.
- per ticked field: one count query (`per_page=0`, `getTotalResults()`) of the set plus
  `property[][type]=ex` on that term. Explicit ids are constrained with the `id` search parameter.
- computed predicates active: evaluated as in the master view, over at most `HARD_CAP`
  candidates. If the base search returned more than `HARD_CAP`, the preview is refused (D6).

**Hard cap.** `BATCH_MAX = 5000` ids per plan. Above it, the preview is refused, and the curator is
asked to narrow the filter.

**Preview summary**, per ticked field: `write`, `skipped_has_value` (Fill) or `overwrite` (Replace),
plus `total`. The plan freezes the id list, so the number the curator confirms is the set the Job
processes. Values filled in the meantime are handled by the write-time check of §4.

## 8. Interface

**Selection.** When every row on the page is ticked, a strip appears above the table: «25 selected ·
Select all 2 480 matching this filter». Once chosen: «All 2 480 matching items are selected · Clear
selection». In that state the request sends the query, not ids. The batch bar gains a «Licence and
authorship…» button next to the visibility buttons.

**Form** (native sidebar, served as an authenticated partial, as the detail panel since
ADR-0017). Four fields, each behind an «Assign this field» checkbox, unticked by default. Mode radio:
«Fill empty only» (default) / «Replace». A vocabulary that does not resolve shows the same
degradation notice as the individual panel.

**Preview → confirm.** «Preview» shows the per-field counts. In Replace mode the apply button stays
disabled until «I understand that K existing values will be overwritten» is ticked. The button
states the number: «Apply to 2 480 REA». A preview with nothing to write shows why and no apply
button.

**Progress and result** (the same polling and cancel pattern as «Propose with AI»): a progress bar
with done/total and a Cancel button. Final summary: written · skipped (had a value) · unchanged ·
failed, with failed ids linked to their items and the batch id shown. The table is not repainted row
by row, because most affected items are not on the page; a «Reload view» button does it.

**Reattach.** The job id is kept in `localStorage` (per-browser convenience, as for the AI propose).
Reloading or reopening the master view finds the running job and resumes showing its progress.

All strings go through `Omeka.jsTranslate()` / `$translate` and are extracted by
`make generate-pot` (NFR-005).

## 9. Errors, concurrency and scale

| Situation | Response |
| --- | --- |
| Empty selection, no field ticked, invalid value | Preview error with its code, per field where it applies |
| Set above `BATCH_MAX`, or computed filter truncated | Preview refused, with the reason |
| Token expired, already used, or another user's | `plan_expired`; the curator previews again |
| Item not editable by the job's owner | Per-item failure `denied` |
| Item deleted between preview and execution | Per-item failure `not_found` |
| Domain exception | Per-item failure with its code |
| Anything else | Per-item failure `unexpected`; detail in the log, never in the response |
| Job process died (OOM, kill) | `status` returns `job_died` (finished job, state still `in_progress`) |

A per-item failure never aborts the batch. Cancel stops between items: what was written stays
written and is reported.

**Concurrency.** Two batches, or a batch and an individual edit, may overlap. The last writer wins:
the same unguarded policy as recataloguing and the individual panel, declared here, not solved. Fill
mode never overwrites a value another writer set in the meantime, because of the write-time check.

**Scale.**

- `EntityManager::clear()` every 50 items, so Doctrine's identity map does not grow across thousands
  of writes.
- Progress written every 25 items, not per item.
- Plans and job states swept by TTL.
- Per-item time is **measured** in the container harness (§10) and extrapolated to 3000. No figure is
  assumed here.

## 10. Verification

**Host, TDD.** Keep the glue thin so the patch reaches the 90% `codecov/patch` target (slice 3b's
PR #46 lesson):

- `BatchRequest`, `BatchPlan`, `BatchPlanStore` (one-shot, TTL, owner check), `GovernanceBatchRunner`
  (progress cadence, cancel, failure codes, tallies).
- `GovernanceService::apply()` with `onlyEmpty`, `batch` and `reread`, in `GovernanceServiceTest`.
- `CurationEvent`: `batch` round-trips through `encode`/`decode`, and `scopeOf()` ignores it.
- `BatchSelection` against the mocked API: explicit ids, rebuilt query, counts, `BATCH_MAX`, truncated
  computed filter.
- The five controller actions: GET/CSRF guards, ACL, sanitised errors, one-shot token, owner check
  on status and cancel.
- JS (`node --test`): `governanceBatchModel.js`.

**Container.** `test/container/governance-batch-check.php` works on **disposable fixtures only**:
it creates about 30 private REA of its own (the `governance-check.php --write` pattern) and deletes
them at the end. It never touches a catalogue item. It checks:

1. `return_scalar=id` support on `items` (decides between the scalar and the fallback path), and
   that the `id` search parameter accepts a list (explicit-ids counts depend on it).
2. Fill mode: exact counts, items with a value untouched.
3. Replace mode: exact counts, values overwritten.
4. Every written item has one event carrying the batch id; per-item undo restores it.
5. Unrelated properties (title, description, alignment) are identical after the batch.
6. Cancel mid-run: the job stops, and the partial tally matches what was written.
7. Throughput and peak memory per item, extrapolated to 3000 and recorded in the backlog.

**Not verifiable here, declared:** the form and progress in a real browser session with a logged-in
curator. That is TASK-030 debt, like the rest of the module's JavaScript.

## 11. Accepted risks

- Last writer wins between concurrent writers (§9).
- The frozen id list can include items whose state changed since preview. Fill mode re-checks per
  item. Replace mode overwrites whatever is there, which is what the curator confirmed.
- Until batch undo exists, correcting a wrong Replace batch means running another Replace batch with
  the right value. The confirmation step exists because of that.
- The preview relies on `return_scalar`, or a paginated fallback, to fetch up to 5000 ids
  synchronously. The harness measures it.

## 12. Acceptance criteria

1. A curator selects rows or «all matching the filter», and assigns licence, authorship, publisher
   and rights holder in one operation, choosing Fill or Replace.
2. The preview states, per field, how many items will be written, skipped or overwritten, before
   anything is written. Replace cannot run without the explicit overwrite confirmation.
3. The batch runs as a Job with progress, cancel and reattach; per-item failures are reported and do
   not abort it.
4. Every written item carries its own audited, per-item-undoable event with the batch id.
5. Fill mode never overwrites a value, including one set after the preview.
6. Values reach the catalogue in exactly the shapes the individual panel writes; a licence matched to
   a URI-typed CustomVocab keeps its URI.
7. The container harness passes on disposable fixtures, and the throughput extrapolated to 3000 REA is
   recorded.

## 13. Governance at close

Backlog row of TASK-028 (slice 4 done, slice 5 = quality queue and resource-type normalisation, plus
batch undo), traceability for RF-015, the ADR-0020 addendum, and `project-memory.md`, including the
production catalogue scale of about 3000 REA as a standing design constraint.
