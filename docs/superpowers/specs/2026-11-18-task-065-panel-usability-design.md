# TASK-065: usability and design pass over the admin panel

- **Date:** 2026-11-18
- **Task:** TASK-065 (milestone 2 of the [1.0 plan](2026-11-17-v1-production-plan.md))
- **Requirements:** RF-002, RF-004, RF-005, NFR-004, NFR-006
- **Decisions it works within:** ADR-0014 (native admin visual language), ADR-0017 (the panel lives in the
  native sidebar), ADR-0022 (`cur:` curriculum model), ADR-0002/ADR-0015 (curation audit)

## Why and when

The detail and re-cataloguing panel grew slice by slice. The owner reviewed it in a logged-in admin on
2026-11-18 and reported these problems:

- There is no clear separation between sections.
- «Re-catalogar» and «Deshacer …» are not aligned.
- The curation history does not look like a section that can be opened, and once open it is not usable.
- The history shows ids instead of names.
- The re-cataloguing editor is too long, Confirm/Cancel get lost below it, the diff is unclear, and the
  AI proposal is not integrated with the manual editing.
- The curricular alignment should be built as the hierarchy it is: course → subject → knowledge and
  criteria, each subject a collapsible group.

**Order.** The backlog placed this pass after TASK-070, once curators had tried the queue. On
2026-11-18 the owner decided to bring it forward, based on their own review of the panel. Curator
feedback from TASK-070 may still lead to a later pass.

## Constraints

- **Within ADR-0014** (owner's answer, 2026-11-18): native typefaces, palette and admin buttons. Only the
  existing `--oer-*` tokens. No own palette or type scale.
- **The panel stays in the sidebar** (ADR-0017). Editing may widen the sidebar; it does not move to a
  modal or a separate page.
- **The RDF does not change shape.** An REA keeps five flat lists: `lrmi:educationalLevel` (`cur:Curso`),
  `schema:about` (`cur:MateriaCurso`), `lrmi:teaches`, `lrmi:assesses` and `dcterms:relation`. The tree is
  a view. The `recatalog-apply` contract, the audit events, undo and the AI justifications stay as they are.
- **Accessibility at the admin's level** (NFR-006): native controls, `aria-expanded`, labelled checkboxes,
  counts in a polite live region.
- **Curriculum items are read-only inputs.** Nothing here writes to them.

## Part A: structure and style of the panel

### A1–A2. Sections as tabs (revised 2026-11-19)

**Revision.** The first version of A1–A2 (a top rule per section; Media, Record and History as
`<details>` with a chevron) was implemented and reviewed by the owner. It still did not separate the
sections clearly and kept the long scroll. The owner asked for tabs under the panel header instead
(2026-11-19) and chose this grouping.

- **Always visible, above the tabs:** title, header (thumbnail, visibility, verdict), workflow state and
  actions, and integrity alerts.
- **Four tabs:** **Currículo** (curricular alignment and the «Re-catalogar / Deshacer» bar; the default
  tab) · **Gobernanza** (licence and authorship) · **Recurso** (Media, then Record, each with its
  heading, not collapsible) · **Historial** (the timeline of A4).
- **Native look (ADR-0014):** the admin's own sidebar tabs, `.sidebar-section-nav` (pill buttons with
  `role="tab"`). The core's script for them lives in `resource-form.js`, which the master view does not
  load, so a small module script switches them, scoped to the drawer.
- **Rendered by the server:** all four panels come with `drawer-details`; switching makes no request,
  except History, which still loads on its first opening (NFR-004).
- **The chosen tab is remembered** while moving from one REA to another in the master view
  (`sessionStorage`; if unavailable, Currículo).
- **Editing survives a tab switch:** the hidden panel keeps its DOM, so an open re-cataloguing or
  governance edit is still there on return.
- **Accessibility (NFR-006):** the ARIA tabs pattern. Tabs have `role="tab"`, `aria-selected` and
  `aria-controls`; panels have `role="tabpanel"`, `aria-labelledby` and `hidden`. Arrow keys, Home and
  End move between tabs (roving `tabindex`); Tab enters the panel.
- The two-column grid of alignment | media + record is removed.

### A3. The re-cataloguing bar

Cause of the misalignment: «Re-catalogar» is a native admin button, while «Deshacer …» has its own
font size and padding and not the native height. Both become native buttons of the same height,
aligned on the bar's baseline. «Deshacer» stays secondary (border, no fill) so it does not compete with
«Re-catalogar». It keeps its place in the bar and its behaviour (it undoes the latest event of any scope).

### A4. History as a compact timeline

- One line per event, newest first: `18 nov 2026 · <contributor> · Saberes +2 −1 · Criterios +1`.
  An undo is marked as such.
- The line is a disclosure. Opening it shows today's detail: added and removed values by name, and the
  collapsed «Ver porqué» of the AI (PEND-013).
- The first 5 events are shown, then «Ver 5 más». Paging is client-side: the server already sends the
  whole history of one REA, which is short.
- The per-event summary is computed in `core/historyModel.js` with human labels (never the RDF term).

### A5. Names instead of ids

The server resolves each referenced id to its title (`RecatalogService::titlesFor`) and
`CurationHistory` renders `#<id>` when the read fails. First step of the implementation: confirm the
cause in the container with a harness (terms deleted when the curriculum moved to `cur:`, or terms the
user cannot read). Then:

1. **New events store the label of every value** they add or remove, in an optional `labels` key of
   payload v1 (decode already ignores unknown keys; undo never reads it). The read side prefers a live
   title, then the stored label.
2. **Older events without labels** whose target can no longer be read show «término retirado (#123)»
   instead of a bare `#123`.

This touches the curation audit: implement it following the `rdf-curation` skill. Undo keeps working on
ids only; labels are for display.

Governance events (payload v2) were painted as `#1`: fixed by rendering typed values as text.

**Cause measured (2026-11-18, `test/container/history-titles-check.php`, read only):** on the development
container, 16 REA hold 51 events; 8 are governance events (the `#1` above) and the history references
296 ids that no longer exist (terms of the old graph, deleted when the curriculum moved to `cur:`). No
referenced id was unreadable for a non-administrator editor. Those 296 now read «término retirado (#n)».

### A6. Flow bar and integrity (added 2026-11-19)

The owner asked to make the two zones above the tabs smaller, and chose these two proposals:

- **A compact flow bar.**
  - The state is the same badge as the table and the item page, including «Publicado fuera del flujo».
    «Borrador» has no badge, so it shows as plain text.
  - The actions are native buttons in one row: «Confirmar y publicar» and «Reabrir» act at once.
    «Rechazar…», «Descartar…» and «Despublicar…» open ONE inline reason form under the bar, one at a
    time. It has a visible label, the full verb and «Cancelar», and Escape closes it.
  - Each action keeps its own form, AJAX submit, CSRF hash and required reason.
- **Integrity summary and issues per tab.**
  - Above the tabs there is one line, for example «⚠ 4 avisos · impiden publicar». «impiden publicar»
    appears only to whoever can publish and only when the publish gate would refuse. The gate and the
    panel share `IntegrityPolicy::PUBLISH_GATE_IGNORES`.
  - The summary opens the first tab with issues.
  - Each tab carries its count, for example «Currículo ⚠4», and lists its issues at the top in plain
    language. The missing anchors become one sentence («Falta el anclaje curricular: curso, materia,
    saberes básicos y criterios de evaluación»). Quoted RDF terms are replaced by their labels.
  - Workflow issues (`curation:status`, `o:site`) are notes in the flow bar. An out-of-flow REA is not
    repeated, because its badge says it.
  - The pure `PanelIssues` class decides where each issue goes and how it is worded. The rules and the
    gate do not change.
- **After a governance save**, `ui/panelIntegrity.js` re-reads the panel from the server and swaps only
  the integrity pieces. Logic is not duplicated in the browser, and an open re-cataloguing in another tab
  is not lost.
- The server label of `lrmi:educationalLevel` becomes «Curso».

## Part B: the re-cataloguer as a tree

The server already provides what the tree needs: `search-terms` narrows subjects by the chosen courses
(`level`), and `curriculum-tree` returns knowledge and criteria grouped by subject (`trees[].subject`).
Part B is a client-side restructuring.

### B1. Shape of the editor

While editing, the sidebar widens.

```
[Proponer con IA]
CURSOS  [+ Añadir curso ▾ (Etapa para acotar)]
▾ 1.º ESO                                   [quitar curso]
   ▾ Matemáticas · 3 saberes, 2 criterios   [quitar materia]
       Saberes   [filtro] ☑ árbol por bloques…
       Criterios [filtro] ☑ árbol…
   ▸ Lengua · 1 saber, 0 criterios
   [+ Añadir materia de 1.º ESO]
▸ 2.º ESO · 1 materia
SIN ENCAJAR (only when there are values without a course or a subject)
EJE TEMÁTICO  [flat picker, as today]
──────────── sticky footer ────────────
[Previsualizar] [Confirmar] [Cancelar]   + diff
```

### B2. Model: `asset/js/core/alignmentTree.js`

A new pure core module without DOM, tested with `node --test`.

- **Builds** the tree from the REA's current values and the `curriculum-tree` response. A subject belongs
  to exactly one course: the `cur:curso` of its `cur:MateriaCurso`.
- **Operations:** add a course; add a subject under a course; check or uncheck a knowledge item or a
  criterion; remove one item; remove a subject with its content; remove a course with its subjects and
  their content.
- **Summaries** of collapsed groups: «Matemáticas · 3 saberes, 2 criterios», «2.º ESO · 1 materia».
- **Serializes** to the five flat lists the apply contract already takes, plus the AI justifications of
  the values that carry one.
- **Diff grouped** by course and subject, built from the flat diff the preview endpoint already returns.

### B3. Values that do not fit

A knowledge item or criterion whose subject is not chosen, or a subject whose course is not chosen, is
never dropped silently. It goes to a «Sin encajar» group, as the read view does today
(`CurricularGrouping`). The curator removes it or adds what it is missing.

### B4. Interactions

- **Course:** a search with Etapa as a narrowing helper. Etapa is not written to the REA, as today.
- **Subject:** «+ Añadir materia» inside each course, with a search narrowed to that course.
- **Knowledge and criteria:** adding a subject opens its two trees. The existing `leafTree` trees and
  their text filter are reused, mounted once per subject.
- **Collapse:** courses and subjects collapse to their summary. The group being worked on stays open.
- **Remove:** one item is removed without asking. A whole group asks for confirmation with its count
  («Quitar Matemáticas y sus 5 términos»).

### B5. AI proposal

«Proponer con IA» fills the same tree: it creates the missing courses and subjects and checks their
knowledge and criteria. Proposed nodes carry an «IA» mark with their reason collapsed. Nothing is
written without «Confirmar», as today. «Detener la propuesta» keeps its behaviour.

### B6. Sticky footer and diff

Previsualizar, Confirmar and Cancelar stay visible at the bottom of the sidebar (`position: sticky`)
while editing. The diff shows added (`+`) and removed (`−`) values by name, grouped by course and subject,
with the thematic axis as its own row.

### B7. Accessibility (NFR-006)

Native disclosure buttons with `aria-expanded`/`aria-controls`, native labelled checkboxes, counts and
summaries announced in a polite live region, keyboard as in today's trees (Tab, Space,
ArrowRight/ArrowLeft on a branch).

## Out of scope

- The item page's workflow buttons (redesigned in TASK-067).
- Workflow events in the history timeline (owner's answer, 2026-11-18).
- Moving «Deshacer» into the history.
- The curation queue (TASK-070) and the governance editor's fields.

## Testing

- **JavaScript (`make test-js`):** `historyModel` (event summary, paging), `panelTabs` (initial tab, keyboard), `workflowReason` (one reason form at a time), `alignmentTree` (build, values
  that do not fit, operations, serialization, summaries, grouped diff).
- **PHPUnit (`make test`):** `PanelIssues` (tab per field, grouped missing anchors, labels instead of RDF terms, publish gate count); the payload version with labels, reading older payloads, the «término
  retirado» fallback. The existing apply tests stay valid because the contract does not change.
- **Container:** a harness confirms the cause of `#<id>` in A5. Visual review of the panel with throwaway
  non-administrator users holding REA roles, in a disposable environment.
- **Lint:** `make lint`.

## Delivery

Two slices, each reviewable on its own: **A** (A1-A5) first, then **B** (B1-B7).
