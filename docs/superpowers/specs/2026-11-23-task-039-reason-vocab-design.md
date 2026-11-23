# TASK-039: reasons to reject and to discard come from a CustomVocab

Approved by the owner on 2026-11-23 (brainstorming session). Milestone 1 of the
[1.0 plan](2026-11-17-v1-production-plan.md). Requirements: RF-016, NFR-005. Decisions: ADR-0018,
ADR-0013, ADR-0023.

## Why

A curator who rejects or discards a REA writes the reason as free text (`curation:note`, a literal of
at most 200 characters). Free text cannot be counted or filtered, and every curator words the same
cause differently. The owner asked on 2026-09-07 for a controlled list. ADR-0023 (2026-11-17) added
the state Descartado, whose reasons are handled the same way.

## Owner decisions (2026-11-23)

| Question | Decision |
| --- | --- |
| (c) Content of the lists | The lists below, as proposed. |
| (c) Who creates the vocabularies | The administrator, by hand, in CustomVocab. The module only reads them by id (ADR-0013, consume variant). |
| (a) No vocabulary configured, missing, or CustomVocab inactive | Fall back to today's free text, with a hint. The flow is never blocked. |
| (b) A free note besides the reason | Yes, optional, at most 200 characters. |
| «Despublicar» | Stays free text. Out of scope. |

Decided in the design, without a separate question:

- **(d) Migration:** none. Production has no REA yet. An old free-text reason keeps showing as it is.
- **(e) i18n:** vocabulary terms are instance data and do not go through the module's `.po`. This is a
  stated limit against NFR-005, the same as for every other CustomVocab the module reads.

### The lists

These are seeded by hand during deployment (TASK-071). The module never depends on their wording.

**Reasons to reject** (the REA goes back to its proposer to be fixed):

- Faltan metadatos obligatorios
- Anclaje curricular incorrecto o incompleto
- Licencia ausente o incompatible
- Errores de contenido (didácticos, de redacción o técnicos)
- Enlaces o archivos que no funcionan
- Accesibilidad insuficiente
- Otro motivo

**Reasons to discard** (the REA will not be published):

- Duplica un REA existente
- No es un recurso educativo
- Derechos incompatibles con la publicación abierta
- Contenido obsoleto (currículo derogado)
- Calidad insuficiente para el catálogo
- Otro motivo

## Approach

Reuse `Service\Governance\VocabEntries`, which already serves the licence and publisher fields. It
identifies a vocabulary by an id in a setting, injects the reader, and reports «unresolved» (null)
apart from «empty».

There are **two vocabularies and two settings**. A single vocabulary holding both families was
rejected: the module would have to tell the families apart by label, and it never identifies anything
by label.

## Design

### 1. Data and writes (`WorkflowService`)

- `curation:note` holds **up to two values**:
  1. the reason, typed `customvocab:N` and written as the term itself (`@value`, a term-typed
     vocabulary);
  2. the optional detail, a `literal` of at most 200 characters.
- `reject()` and `discard()` take `(reason, detail)`. `unpublish()` is unchanged: one free literal.
- Propose, reopen and publish clear **every** value of `curation:note`, as they do today.
- Writes keep going through `transition()`, which avoids the `isPartial` trap described in that
  method's comment.
- When the vocabulary does not resolve, the reason is written as a literal, as today, and the detail
  is ignored: the field is free text already.

`curation:status` stays a literal. ADR-0018 §2 still holds for it, because the state machine compares
the status by exact equality. The note is never compared, so that argument does not apply to it.

### 2. Configuration and validation

- **Settings.** `GovernanceSettings::REJECT_REASON_VOCAB_ID` (`oermanager_reject_reason_vocab_id`) and
  `GovernanceSettings::DISCARD_REASON_VOCAB_ID` (`oermanager_discard_reason_vocab_id`).
  - Each has a `Number` field in `ConfigForm` and is handled in `ConfigPayload`, next to the licence
    and resource type fields.
  - Their help text says that the module does not create the vocabularies, and that an empty field
    means free text.
- **Services.** `VocabEntries\RejectReason` and `VocabEntries\DiscardReason` are registered in
  `module.config.php`, built like `VocabEntries\Licence`.
- **Controller.** `IndexController::workflowGuard()` reads `reason` and `detail`.
  - When the action's vocabulary resolves, the reason must equal one of its current terms. Otherwise
    the guard answers `reason_invalid` («Elige un motivo de la lista.»). A crafted POST cannot store
    free text as the reason.
  - When the vocabulary does not resolve, the guard behaves as today: the reason is required and cut
    to 200 characters.
  - The detail is optional, trimmed and cut to 200 characters.
- Only `reject` and `discard` use the vocabularies. `unpublish` keeps today's guard.
- **Refinement during planning (2026-11-23).** One service, `Service\Workflow\ReasonVocabularies`, wraps
  the two `VocabEntries` instead of registering two services. It keeps the controller to one new
  dependency. Its reader is `Service\Governance\CustomVocabTerms`, which yields the terms of a term-typed
  vocabulary only, so a URI or item-set vocabulary counts as not configured. The item page's reason form
  already opens in a panel under the 48px bar, so the select and the detail field do not have to fit in
  the bar.

### 3. Interface

There are two entry points: the native item page (`view/oer-manager/common/workflow-actions.phtml`)
and the detail panel (`view/oer-manager/admin/index/drawer-details.phtml`, driven by
`asset/js/ui/workflowDrawer.js`).

- **With a vocabulary:**
  - a required `<select name="reason">`, whose first option «Elige un motivo…» has an empty value;
  - under it, «Concreta (opcional)»: `<input type="text" name="detail" maxlength="200">`.
- **Without a vocabulary:** today's text field, with the hint «Lista de motivos no configurada: escribe
  el motivo.».
- `workflowDrawer.js` already focuses `[name="reason"]` when a form opens, and that keeps working with a
  `<select>`.
- **«Mis propuestas»** shows «reason — detail», reading every value of `curation:note` instead of the
  first. A legacy free-text reason shows as it is.
- **Not in scope:** the detail panel does not show the note today, and this task does not add it.
- **Risk to check in a browser:** the core renders `#page-actions` with a fixed height of 48px. A select
  plus a field may not fit in one row on the item page. This is checked in the container. If they do
  not fit, the form wraps below the bar, with no change to the core.

### 4. Errors and degradation

| Situation | Result |
| --- | --- |
| The setting is empty, the vocabulary was deleted, or CustomVocab is inactive | Free text, with the hint. |
| The vocabulary resolves but has no terms | Treated as unresolved: free text, so the flow never shows an empty select. |
| A POST sends a reason that is not a term | `reason_invalid`, nothing written. |
| The vocabulary is renamed or its terms are edited | Existing notes keep the text they were written with. New ones validate against the current terms. |

### 5. Governance

- **Addendum to ADR-0018 §2:** the note is a CustomVocab term plus an optional literal. The status
  stays a literal, and the addendum says why.
- **NFR-005:** the limit on translating instance data is stated in the traceability.
- **TASK-071** (deployment runbook) gains the step: create both vocabularies with the lists above and
  set their ids.
- When the task closes, the backlog, traceability and project memory are updated together.

## Testing

- **PHPUnit:**
  - `WorkflowService`: reject and discard write the term with type `customvocab:N` plus the detail; they
    fall back to a literal without a vocabulary; propose and publish clear both values; unpublish is
    unchanged.
  - `IndexController`: `reason_invalid` for a reason that is not a term, the fallback without a
    vocabulary, the detail cut to 200 characters.
  - `ConfigForm` and `ConfigPayload`: the two new settings.
- **JS tests:** only if `workflowDrawer.js` changes behaviour.
- **Container check:** throwaway vocabularies and a throwaway REA on the 8080 development catalogue,
  removed at the end, with a before/after count. Covered: reject and discard as a non-administrator
  `rea_curador`, the fallback without a vocabulary, and the 48px bar in a browser.
- `make lint`, `make test` and `make test-js` before finishing.

## Out of scope

- Reasons to unpublish.
- Showing the note in the detail panel.
- The module seeding the vocabularies.
- Reporting or filtering by reason. The curation queue (TASK-070) may use it later.
