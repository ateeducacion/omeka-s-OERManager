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

### A1. Visible sections

Each `.oer-area` becomes a block with a top rule (`--oer-rule`) and more space between blocks
(about 1.5em instead of 1.25em). Its `h4` keeps the small caps and gains some contrast. The order does
not change: header (title, state, alerts) → Curricular alignment → Media → Record → Governance → History.

### A2. Collapsible sections with a visible indicator

History, Media and Record use `<details>`/`<summary>` with a visible chevron (▸ closed, ▾ open) and the
same heading style as the other sections, so they read as sections that open. History still loads on
first open (NFR-004). Curricular alignment and Governance are always open: they are the ones edited.

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

1. **New events store the label of every value** they add or remove, in a new payload version that is
   read alongside the older ones. The read side prefers a live title, then the stored label.
2. **Older events without labels** whose target can no longer be read show «término retirado (#123)»
   instead of a bare `#123`.

This touches the curation audit: implement it following the `rdf-curation` skill. Undo keeps working on
ids only; labels are for display.

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

- **JavaScript (`make test-js`):** `historyModel` (event summary, paging), `alignmentTree` (build, values
  that do not fit, operations, serialization, summaries, grouped diff).
- **PHPUnit (`make test`):** the payload version with labels, reading older payloads, the «término
  retirado» fallback. The existing apply tests stay valid because the contract does not change.
- **Container:** a harness confirms the cause of `#<id>` in A5. Visual review of the panel with throwaway
  non-administrator users holding REA roles, in a disposable environment.
- **Lint:** `make lint`.

## Delivery

Two slices, each reviewable on its own: **A** (A1-A5) first, then **B** (B1-B7).
