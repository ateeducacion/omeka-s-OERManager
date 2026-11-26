# TASK-077 guided wizard to add a REA: implementation plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or
> superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax.

**Goal:** A four-step wizard, reached from «Mis propuestas», that creates (or resumes) a private REA in the REA
site with its data, its main resource and its curricular anchoring, and optionally proposes it.

**Architecture:** A new admin controller (`ReaWizardController`, route `admin/oer-manager-wizard`) serves a
standalone page and three JSON endpoints. Pure services decide the schema, the minimum rule and the payloads;
`ReaWizardSave` orchestrates the writes through the existing services (API create/update, `GovernanceService`,
`RecatalogService::apply`, `WorkflowService::propose`). A module media ingester attaches the file uploaded in
step ① at `save`. The browser side is a pure model (`core/wizardModel.js`, node-tested) and a DOM layer.

**Tech Stack:** PHP 8.4, Omeka S 4.2, Laminas MVC; ES modules, `node --test`.

**Spec:** `docs/superpowers/specs/2026-11-26-task-077-rea-add-wizard-design.md` (owner-approved 2026-11-26).
Prototype approved by the owner on 2026-11-26: <https://claude.ai/artifact/3jbXMMP3wbu66gG8TYqTLz>.

## Global Constraints

- Target PHP 8.4; PSR-12 (`make lint`); no PHPStan/Psalm; Makefile unchanged; no custom Doctrine tables.
- UI copy in Spanish through `$translate` (server) — the page passes its translated strings in its config JSON.
- Code, docs and commits in English; no Claude attribution.
- Every write is POST + CSRF (`oer_wizard`); errors answer JSON `{ok:false, error:<code>, message}`; details go
  to the log without personal data.
- The REA is private, of class `lrmi:LearningResource`, with the REA template and in the REA site.
- The workflow status is never written by hand: only `WorkflowService::propose`.
- Anchoring and axes only through `RecatalogService::apply` (same validation, annotations and event).
- Uploaded files and metadata read from them are data: escaped, never interpreted.
- Codecov patch coverage of `src/` ≥ 90 %: every controller action and service has a host test.

## Decisions taken in this plan (owner said «hazlo» on 2026-11-26)

1. **Identity:** the wizard adopts the public theme's «Canarias Educativa» tokens (`omeka-s-theme-rea-ate`):
   institutional blue `#0C2C84`, Atlantic blue `#0768AC`, sun yellow `#FFB300`, Inter. Recorded by
   **ADR-0025**, which extends ADR-0024's exception to the wizard with the theme's palette instead of the
   alignment editor's. Inter is bundled (`asset/fonts/`), never loaded from a CDN; icons are inline SVG
   (the theme's Material Symbols font is 3 MB and would be a CDN or a heavy bundle).
2. **Media ingestion (spike):** Omeka's `upload` ingester runs `RenameUpload` (`move_uploaded_file`), so it
   cannot ingest a file stored earlier. The module registers its own ingester `oermanager_wizard`: it takes
   `wizard_token`, resolves the stored file of **the current user** in `WizardUploadStore`, copies it to an
   Omeka `TempFile` and calls `mediaIngestFile()` — Omeka's own validator (allowed types and extensions)
   still applies. A URL goes through the core `url` ingester.
3. **Governance fields** of the template (`dcterms:license`, `dcterms:creator`, `dcterms:publisher`,
   `dcterms:rightsHolder`, `dcterms:source`) are written with `GovernanceService::apply` (vocabulary
   validation and a curation event); the other data fields go in the item create/update payload.
4. **Stage** is navigation only: the wizard writes courses (`lrmi:educationalLevel`), course subjects
   (`schema:about`), knowledge, criteria and axes; the minimum rule checks courses and course subjects.
5. **Axes browse:** `search-terms?dimension=dcterms:relation&browse=1` lists up to 100 axes (today it answers
   `parent_required`).
6. **Default licence:** new setting `oermanager_default_licence` (a URI of the licence vocabulary), optional.

## Review Focus

- A proposer resumes a REA id that is someone else's or already Propuesto → same `not_found` as a missing id.
- An upload token of another user, an expired token, or a reused token at `save` → `upload_expired`, no item.
- A `save` whose governance or anchoring write fails after the item exists → the REA stays a Borrador, the
  answer carries `id` and the failed step, and `propose` is not attempted.
- A hostile `.elpx` (zip bomb, entity declaration, huge `content.xml`) or a broken PDF → no metadata, no error.
- A resume that changes only some fields keeps every property the wizard does not manage (title kept,
  provenance never cleared).

---

### Task 1: ACL, route and navigation

**Files:** Modify `src/Permissions/ReaAcl.php`, `config/module.config.php`; Create
`src/Controller/Admin/ReaWizardController.php` (skeleton); Test `test/Permissions/ReaAclTest.php`.

- [ ] Test: `ReaWizardController` grants `index`, `upload`, `duplicates`, `save` to `rea_proponente` and
  `rea_curador`; `IndexController` `my-proposals` granted to `rea_curador`; `ReaAclTest`'s "every action is
  decided" check covers the new controller.
- [ ] Route `admin/oer-manager-wizard` (`/oer-manager/wizard[/:action]`, default `index`); controller factory.
- [ ] Commit `feat: wizard ACL, route and curators' «Mis propuestas» (TASK-077)`.

### Task 2: `ReaWizardSchema` (pure)

**Files:** Create `src/Service/Wizard/ReaWizardSchema.php`; Test `test/Service/Wizard/ReaWizardSchemaTest.php`.

**Produces:** `ReaWizardSchema::fromTemplate(list<array{term:string,label:string,alternateLabel:?string,
required:bool,dataTypes:list<string>}>): array{data:list<array{term,label,required,control,dataType,help}>,
anchoring:list<string>}`; constants `ANCHORING_TERMS`, `MACHINE_TERMS`, `GOVERNANCE_TERMS`; control is one of
`text|textarea|cards|select|url|licence`.

- [ ] Tests: order kept; alternate label wins; `dcterms:description` → `textarea`; `lrmi:learningResourceType`
  with `customvocab:N` → `cards`; other `customvocab:N` → `select`; `uri` → `url`; `dcterms:license` →
  `licence`; unknown data type → `text`; anchoring and machine terms are never data fields; empty template →
  no fields; help texts only for title, description, resource type, licence, authorship, project.

### Task 3: `WizardUploadStore` and `MediaMetadataReader`

**Files:** Create `src/Service/Wizard/WizardUploadStore.php`, `src/Service/Wizard/MediaMetadataReader.php`;
Tests in `test/Service/Wizard/`.

**Produces:** `WizardUploadStore::put(int $userId, string $tmpPath, string $name, string $mediaType): string`
(token, 32 hex), `get(int $userId, string $token): ?array{path,name,mediaType,size}`, `forget(string $token)`,
`sweepOld(int $ttl)`. Files under a private dir (`sys_get_temp_dir()/oer-manager-wizard`), mode 0600, names
from the token, metadata JSON beside them; TTL 24 h. `MediaMetadataReader::read(string $path, string $name):
array{title?,description?,author?,licence?}`.

- [ ] Tests: put/get round trip; another user's token → null; malformed token → null; forget removes both
  files; sweep removes old ones. Metadata: `.elpx` fixture built in the test with ZipArchive (namespaced and
  bare `<ode>`), `pp_title` «eXeLearning» ignored, HTML stripped; `<!ENTITY` → `[]`; oversize `content.xml` →
  `[]`; not a zip → `[]`; PDF with `/Title (…)` in its info dictionary → title; garbage → `[]`.
- [ ] Limits reused from `ContentExtractor::DEFAULTS` (`max_entry_bytes`, `max_compression_ratio`).

### Task 4: `WizardIngester` (spike result)

**Files:** Create `src/Media/Ingester/WizardIngester.php`; Modify `config/module.config.php` (`media_ingesters`);
Test `test/Media/WizardIngesterTest.php` (logic split into a testable `resolve()` returning the stored file or an
error code); container check in Task 9.

### Task 5: `DuplicateFinder` and `MinimumRule`

**Files:** Create `src/Service/Wizard/DuplicateFinder.php`, `src/Service/Wizard/MinimumRule.php`; tests.

**Produces:** `MinimumRule::missing(array $state, list<string> $requiredTerms): list<string>` with codes
`resource`, `field:<term>`, `licence`, `course`, `subject`; `DuplicateFinder::find(string $title, string $name,
?int $exceptId): list<array{id:int,title:string}>` (title: API `eq` search, case-insensitive by collation; name:
media `source` via DQL id-only, then each item read through the API so ACL filters it; at most 5).

### Task 6: `ReaWizardSave`

**Files:** Create `src/Service/Wizard/ReaWizardSave.php`; Test `test/Service/Wizard/ReaWizardSaveTest.php`.

**Produces:** `save(array $state, ?ItemRepresentation $existing, int $userId, string $contributor): array{ok:bool,
id?:int, status?:string, error?:string, step?:string, missing?:list<string>}`.

- [ ] Tests: create payload (class, template, private, site, data values, media with token / url); draft
  without title → `title_required`; update clears only managed data terms and appends; replaced media creates
  the new one and deletes the old; governance via `GovernanceService::apply`; anchoring via
  `RecatalogService::apply` only; a `RuntimeException` from anchoring → `{ok:false, id, step:'anchoring'}` and
  no propose; `propose:true` with missing items → `{ok:false, error:'incomplete', missing}` after saving the
  draft; propose through `WorkflowService::propose`; status never in any payload; upload token forgotten after
  success.

### Task 7: Controller actions and default licence setting

**Files:** Modify `src/Controller/Admin/ReaWizardController.php`, `src/Form/ConfigForm.php`,
`src/Service/GovernanceSettings.php`, `src/Controller/Admin/IndexController.php` (axes browse); Test
`test/Controller/ReaWizardControllerTest.php`.

- [ ] `index`: no template → page with the notice; `?id=` resumes only when `RecatalogRule::denial()` is null
  and `readDenied` is false, else `not_found`; config JSON with schema, options, default licence, upload limit
  (`min(upload_max_filesize, post_max_size)`), urls, CSRF, resumed state and rejection reason.
- [ ] `upload`, `duplicates`, `save`: POST + CSRF; type checked by content (`finfo`) against the allow-list.

### Task 8: Front end

**Files:** Create `asset/js/core/wizardModel.js`, `asset/js/wizard.js`, `asset/js/ui/wizard*.js`,
`asset/css/oer-wizard.css`, `asset/fonts/inter-*.woff2` (+ licence),
`view/oer-manager/admin/rea-wizard/index.phtml`; Modify `view/oer-manager/admin/index/my-proposals.phtml`;
Tests `test/js/wizardModel.test.js`, `test/js/wizardContrast.test.js`.

- [ ] Model tests: first incomplete step; missing list mirrors `MinimumRule`; «Le falta» texts; autosave
  merge (newer local state offered, older ignored); summary sentence («Para 1.º y 2.º de ESO · Matemáticas ·
  3 saberes · 1 criterio»); payload built for `save`.
- [ ] Contrast test pins every text/background pair of `oer-wizard.css` at WCAG AA.

### Task 9: Container harness and governance docs

**Files:** Create `test/container/wizard-check.php`, `docs/decisions/0025-wizard-visual-identity.md`; Modify
`docs/backlog.md`, `docs/traceability.md`, `docs/project-memory.md`.

- [ ] Harness: throwaway `rea_proponente` and `rea_curador` (pattern of `batch-ai-check.php
  --disposable-curator`); upload + ingest spike; create draft; resume; propose; template-invalid case;
  someone else's REA refused; deletes everything it created.
