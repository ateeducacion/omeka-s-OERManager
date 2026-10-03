# TASK-044 — «Propuesto»/«Rechazado» badge and always-visible row actions (design)

Short spec for a bounded change. Implements [RF-017](../../requirements.md) on top of the author→curator
workflow of RF-016 / [ADR-0018](../../decisions/0018-flujo-autor-curador-rea.md), under the visual rules of
[ADR-0014](../../decisions/0014-lenguaje-visual-vista-maestra.md). Requested by the owner and answered on
2026-10-03 (see the TASK-044 row in `backlog.md`).

## 1. Problem

A curator cannot tell from the master view which REA await validation: the only way is the «Propuestos»
filter, which hides every other row. The row actions (edit) were hidden until the pointer hovered the row,
so nothing placed there could be seen while scanning. An `author` has no access to the master view and,
once their REA is proposed, gets no workflow action on the item page either — nothing tells them it is
waiting.

## 2. Decisions

| # | Question | Decision | Source |
| --- | --- | --- | --- |
| D1 | Which REA carry a badge | Exactly `curation:status` = `Propuesto` (badge «Propuesto») and = `Rechazado` (badge «Rechazado»). Draft (no value), published (value removed) and any unknown literal: no badge. Exact equality, like the rest of `WorkflowStatus` | RF-017 (1), owner answer (b) |
| D2 | Where | Master view: inside `ul.actions` of the title cell, before the edit icon. Native item page (`view.show.page_actions`): before the workflow buttons, for everyone who opens it, author included | RF-017, owner answer (a) |
| D3 | Native items browse | **Not touched.** ADR-0014 rule 2 keeps the native browse untouched; the author reaches the state from the item page, and a browse badge would cost a listener on every admin browse | delegated to this spec by RF-017 (a) |
| D4 | Data source | `$item->value('curation:status')` of the representation each row already holds. No query per row (RF-017 (2)) | RF-017 (2) |
| D5 | Coding | Text + glyph + border: «● Propuesto» solid border, «✗ Rechazado» dashed border. Distinguishable without colour (ADR-0014 rule 3); the glyph is `aria-hidden`, the visible text is the accessible name; a `title` explains the state | RF-017 (3), ADR-0014 rule 3 |
| D6 | Colour in the master view | «Propuesto» in the accent (`--oer-accent`): it is the state that needs the curator (ADR-0014 rule 2). «Rechazado» in `--oer-muted`: it waits on the author. Both ≥ 4.5:1 on the three table backgrounds (`test/js/contrast.test.js`). On the item page the badge inherits the host colour | ADR-0014 rules 1–2, NFR-006 |
| D7 | Always-visible actions | Drop the `@media (hover: hover)` opacity rule on `.oer-title-cell .actions` (and its reduced-motion override). The edit link stays a native link, keyboard-reachable | RF-017 (4) |
| D8 | Translation | Badge labels and titles go through `translate` (NFR-005) | RF-017 (3) |

## 3. Implementation

- `WorkflowStatus::normalize(?string)` — single reading rule for the raw literal (trimmed, blank = draft);
  `WorkflowService::statusOf()` now delegates to it. `WorkflowStatus::badge(?string)` maps a status to
  `proposed` / `rejected` / null.
- Partial `view/oer-manager/common/workflow-badge.phtml` — the one markup for both surfaces.
- `asset/css/oer-workflow-badge.css` — shape only, no colour, so it can load on the native item page
  without duplicating the module tokens (ADR-0014 rule 1). Colour for the table lives in
  `oer-master-view.css`, scoped to `#oer-master-view-table`.
- `Module::addWorkflowActions()` echoes the badge (and appends the stylesheet) **before** the permission
  cut-off, so an author with no available action still sees it.

## 4. Acceptance

1. Master view: a row shows «Propuesto» iff `curation:status` = `Propuesto`, «Rechazado» iff = `Rechazado`;
   the «Propuestos» filter lists exactly the rows with the «Propuesto» badge (filter unchanged, same exact
   `eq` on the same literal).
2. Edit icon and badge visible without hover on pointer devices; edit reachable with Tab.
3. Native item page of a proposed REA, opened by its author: badge shown, no workflow button. Rejected REA:
   «Rechazado» badge plus «Proponer para revisión».
4. Unit tests: `WorkflowStatusTest` (normalize, badge), `ModuleRuntimeTest` (badge without actions),
   `contrast.test.js` (accent on the table backgrounds).

## 5. Out of scope / verification debt

- Native items browse (D3); public site.
- Not yet checked in a logged-in Omeka admin: the core admin CSS for `ul.actions` inside a table cell and
  the fit of the badge in the fixed-height `#page-actions` bar. First session with credentials should
  confirm both (same debt as ADR-0014 §Consecuencias).
