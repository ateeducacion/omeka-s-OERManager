# TASK-077: a guided wizard to add a REA

Approved by the owner on 2026-11-26 (brainstorming session, with the visual companion). Milestone 2 of
the [1.0 plan](2026-11-17-v1-production-plan.md) (addendum 1). Requirements: RF-016, RF-001, RF-014,
RF-015, RF-006, NFR-003, NFR-006. Decisions: ADR-0023 §1–4, ADR-0022, ADR-0019, ADR-0024, ADR-0015,
ADR-0016, PEND-012.

## Why

A REA is added one by one with Omeka's native item form and the REA template (ADR-0023 §3, single
additions). That form is a long flat list of every property, with no help on which values are
expected. The curricular anchoring is the hardest part: the native resource selector searches a graph
of about 15 000 `cur:` items by title, one link at a time. The proposer then proposes from the item page
and anchors again from the panel.

The module adds its own screen, more guided, intuitive and usable, reached from «Mis propuestas».

## Who it is for

- In 1.0 the ATE team uses it (`rea_proponente`, `rea_curador`, `global_admin`). ADR-0023 is unchanged:
  only the ATE team proposes.
- It is **designed for a teacher with B1 digital competence**, because it will later open to school
  teachers. One decision per screen, the teacher's language (never RDF terms), defaults, and no lost work.

## Owner decisions (2026-11-26)

| Question | Decision |
| --- | --- |
| When the REA is created | **At the end of one flow**, as in the native form: the anchoring is filled before creating, never «create, then edit again». «Guardar borrador» is available in every step for a flow that is not finished. |
| Screen structure | **A step-by-step wizard** (chosen among a wizard, a single page with an index and two panels): ① Tu recurso → ② Datos → ③ ¿Para qué curso y materia? → ④ Revisar y proponer. The resource comes first so its file can pre-fill the data. |
| The anchoring step | **Guided sub-steps**, not the curators' master-detail editor of ADR-0024: Etapa → Curso → Materia → saberes to tick, then criteria and thematic axes as optional sub-steps. |
| What «Guardar y proponer» requires | **The minimum a curator needs:** the template's required fields, the licence, a minimum anchoring (stage, course and subject) and the attached resource. «Guardar borrador» requires only a title. |
| Resuming a draft | **In the same wizard:** a Borrador or a Rechazado REA in «Mis propuestas» has «Continuar», which opens the wizard loaded, at the first incomplete step. |
| Pre-fill from the file | **The file's metadata:** title, description, authorship and licence declared by a `.elpx`, and the title of a PDF, as editable suggestions. The alignment declared in a `.elpx` stays in TASK-055. |
| AI in the wizard | **No.** Curators have it in the panel and the batch (TASK-011). |
| Duplicates | **A warning that does not block**, in «Revisar», when another REA has the same title or a media file with the same name. |
| Design | **The wizard leaves Omeka's admin design**, as ADR-0024 did for the alignment editor, with the same visual language as that editor. A new ADR records the exception. |

## Behaviour

### Entry and access

- «Mis propuestas» gains a button **«Añadir REA»**, and each Borrador or Rechazado row gains
  **«Continuar»**.
- **Curators gain «Mis propuestas»:** `ReaAcl` grants `my-proposals` and the wizard's privileges to
  `rea_curador` as well as `rea_proponente` (and `global_admin`); nobody else (ADR-0023 §1). The
  navigation entry «Mis propuestas» then shows for curators too.
- Without a configured, readable REA template (`oermanager_rea_template_id`), the wizard page says so,
  links to the configuration (for a global administrator) and saves nothing.
- «Continuar» on a REA the user may not re-catalogue (`RecatalogRule`: a proposer only their own
  Borrador or Rechazado REA; a curator what the rule allows) answers as for an unreadable REA, without
  revealing that it exists.

### Always present

- The step bar (each completed step can be reopened), «Guardar borrador» and «Atrás» / «Siguiente».
- **Local autosave:** the wizard state is kept in `localStorage` per REA (or per «new» draft) on every
  change. On entry, if a local state is newer than what is saved, the wizard asks «¿Recuperar lo que no
  guardaste?». The local state is cleared after a successful save.
- A rejected REA shows its rejection reason at the top of every step.

### ① Tu recurso

- A drop zone and a file chooser, or a field «Pegar un enlace» (a URL). Exactly one main resource.
- The upload goes to the server at once (action `upload`), which stores it in a private temporary
  directory under an unguessable token and answers with its name, size, type and the metadata read.
- The size limit is the server's (PHP `upload_max_filesize` and `post_max_size`, the smaller) and is
  shown before uploading. A file too large or of a type not accepted (types: `.elpx`, `.zip`, PDF,
  images, audio, video and office documents, checked on the server by content, not extension) gives a
  clear message, and nothing typed so far is lost.
- If metadata was read: «Hemos rellenado algunos datos a partir del fichero; revísalos en el paso
  siguiente».
- When resuming a REA that already has media, the step shows them and allows replacing the main one.

### ② Datos

- The template's **data** properties (see `ReaWizardSchema`), in the template's order and with its
  labels (alternate label when set), each with a one-line help text of the module for the known terms
  (title, description, resource type, licence, authorship, project), in the teacher's language.
- Controls by data type:
  - literal → text field (a text area for `dcterms:description`);
  - `customvocab:N` → for `lrmi:learningResourceType`, **cards** with the term; for any other custom
    vocabulary, a select;
  - `uri` → URL field with a label;
  - anything else the module does not know how to edit well → a text field, never hidden.
- **Licence** (`dcterms:license`, ADR-0019): the select of its vocabulary, with a **default licence**
  read from a new optional module setting; without it, empty.
- Values pre-filled from the file carry a «sugerido» mark until edited.

### ③ ¿Para qué curso y materia?

- Sub-steps, with breadcrumbs on top that reopen any of them:
  1. **Etapa**;
  2. **Curso** (several allowed);
  3. **Materia** (subjects of the chosen courses);
  4. **¿Qué saberes trabaja?** — the knowledge items of the chosen subject-courses, grouped by block, with
     a search box and tick boxes, the curriculum code in grey;
  5. **Criterios de evaluación (opcional)** — the same, for criteria;
  6. **Ejes temáticos (opcional)** — tick boxes of the thematic axes.
- Data comes from the same curriculum graph and endpoints the re-cataloguer uses (`curriculum-tree`,
  `search-terms`, RF-014, `cur:` model of ADR-0022). The values map to the same properties
  (`lrmi:educationalLevel`, `schema:about`, `lrmi:teaches`, `lrmi:assesses`, `dcterms:relation`,
  ADR-0004 as amended by ADR-0022). Retired terms are not offered.

### ④ Revisar y proponer

- The record in plain language: the resource, the data, and «Para 1.º y 2.º de ESO · Matemáticas · 3
  saberes · 1 criterio».
- **«Le falta:»** with a link to the step of each missing item, from the minimum rule: the template's
  required fields, the licence, stage + course + subject, and the resource.
- **Possible duplicate:** other REA with the same title (exact, case-insensitive) or with a media file of
  the same original name, linked, without blocking.
- **«Guardar borrador»** and **«Guardar y proponer»** (disabled while anything is missing). After saving,
  the wizard returns to «Mis propuestas» with a message, the REA highlighted.

### What saving writes

`save` is one POST (CSRF) carrying the whole wizard state and `propose: true|false`:

1. **Item:** created (or, when resuming, updated) through the API: class `lrmi:LearningResource`, the REA
   template, **private**, the REA site (ADR-0023 §4), and the data values. A partial update keeps every
   property the wizard does not manage.
2. **Media:** the temporary file (or the URL) attached as the item's media; on resume, a replaced main
   resource replaces its media.
3. **Anchoring and axes:** written with `RecatalogService::apply` — the same target validation, value
   annotations and curation event as the re-cataloguer (ADR-0015), never as plain values.
4. **Propose** (only with `propose: true`): `WorkflowService::propose`, after the server checks the
   minimum rule again. The workflow status is never written by hand.

If step 1 fails, nothing is written and the wizard keeps its state. If a later step fails, the REA stays
a Borrador with what was written, the wizard says what failed and reopens on that REA.

## Out of scope

- AI in the wizard; the alignment declared in a `.elpx` (TASK-055).
- Batch creation and the initial import (TASK-072).
- Replacing the native item form, which stays available.
- Several main resources per REA; editing a published REA.

## Design

### Components

| Unit | Responsibility |
| --- | --- |
| `Controller\Admin\ReaWizardController` | `index` (page; `?id=` to resume), `upload`, `duplicates`, `save`. Route `admin/oer-manager-wizard`. POST + CSRF for every write. |
| `Service\Wizard\ReaWizardSchema` (pure) | From the template's properties: the **data** fields with label, data type, required flag and order; the **anchoring** properties (the five curricular and axis terms); the **machine** properties never shown (`curation:status`, `curation:note`, `curation:data`, `dcterms:provenance`). |
| `Service\Wizard\MediaMetadataReader` | Title, description, authorship and licence of a `.elpx` (`content.xml`, ODE properties `pp_title`, `pp_description`, `pp_author`, `pp_license`, reusing `ContentExtractor`'s zip limits), and the title of a PDF. Never throws: unreadable means no metadata. |
| `Service\Wizard\WizardUploadStore` | Private temporary files by token (outside `files/`, unguessable names, TTL sweep, deleted after ingest), like `ProposalStore`. |
| `Service\Wizard\ReaWizardSave` | The four writes above, the minimum rule (`missing(): list<string>` of codes), and the partial-failure outcome. |
| `Service\Wizard\DuplicateFinder` | Id-only searches by title and by media original name. |
| `ReaAcl`, `Module` navigation, `my-proposals.phtml` | Grants, «Mis propuestas» for curators, «Añadir REA» and «Continuar». |
| `GovernanceSettings` / `ConfigForm` | The optional default licence. |
| JS `core/wizardModel.js` (pure) | Step state, the first incomplete step, the minimum rule mirrored client-side, «Le falta» texts, the autosave merge decision. |
| JS `ui/wizard*.js`, `asset/css/oer-wizard.css` | The four steps and the anchoring sub-steps, in the own visual language. |

### Visual design

- Own identity outside Omeka's admin chrome, sharing the alignment editor's visual language (ADR-0024),
  recorded by a **new ADR** that extends that exception to the wizard.
- WCAG AA contrast measured by a test (as ADR-0024 point 3); full keyboard use; focus moves to the step
  heading on each change; readable on a narrow screen, though desktop is the expected use.
- **A high-fidelity prototype of the four steps, made with the design skills, is reviewed by the owner
  before any implementation.**

### Media ingestion (to settle first)

Omeka's `upload` ingester expects the file in the same request. The plan starts with a spike that
settles, in the development container, whether the temporary file can be ingested at `save` (a module
ingester or Omeka's temp-file API) or whether the file is sent again with `save`. The behaviour above
holds either way.

### Errors and edge cases

- No template or an unreadable one: the page explains it; nothing is saved.
- Upload too large, wrong type or failed: message in step ①; state kept.
- An expired temporary file at `save`: the wizard asks to upload it again.
- Invalid or retired anchoring targets: the re-cataloguer's validation message, naming the term.
- Resuming a REA that is not the user's or not in Borrador/Rechazado: same answer as unreadable.
- Server errors always answer JSON with a code; the detail goes to the log without personal data.
- Metadata read from a file is data: escaped, never interpreted.

## Testing

- **Host, TDD:** `ReaWizardSchema` (classification; empty, unknown and missing templates);
  `MediaMetadataReader` (sample `.elpx` and PDF fixtures; broken and hostile files); `ReaWizardSave`
  (create, update, partial failure leaves a Borrador, the minimum rule, status never written by hand,
  anchoring only through `RecatalogService`); `DuplicateFinder`; `WizardUploadStore`; the controller
  (guards, CSRF, resuming someone else's REA, upload, duplicates); `ReaAclTest`; the JS model.
- **Container:** a harness on the development catalogue with a throwaway `rea_proponente` and
  `rea_curador` (the `--disposable-curator` pattern of TASK-011): create a draft, resume it, propose it,
  a template-invalid case, the media spike; it deletes everything it created.
- **Browser:** the four steps as a `rea_proponente` who is not an administrator (ADR-0023, consequences),
  never as `global_admin`.

## Governance

- New ADR: own visual identity of the wizard (extends ADR-0024).
- `ReaAcl` change: curators gain `my-proposals` and the wizard privileges.
- On close: backlog TASK-077, traceability, project memory together.

## Owner inputs

- **PEND-012:** which template fields are required. The wizard reads them from the template, so it works
  before the decision; the decision changes what «Le falta» lists.
- **The default licence**, if any (a module setting).

## Addendum (2026-11-26, after the prototype review)

- **Identity.** The owner reviewed the first prototype (alignment editor's palette) and asked for the public
  theme's look instead («Canarias Educativa», `omeka-s-theme-rea-ate`). The wizard follows ADR-0025, not
  ADR-0024's palette; «same visual language as that editor» above is superseded by it.
- **Template and drafts.** Omeka refuses an item whose template has an empty required property, so a draft
  carries the REA template only once it meets it (plan, decision 7).
- **Media ingestion (spike settled).** A module ingester ingests the file stored in step ① at `save`; the file
  is not sent again (plan, decision 2 and 8).
