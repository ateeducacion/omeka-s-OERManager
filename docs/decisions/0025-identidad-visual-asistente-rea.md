# ADR-0025: Own visual identity for the wizard to add a REA, from the public theme

## Estado

**Aceptado (2026-11-26)** by the owner, with the review of the wizard's prototype
(<https://claude.ai/artifact/3jbXMMP3wbu66gG8TYqTLz>) for TASK-077. The first prototype used the alignment
editor's palette (ADR-0024); the owner asked for «la apariencia del tema que está haciendo Fran para el canal
de mediateca de ATE, con los azules y amarillos, más atractivo y menos IA», reviewed the second version and
approved it («hazlo»). It is a second exception to rule 1 of ADR-0014, limited to one page, and replaces no ADR.
The contrast measurement of point 3 is pinned by a test.

## Contexto

The spec of TASK-077 (`docs/superpowers/specs/2026-11-26-task-077-rea-add-wizard-design.md`) gives the wizard
its own identity outside Omeka's admin chrome and proposed «the same visual language as the alignment editor»
(ADR-0024). The wizard is designed for a teacher with B1 digital competence, because it will later open to
school teachers. For them the wizard is the door to the Mediateca, not a curation workspace: it should look
like the Mediateca they know, the public REA site.

That site is themed by `omeka-s-theme-rea-ate` («Canarias Educativa» design system, `DESIGN.md`): the
Government of the Canary Islands' palette (institutional blue `#0C2C84`, Atlantic blue `#0768AC`, sun yellow
`#FFB300`), Inter, notice cards with a 4px left border, a white top bar with a 3px yellow rule and a blue
footer with a yellow rule.

## Alternativas consideradas

- **A) The alignment editor's identity (ADR-0024).** What the spec proposed. Against: the owner reviewed it
  and found it generic («menos IA»); it is a curator's workspace look, not the Mediateca's.
- **B) Omeka's admin chrome (ADR-0014 rule 1).** Against: the spec and the owner want the wizard out of the
  admin's long flat form; teachers would meet the admin's look at their first step.
- **C) The public theme's identity, scoped to the wizard.** Recognisable as the Mediateca, institutional,
  and already designed for teachers, students and families.

## Decisión

**Option C.** The wizard to add a REA (route `admin/oer-manager-wizard`, root `.oer-wizard`) uses the public
theme's identity, under these limits:

1. **Scope.** Only the wizard's own page, which renders without the admin layout. Its tokens and rules live in
   `asset/css/oer-wizard.css`, every selector under `.oer-wizard`. «Mis propuestas» keeps the admin's look
   (ADR-0014): only its «Añadir REA» button and the highlighted row are added.
2. **Own typeface, bundled.** Inter (variable, Latin subset, SIL OFL 1.1) ships in `asset/fonts/` with its
   licence and is never loaded from a CDN, for the reason of ADR-0024 point 2 (the theme loads it from Google
   Fonts; the admin must not). Icons are inline SVG: the theme's Material Symbols font would be a CDN or a
   3 MB bundle.
3. **Own palette, with contrast measured.** The theme's colours are tokens declared once on `.oer-wizard`; every
   text and state pair the stylesheet paints is measured against WCAG 2.2 AA by
   `test/js/wizardContrast.test.js` (4.5:1 for text, 3:1 for UI boundaries). Measured while building it: the
   theme's muted grey `#6B7280` gives 4.4:1 on its pale blue card and 4.24:1 on the disabled ground, so it is
   used only on white and the light grey, and disabled buttons use `#4A4A4A`.
4. **ADR-0014 rules 2–4 and ADR-0024 point 5 still hold.** States have a shape as well as a colour (the step
   check, the notice icons); the admin's red is not used for links; native controls stay native (real radio
   buttons and checkboxes inside their card labels, real buttons and links), with visible focus.

## Consecuencias

**Más fácil.**
- A teacher recognises the Mediateca from the first step; the wizard can later open to schools as it is.
- Later changes to the wizard's look need no further ADR while they stay within these limits.

**Más difícil.**
- The module now carries two small design systems of its own (ADR-0024 and this one), and the wizard must
  follow the public theme if the theme's palette changes.
- Inter adds about 48 KB to the release, loaded only on the wizard's page.

**Riesgo aceptado.** A curator moves from the admin's look («Mis propuestas») to the Mediateca's look (the
wizard) and back. Accepted because entering the wizard is an explicit action («Añadir REA», «Continuar») and
leaving it returns to «Mis propuestas».

## Fuentes

- Owner's review of the prototype on 2026-11-26 and the decisions recorded in the TASK-077 spec.
- `omeka-s-theme-rea-ate` (`DESIGN.md`, `view/layout/layout.phtml` tokens `--ate-*`).
- ADR-0014, ADR-0024, NFR-006.

---

*Append-only record: ADRs are not deleted or rewritten. To change a decision, create a new ADR and mark this
one «Reemplazado por ADR-NNNN».*
