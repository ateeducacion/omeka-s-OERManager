# ADR-0024: Own visual identity for the curricular alignment editor

## Estado

**Propuesto (2026-11-20)**, at the owner's request («pide excepción en este caso»), after reviewing a
prototype of the editor (<https://claude.ai/artifact/Ap5xxptk6H8jyFrRjy4LYX>). It becomes **Aceptado** when
the owner approves it together with the revised spec of TASK-065 part B. It is an exception to rule 1 of
ADR-0014, limited to one component. It replaces no ADR.

## Contexto

ADR-0014 rule 1 makes every curation surface of the module inherit the admin's chrome: Lato, the admin
palette and native controls, with no typefaces, backgrounds or identity of its own. It was written for the
master view and the drawer, which are reading and triage surfaces.

TASK-065 part B turned the re-cataloguer into a tree (Curso → Materia → Saberes/Criterios). The owner tried
it and found it «más estructurada para anclar un recurso», but its look still falls short. The editor is
not a reading surface: it is the one place where a curator works through up to ~150 curriculum terms per
subject, with codes, blocks, AI proposals and an unfitted list. Inside the admin's chrome that density
reads as one long undifferentiated scroll.

The owner asked for a design that leaves Omeka's style. A prototype was made with a master-detail layout
and its own type, colour and components, and the owner chose it on 2026-11-20 (spec B8).

## Alternativas consideradas

- **A) Stay within ADR-0014.** No exception. Against: the owner reviewed that result and asked for more.
  The editor is the surface where density matters most.
- **B) Own identity for the whole module** (ADR-0014 option B). Against: the master view and the drawer
  would stop reading as part of the admin, which is exactly the risk ADR-0014 avoided, for screens that
  work well as they are.
- **C) Own identity for the alignment editor only.** It is scoped, visible as a deliberate workspace, and
  does not touch the surfaces where blending in matters.

## Decisión

**Option C.** The curricular alignment editor (the re-cataloguer's edit mode, `.oer-align-editor` and
its review step) may use its own visual identity, under these limits:

1. **Scope.** Only inside the editor's root. Its tokens and rules live in their own stylesheet,
   `asset/css/oer-alignment-editor.css`, every selector under the editor's root class. Nothing leaks to
   the drawer's read mode, the tabs, the flow bar or the master view, which keep ADR-0014.
2. **Own typefaces, bundled.** Public Sans for the interface and IBM Plex Mono for curriculum codes only,
   both SIL OFL 1.1. The `woff2` files and their licences ship inside the module, under
   `asset/fonts/`. They are **never loaded from a third-party CDN**: loading fonts from Google from the
   production admin would send every curator's IP to a third party. Fallbacks: `system-ui` and
   `monospace`.
3. **Own palette, with contrast measured.** The ink, ground, selection accent, AI accent, unfitted
   amber, added and removed colours are tokens declared once as CSS variables at the editor's root. Every
   text and state pair is measured against WCAG 2.2 AA (4.5:1 for text, 3:1 for large text and UI
   boundaries) before acceptance, and the results are recorded in the spec.
4. **ADR-0014 rules 2–4 still hold inside the editor.**
   - Every state the curator acts on has its own shape as well as colour: the «IA» mark, the unfitted
     block's icon and the +/− signs.
   - The admin's red is not used for links.
   - Severity that only grades may lean on colour, provided its accessible name says it in text.
5. **Native controls stay native.** Checkboxes, inputs, buttons and links remain real HTML controls with
   visible focus, labels and keyboard behaviour (NFR-006). The identity is skin and layout, never
   look-alike widgets.

## Consecuencias

**Más fácil.**
- The editor can be designed for its job: one subject in focus, codes legible at a glance, the AI
  proposals and the unfitted values impossible to miss.
- Later changes to the editor's look need no further ADR while they stay within these limits.

**Más difícil.**
- The module now maintains a small design system of its own: two bundled typefaces and about a dozen
  tokens.
- An Omeka redesign no longer carries the editor along with it, so the editor may drift from the admin
  and its look must be revisited when the admin changes.
- Bundled fonts add about 150 KB to the release, loaded only when the editor opens.

**Riesgo aceptado.** A curator moves between the admin's look (drawer, table) and the editor's own look in
the same sidebar. This is accepted because entering the editor is an explicit mode change («Re-catalogar»),
and the change of look signals that mode.

## Fuentes

- Owner's review of TASK-065 part B and decisions of 2026-11-20 (adopt master-detail; ask for this
  exception; ship it with part B).
- Prototype: <https://claude.ai/artifact/Ap5xxptk6H8jyFrRjy4LYX>.
- ADR-0014 (rule 1, and the alternatives it weighed), ADR-0017 (panel in the native sidebar), NFR-006.
- Spec `docs/superpowers/specs/2026-11-18-task-065-panel-usability-design.md` §B8.

---

*Append-only record: ADRs are not deleted or rewritten. To change a decision, create a new ADR and mark this
one «Reemplazado por ADR-NNNN».*
