# TASK-011: batch AI proposal, stored in the REA until a curator reviews it

Approved by the owner on 2026-11-25 (brainstorming session). Milestone 3 of the
[1.0 plan](2026-11-17-v1-production-plan.md). Requirements: RF-011, RF-009, NFR-002, NFR-004,
NFR-010, NFR-011. Decisions: ADR-0007, ADR-0008, ADR-0015, ADR-0023 §3. Builds on TASK-020 (the
single proposal as a Job) and TASK-070 (the queue as a mode of the master view).

## Why

The initial import (TASK-072) brings about 3 000 REA, private and in Propuesto. ADR-0023 §3: curators
launch the batch AI proposal on part of the queue; it runs as a Job; each curator then works the
queue, finds the AI proposal pre-filled in the panel, adjusts it and confirms and publishes, or
rejects or discards. There is no batch publication.

Today a proposal lives only in a temporary file (`ProposalStore`, `sys_get_temp_dir()`) read by the
browser that launched it and swept after a day. A batch proposal must wait days or weeks for a
curator, survive restarts and backups, and be filterable in the queue. TASK-070 left the «AI
proposal ready» filter and its row badge to this task.

**Order in the plan.** TASK-068 (a), the AI baseline on `cur:`, goes before this task so the batch
runs with a known accuracy. The code and its tests need no paid LLM calls (a fake provider stands in);
only the real batch run waits for TASK-068 (a) and the owner's budget.

## Owner decisions (2026-11-25)

| Question | Decision |
| --- | --- |
| Where pending proposals live (NFR-002, no own tables) | **A private value in the REA itself**, like the curation event of ADR-0015: it is in the database, travels in backups and exports, and the queue filter is an exact native search. Rejected: durable private files (outside database backups, a directory the deployment must mount, one server only); the Job record (the panel would have to find the last batch holding each REA, and one batch's JSON reaches about 30 MB); Omeka settings (Omeka loads every setting on each request). |
| Which REA a batch processes | **Only REA in the queue that have no pending proposal.** From the ticked rows or everything matching the filter, as the governance batch; a preview shows how many enter, how many are skipped and why, and the estimated cost, before launching. |
| How the panel shows a stored proposal | **Pre-filled on opening**, as pending chips through the same path as «Proponer con IA», with a notice «Propuesta IA del <fecha>» and «Descartar propuesta». |

## Behaviour

### Launching a batch

- In the master view, with ticked rows or «todos los que cumplen el filtro», a curator chooses
  **«Proponer con IA (lote)»**. A sidebar opens (the governance batch pattern, `#oer-batch-sidebar`).
- **Preview.** The sidebar shows the selection total and splits it into:
  - **entran**: REA in the queue (status filter `queue`: Propuesto, or public without Publicado;
    Descartado excluded) without a pending proposal;
  - **se saltan, fuera de la cola**;
  - **se saltan, ya tienen propuesta**;
  - **coste estimado**: entran × 0,0052 $ (the cost per proposal measured in TASK-062), labelled as
    an estimate.
- **«Lanzar»** dispatches the Job with the ids that enter. Zero entering disables the button.
- **One batch at a time.** If an AI batch Job is starting or in progress, the preview says so and
  launching is refused (`error: running`), so the same REA are never paid for twice.
- While it runs the sidebar shows progress (processed / total; with proposal, skipped, failed) and
  «Cancelar»; after it ends, the summary with links to the failed REA. Reopening the master view
  re-attaches to the running batch (the governance batch's `localStorage` pattern).
- Only `rea_curador` and `global_admin` reach these actions (ADR-0023 §1).

### The Job

For each id, in order:

1. Stop if the Job was asked to stop.
2. Re-read the REA and **check again** that it is in the queue, has no pending proposal, and that the
   Job's owner may update it (the native ACL on the entity, `update`). Otherwise count it as skipped:
   the queue may have moved since the preview.
3. Run `ProposeRunner` (the single proposal's pipeline: distiller, media, curricular cascade, axes).
4. Write the proposal value **directly on the entity** and flush, as the TASK-076 resync does. The
   API is not used: imported REA usually fail the template's required fields (ADR-0023,
   consequences), and a refused API update would lose the proposal. `o:modified` does not move.
5. Clear the entity manager every 50 REA so memory stays flat over 3 000.

Errors:

- An LLM error or an unexpected error on one REA is logged (item id and message, never the key),
  counted as failed, and the batch goes on.
- **Five LLM errors in a row abort the batch** (status `error`, reason `llm_repeated`): a bad key,
  quota or provider outage would otherwise fail, and possibly bill, every remaining REA.
- A REA whose proposal is empty (no dimension fits, ADR-0010 §5) still gets a proposal value with no
  chips, so the curator sees that the AI found nothing instead of it being proposed again.

The Job never writes alignment, axes, status or visibility (ADR-0007): it only stores a proposal.

### The stored proposal

- **Property:** `curation:data` («Any data that can be used for any purpose»). The `curation`
  vocabulary is installed in production and the workflow already uses `curation:status` and
  `curation:note`.
- **At most one** proposal value per REA, **private** (`is_public = false`), a literal whose text is
  the stable, untranslatable marker `OERManager/ai-proposal/1`, a space, and a JSON object:

  ```json
  {"v":1,"created":"2026-11-25T10:31:07+00:00","model":"<configured model>",
   "item_modified":"2026-11-20T08:00:00+00:00",
   "alignment":{"lrmi:teaches":[123,456],"lrmi:assesses":[789],"dcterms:relation":[42]},
   "justifications":{"lrmi:teaches":{"123":"…"}}}
  ```

  `alignment` holds term ids per property as `AiCataloguer::propose()` returns them;
  `justifications` as `ProposeRunner` returns them; `item_modified` is the REA's `o:modified` when
  proposed. No `debug` (tens of KB of prompts) and no titles: titles are resolved when the panel
  opens, so a renamed term shows its current title.
- Only `curation:data` values that start with the marker are read, replaced or removed; any other use
  of the property is left alone.
- A value that starts with the marker but does not decode, or has another `v`, is treated as no
  proposal and logged once per read; it is still removed by the removal events below.

### The panel

- When the panel opens on a REA with a proposal, the proposal enters the alignment editor as pending
  chips through the same function as «Proponer con IA» (`proposeIntoEditor`), with its
  justifications. Term ids that no longer exist are dropped silently, as `enrichLabels` does today.
- A notice above the editor: **«Propuesta IA del 25/11/2026»**, and **«Descartar propuesta»**.
  If the REA's current `o:modified` is later than `item_modified`, the notice adds **«El REA ha
  cambiado desde la propuesta.»**
- «Proponer con IA» on that REA still works and replaces the pending chips in the editor; it does not
  touch the stored value (the single proposal stays ephemeral).

### When the proposal is removed

| Event | Removed |
| --- | --- |
| The re-cataloguer's apply on that REA (any confirmed change) | yes |
| «Confirmar y publicar» | yes |
| «Descartar» (the REA) | yes |
| «Descartar propuesta» in the panel | yes |
| «Rechazar» | no: if the REA comes back to the queue the proposal still serves, with the «ha cambiado» notice |
| Undoing a re-cataloguing | no, it does not come back |

Removal happens in the same request as the action, after it succeeds, by a direct entity write of the
marker values only.

### The queue

- New filter **«Propuesta IA»: «lista» / «sin propuesta»** in the quick bar, the advanced search and
  the chips. «lista» is a native property search: `curation:data` starts with (`sw`) the marker;
  «sin propuesta» is its negation (`nsw`). Both are exact over the whole catalogue (NFR-004).
- A row badge **«IA»** on REA with a proposal (the badge TASK-070 left to this task).

## Out of scope

- Batch publication (ADR-0023 §3).
- Storing the single «Proponer con IA» result.
- A per-call configurable LLM timeout (deferred in TASK-020).
- Scheduling batches, or several batches in parallel.
- Choosing a model per batch: the batch uses the configured connection (ADR-0008).

## Design

### Components

| Unit | Responsibility |
| --- | --- |
| `Service\Ai\StoredProposal` (final, pure) | encode `{created, model, item_modified, alignment, justifications}` to the marker text; decode the text back or return `null`; `isProposal(string)` by marker. |
| `Service\Ai\StoredProposalWriter` | on an `Item` entity: `read()`, `replace()`, `remove()` the marker values of `curation:data`, as new private literal `Value`s. Built like `DerivedSubjectWriter` (property and value factories injected). |
| `Service\Ai\BatchProposeEligibility` | turns a selection into `{enter, skipped_out_of_queue, skipped_has_proposal}` with id-only searches (`MasterViewQuery` status `queue` plus the new proposal filter, `return_scalar`). Used by the preview and, per id, by the Job. |
| `Job\AiBatchProposeJob` | the loop above. A static `run(...)` with injected callables (ids, eligibility, propose, write, shouldStop, progress), as `ResyncDerivedSubjects::run`, so the loop is host-tested without Omeka. |
| `Controller\Admin\AiBatchController` | `form`, `preview`, `launch`, `status`, `cancel`; POST and CSRF guarded as `GovernanceBatchController`; selection through `BatchSelection`; Job ownership through `BatchJobLookup`. Progress through `ProposalStore` keyed by Job id (as the governance batch). |
| `ReaAcl` | the five actions granted to `rea_curador` (and `global_admin`). |
| `MasterViewQuery` | the `ai_proposal` filter (`ready` / `none`) and its chip. |
| `ItemPanelData` | adds `aiProposal: {created, changed, alignment (with titles), justifications}` or `null`. |
| Removal hooks | `RecatalogService::apply`, `WorkflowService::publish` and `::discard`, and a new `ai-proposal-discard` action, call `StoredProposalWriter::remove`. |
| JS `ui/aiBatch.js`, `core/aiBatchState.js` | the sidebar (preview, launch, progress, cancel, re-attach); pure state decisions in `core/` for `node --test`. |
| JS `ui/aiPropose.js` | on panel open, pre-fill from `aiProposal` and render the notice. |

### Cost (NFR-004)

- Preview: three id-only searches over the selection (at most 5 000 ids). No REA is read whole.
- Queue filter: one native property clause; no PHP-side scan.
- Job: one REA read and one small flush per REA, plus the LLM calls; the LLM dominates (about 18 s
  and $0.0052 per REA measured in TASK-020 and TASK-062, so 3 000 REA take roughly 15 hours and $16
  in one batch). Curators are expected to launch it on parts of the queue (one load or item set).

### Errors and edge cases

- **The native edit form.** An administrator sees the private `curation:data` value in the native
  form. Saving the form keeps it. Editing its text so it no longer decodes makes it «no proposal».
- **A REA edited in the native form while the Job writes.** If the form was loaded before the Job
  wrote, saving it replaces all values and drops the proposal; the REA becomes eligible again. This
  is accepted and documented.
- **The Job's owner loses the curator role mid-batch.** The per-id ACL check skips the remaining
  REA.
- **A deleted REA.** Skipped as not found.
- **Template validation.** Not involved: the Job and the removal write the entity directly, and only
  the marker values.
- **Isolation.** Does not apply inside Jobs (ADR-0023 §3); the per-id ACL check does.

### Governance

- **ADR-0007 addendum 1:** a batch Job stores a proposal in the REA (`curation:data`, private,
  marker `OERManager/ai-proposal/1`) and never applies it; the curator still confirms every change.
- Backlog TASK-011 closed with the summary; traceability row; project memory; TASK-071 runbook: no
  step needed (no schema or setting change), but a line noting that batches use the configured LLM.

## Testing

- Host, TDD:
  - `StoredProposal`: round trip, marker check, unknown `v`, malformed JSON, non-marker text.
  - `StoredProposalWriter`: replace keeps other `curation:data` values; remove only the marker
    values; private flag set; read of a corrupt value returns null.
  - `BatchProposeEligibility`: the three buckets, empty selection, all skipped.
  - `AiBatchProposeJob::run`: counting, per-REA failure goes on, five LLM errors in a row abort
    and a success resets the streak, stop, re-check at run time skips a REA that left the queue,
    clear every 50.
  - Controller: method and CSRF guards, preview figures, launch refused while a batch runs, status and
    cancel only for the owner, `ai-proposal-discard`.
  - `ReaAclTest` matrix and the every-action-decided test.
  - `MasterViewQuery`: `ai_proposal=ready|none` produce the `sw` / `nsw` clauses.
  - `ItemPanelData`: `aiProposal` present, absent, and `changed` when `o:modified` is later.
  - Removal on apply, publish and discard; kept on reject.
  - JS: the batch state machine and the panel notice and pre-fill.
- Container: `test/container/batch-ai-check.php --write` on the development catalogue with a fake
  LLM provider (no cost): preview buckets, a batch over a few REA including a template-invalid one,
  the stored value is private and `o:modified` unchanged, the queue filter counts match, removal on
  apply and publish, one batch at a time, cancel. Run as a real `rea_curador` (ADR-0023,
  consequences).
- The real batch with the production model waits for TASK-068 (a) and the owner's budget.
