# TASK-064 — OERManager on the `cur:` curriculum model (design)

Date: 2026-11-16. Status: approved by the owner (decisions below are closed). Implements ADR-0022.

## 1. Context

ADR-0022 (2026-11-09) replaced the ADR-0009 graph (`schema:DefinedTerm` + configurable `dcterms:type`
literals) with the model published by `ateeducacion/curriculo_lomloe_canarias`: one item per node,
resource class `cur:<Type>`, SKOS and `cur:` properties, every item in the item set «Currículo LOMLOE de
Canarias», retired nodes kept and marked with `skos:changeNote`. The contract is the repository's
`docs/omeka.md`; the EducationalCurriculum module loads it.

The blocker recorded in the backlog (no curriculum loaded with the new model) is gone. Facts checked on
the development container (port 8080, public API, read only, 2026-11-16):

| Fact | Value |
| --- | --- |
| `cur` vocabulary | id 13; classes `cur:Etapa` … `cur:SaberBasico` (ids 4763–4771) |
| Curriculum item set | «Currículo LOMLOE de Canarias», id 46259, `dcterms:identifier` URI `<base>id/curriculo`, `dcterms:hasVersion` 1.1.2 |
| Retired item set | «Currículo · Retirados», id 46260, `dcterms:identifier` URI `<base>id/curriculo/retirados` |
| Counts | Etapa 4, Curso 18, Materia 138, MateriaCurso 232, CriterioEvaluacion 3137, BloqueSaberes 1047, SaberBasico 8740 (match `curriculo.json` 1.1.2) |
| Titles | `o:title` = `skos:prefLabel` (template title); items carry no `dcterms:title` |
| Retired nodes in 1.1.2 | none |
| Legacy data | 71 REA and ~72 `schema:DefinedTerm` of the old graph remain; they are not touched |

Corpus shape that drives the design: every SaberBasico, CriterioEvaluacion, BloqueSaberes and
BloqueCompetencial carries `cur:materia`, `cur:materiaCurso`, `cur:curso` and `cur:etapa`. Knowledge is
nested under BloqueSaberes by `skos:broader` (depth 1: 5827, 2: 2813, 3: 100; 889 knowledge items have
children). At most 119 knowledge items and 30 criteria per MateriaCurso. A criterion reaches its block by
`cur:bloqueCompetencial`. `cur:Materia` names are unique per stage (they carry a suffix, e.g. «Biología y
Geología (ESO)»). MateriaCurso labels are «<materia> · <curso>»; leaf labels are «<notation> · <truncated
text…>»; the clean code is in `skos:notation`, while the literal `dcterms:identifier` is prefixed
(`crit:SMAT01CE1.1`). Corpus and id order is alphabetical (BAC, ESO, INF, PRI), not pedagogical.

## 2. Owner decisions (closed)

1. **Hybrid architecture A.** Pure classes under `OERManager\Service\Curriculum`:
   - `CurModel`: the `cur:`/SKOS classes and properties; the dimension table (dimension → class →
     scoping edge(s) → qualifier edges); query builders (curriculum `item_set_id` + `resource_class_id` +
     `skos:changeNote nex` + `page`/`per_page`, text filter on `skos:prefLabel`); readers
     `label`/`definition`/`notation`/`isRetired`/`replacedBy`/`block`.
   - `StageOrder` (pedagogical order) and `LeafTree` (nested knowledge by `skos:broader`).
   - A small interface, `CurriculumTerms`, only for the new runtime lookups: curriculum item set
     resolution (cached per request), retired set, class ids, and later the tree.
   - Every consumer, old and new, builds its queries with the `CurModel` builders; tests assert against
     `CurModel::…Query(...)`, not literal arrays. The four copies of the ancestor edges
     (`CurricularPairs::COURSE_TERMS`, `CurriculumOutline::COURSE_EDGES`, `ItemPanelData::COURSE_EDGES`,
     `RecatalogService::QUALIFIER_TERMS`) move to `CurModel` as each slice touches the file.
2. **Dimensions.** Stage (navigation only) `cur:Etapa`; `lrmi:educationalLevel` → `cur:Curso` scoped by
   `cur:etapa`; `schema:about` → `cur:MateriaCurso` scoped by `cur:curso`; `lrmi:teaches` →
   `cur:SaberBasico` (any depth) and `lrmi:assesses` → `cur:CriterioEvaluacion`, scoped by
   `cur:materiaCurso`, falling back to `cur:curso` then `cur:etapa`. Multiple cardinality stays: one query
   per ancestor id, results merged by id.
3. **Configuration.** `oermanager_type_*` and `oermanager_educational_framework` go away and are deleted
   by the module upgrade. One setting remains: the curriculum item set, by id (ADR-0006), validated and
   auto-detected by the URI of its `dcterms:identifier` (`<base>id/curriculo`). The retired set is
   resolved at run time by its URI (`<base>id/curriculo/retirados`).
4. **Order.** Stages in the fixed list INF, PRI, ESO, BAC; unknown stages last, by notation. Courses by
   `skos:notation` within their stage.
5. **Retired terms.** Out of the cascade; the server refuses to link a new one; existing links are kept
   (the integrity rule is a later slice).
6. **Panel.** Knowledge and criteria as a collapsible tree per block once the course subject is chosen
   (slice 4, not in this delivery).
7. **Container verification** only with disposable test REA the script creates, marks and deletes (the
   `test/container/undo-harness.php` pattern). The 71 REA, the old graph and the curriculum items are not
   touched; no other catalog write.

## 3. Design

### 3.1 `CurModel` (pure)

Static, no core dependency beyond reading representations passed in.

- **Dimension table.** `classOf($dimension)`, `scopeEdges($dimension)` (context key → edge, in fallback
  order), `scopeFor($dimension, $context)` (first context key with ids → `[edge, ids]`),
  `parentEdge($dimension)` (lineage shown next to a result), `qualifierEdges($dimension)` (suffix of a
  qualified title). Qualifiers: knowledge and criteria are qualified by their `cur:materiaCurso`
  («Matemáticas · 1º ESO»); course and course subject labels are already unique («1º ESO»,
  «Matemáticas · 1º ESO»), so they carry no suffix. `COURSE_EDGE = cur:curso` is the single ancestor-course
  edge that replaces the four copies.
- **Query builders.** `termsQuery($schemeId, $classId, $text, $perPage, $page)` returns
  `item_set_id`, `resource_class_id`, a `skos:changeNote nex` row, an optional `skos:prefLabel in` row,
  `sort_by=title` and `page`/`per_page` (the core ignores `per_page` without `page`).
  `withResource($query, $edge, $id)` and `withLabel($query, $edge, $label)` add one scalar row each.
  `schemeQuery()` finds item sets whose identifier ends with `/id/curriculo`; `retiredSetQuery($uri)`
  finds `<uri>/retirados`; `classQuery($term)` resolves a resource class.
- **Readers.** `label` (`skos:prefLabel`, display title fallback), `definition`, `notation`,
  `isRetired` (any `skos:changeNote`), `replacedBy` (ids of `dcterms:isReplacedBy`), `block` (criterion:
  label of `cur:bloqueCompetencial`; knowledge: walk `skos:broader` up to the `cur:BloqueSaberes`, depth
  bounded), `ref($item, $edge)`, `refs`, `classTerm`, `schemeUri($itemSet)`.

### 3.2 `StageOrder` and `LeafTree` (pure)

`StageOrder::compare(a, b)` ranks INF, PRI, ESO, BAC, then unknown notations naturally;
`sortStages($rows)` and `sortCourses($rows)` (stage rank, then course notation). `LeafTree::build($nodes)`
nests rows by `parentId`, roots are rows whose parent is not in the set, children sorted by notation; a
cycle cannot loop (visited set) and an unreachable node is returned as a root rather than dropped.

### 3.3 `CurriculumTerms` (interface) and `ApiCurriculumTerms`

`schemeId()`: configured id if it is an item set whose `dcterms:identifier` URI ends with `/id/curriculo`,
otherwise the auto-detected one; memoised for the request. `isScheme($id)` and `detectSchemeId()` serve
the configuration page. `retiredSetId()` by URI. `classId($term)` memoised. Slice 4 adds the tree.

### 3.4 Slices

| Slice | Content | Files |
| --- | --- | --- |
| 1 | Pure model: `CurModel`, `StageOrder`, `LeafTree`, `CurriculumTerms` + `ApiCurriculumTerms` | `src/Service/Curriculum/*` |
| 2 | Configuration and upgrade: one setting (curriculum item set id), validated/auto-detected on save; `upgrade()` deletes the five legacy settings | `ConfigForm`, `ConfigPayload`, `IndexController::configAction`, `Module::upgrade`, config view if needed |
| 3 | `CurriculumSearch` on `CurModel` (cascade, `nex`, `prefLabel` filter, stage/course order, families = `cur:Materia`, leaves by `cur:materia`/`cur:curso`); `RecatalogService` `matchesDimension` (class + set), `qualifiedTitle` (`CurModel` qualifiers) and `invalidTargets` refusing new retired targets | `CurriculumSearch`, `RecatalogService`, factories |
| 4 | Tree endpoint (`LeafTree` over one MateriaCurso, ≤119 knowledge, ≤30 criteria) and `termPicker` tree UI | controller, `CurriculumTerms`, `asset/js` |
| 5 | Integrity rule «REA linked to a retired term» (with `dcterms:isReplacedBy`); `CurricularPairs`, `ItemPanelData`, `CurriculumOutline`, `CurriculumOrder`/`CurriculumMap` on `CurModel` | integrity, governance, stats |
| 6 | `TermResolverInterface`/`FakeTermResolver`/`CurricularClassifier`/`DeclaredAlignmentResolver`: lookups by `skos:notation` and `skos:prefLabel`, not `dcterms:title`; leaf text = `skos:definition` | `Service/Ai/*` |

Between slices 2 and 5 the stats outline still reads the deleted `dcterms:type` settings and shows no
curriculum; that is accepted (ADR-0022: OERManager does not work with the new model until TASK-064 is
done, and there is no production catalog).

## 4. Risks

- **Cost of the retired rule (slice 5).** The rule runs on every item save and per row in the master
  view. It must not read each linked term: resolve the retired set's member ids once per request
  (`item_set_id = retired set`, ids only) and compare the REA's linked ids against that set. Measure it
  on the container with the real set (0 retired in 1.1.2, so also with a synthetic id list) before
  closing slice 5.
- **Search cost.** Every query is bounded by the item set, the class and `per_page`; multi-ancestor
  context costs one query per ancestor, as today. `nex` adds one left join.
- **Label filter.** `in` on `skos:prefLabel` matches the truncated label, which starts with the notation:
  searching by code works, a phrase cut by the truncation does not. Acceptable for an autocomplete;
  the tree (slice 4) removes the need to search leaves by text.
- **Families by name.** `searchLeaves` still receives a subject name from the classifier. `cur:materia
  eq <name>` matches the Materia title, unique within a stage. Slice 6 can pass the Materia id instead.
- **Transitional state.** See §3.4: stats outline, pairs and panel keep the old edges until slice 5; the
  classifier keeps its title lookups until slice 6.

## 5. Test plan

- TDD on the host for every pure piece (`CurModelTest`, `StageOrderTest`, `LeafTreeTest`) and for the
  services through the existing API doubles (`ApiCurriculumTermsTest`, `CurriculumSearchIntegrationTest`,
  `RecatalogServiceTest`, `ConfigPayloadTest`, `IndexControllerTest`, `ModuleRuntimeTest`). Service tests
  compare the queries they send with `CurModel::…Query(...)` output.
- After each slice: `make lint`, `make test`, `make test-js`, coverage ≥ 90 % (`test/check-coverage.php`).
- Container (`test/container/cur-cascade-check.php`): read-only cascade on the real curriculum (stages in
  order, courses of a stage, course subjects of a course, knowledge/criteria of a course subject, `nex`
  exclusion, `prefLabel` filter) plus disposable REA created, marked and deleted by the script
  (rejection of a target of another class, a valid write, `nex` semantics on marked items).

## 6. Slice 5 decisions (2026-11-16)

Implemented on `feature/task-064-cur-model` (`f1e51b7`, `32a342f`, `a9e86d0`, `bcef474`, `bbb5184`).

### 6.1 Retired-term rule: where it runs and what it costs

- **Rule.** `IntegrityPolicy` emits `retired_term` (warning) for an alignment link (`lrmi:educationalLevel`,
  `schema:about`, `lrmi:teaches`, `lrmi:assesses`) whose target is in the retired set; the message names the
  term (`skos:prefLabel`) and its successors (`dcterms:isReplacedBy`), or says it has none. A dead link is
  not also reported as retired.
- **Ids, not terms.** `CurriculumTerms::retiredIds()` returns the retired set's member ids with one
  `returnScalar` query (`CurModel::retiredMembersQuery`), cached for the request. `IntegrityChecker`
  resolves it lazily (only once a row has an alignment target) and reads label and successors only for a
  term that is retired. Current terms are never read for this rule.
- **Where.** First committed behind `checkLinks` (D-7: save listener, drawer, publish gate). The container
  measurement then showed that D-7's cost premise does not apply here: `values()`, which the column already
  calls, loads the targets of resource values (25 REA, 85 resource values: 0 extra SQL for
  `valueResource()`). The rule therefore runs **everywhere `IntegrityChecker` runs**, including the
  Integrity column and the master view status filter, so the column count and the drawer agree.
  `dead_link` keeps its gate (it is unreachable by construction).
- **Measured** (`test/container/retired-term-check.php`, 8080, curriculum 1.1.2): real retired set 0 ids,
  resolution 7 SQL / ~9 ms; synthetic upper bound, the same scalar query over the whole curriculum set
  (15 438 ids), 1 SQL / ~18 ms, and 100 000 lookups against it ~1 ms; on a disposable REA with five
  alignment links, a cold check costs 0 SQL without the rule and 13 SQL / ~7 ms with it (the per-request
  resolution); a REA linked to a retired term costs 34 SQL / ~9 ms in the drawer (reading the retired
  term and its successor).

### 6.2 Pairs, panel and statistics

- `CurModel::COURSE_EDGE` (`cur:curso`) replaces `CurricularPairs::COURSE_TERMS`,
  `ItemPanelData::COURSE_EDGES` and `CurriculumOutline::COURSE_EDGES`. `CurModel::courseSubjectName()`
  drops the « · <course>» suffix of a `cur:MateriaCurso` label where the course is shown next to it (pairs
  of the Curricular column, statistics subjects); the panel keeps the full label.
- `CurriculumOutline` reads every current `cur:Curso` and `cur:MateriaCurso` with `CurModel::termsQuery`
  (bounded by `LEVEL_CAP`), stages by `cur:etapa`; linked terms outside the current curriculum (retired,
  legacy, axes, projects) still come in one batch by id. `CurriculumOrder` ranks stages with `StageOrder`
  and courses by `skos:notation`; nodes without notation follow in natural label order (the classifier's
  label-only calls keep working until slice 6).

### 6.3 Container harnesses

- New `cur-fixture.php` (shared, writes nothing by itself) and `retired-term-check.php`. The disposable
  retired term is a new private item in «Currículo · Retirados» with `skos:changeNote` and
  `dcterms:isReplacedBy`; no curriculum item is modified, and deleting it leaves the set as it was.
- `stats-check.php`, `columns-check.php`, `detail-panel-check.php`: `cur:` expectations; the checks that
  need aligned REA SKIP read-only (the 71 REA of the container carry no alignment links any more) and run
  with `--write` on disposable REA deleted at exit.
- `materia-multi-curso-check.php` is adapted (courses by notation, labels with their course), keeping the
  multi-course coverage. `collection-filter-check.php` is model-agnostic and passes unchanged.

### 6.4 Deviations

- `DeclaredAlignmentResolver` (slice 6) still resolves by `dcterms:title` on the ADR-0009 graph; its
  edges move from `CurricularPairs::COURSE_TERMS` into a private `LEGACY_COURSE_EDGES` until slice 6.
- `RecatalogService::QUALIFIER_TERMS` was already gone in slice 3; no copy of the ancestor edges remains
  outside `CurModel` and that legacy constant.

## 7. Slice 4 decisions (2026-11-16)

Implemented on `feature/task-064-cur-model` (`aa31206`, `79837a7`, `0e3ce9b`). Owner decision (closed): once
the course subject (`schema:about`, `cur:MateriaCurso`) is chosen, knowledge (`lrmi:teaches`) and criteria
(`lrmi:assesses`) are chosen with checkboxes in a collapsible tree per block.

### 7.1 Server

- `CurModel::treeShape($dimension)`: knowledge = `cur:BloqueSaberes` roots and `cur:SaberBasico` by
  `skos:broader` (any depth); criteria = `cur:BloqueCompetencial` roots and `cur:CriterioEvaluacion` by
  `cur:bloqueCompetencial`. `CurModel::courseSubjectQuery()` = `termsQuery` (set, class, `nex`, page) +
  `cur:materiaCurso res <id>`. `CurModel::refId()` reads a parent id without its title.
- `CurriculumTerms::courseSubjectTree($id)` validates the id (class `cur:MateriaCurso`, member of the
  curriculum set; anything else is null), runs one paged query per class (blocks first, so a leaf's parent is
  already loaded; 200 per page, at most 5 pages) and builds each tree with `LeafTree` from rows
  `{id, parentId, notation, title, definition, selectable}`. Blocks are roots and not selectable.
- `CurriculumTerms::retiredTerms($ids)`: the retired ids among the panel's linked knowledge/criteria (compared
  with `retiredIds()`), each with its label and the labels of `dcterms:isReplacedBy`. Only retired terms are read.
- Action `curriculum-tree` (GET, read only, no CSRF, ACL `editor`/`site_admin`/`reviewer` like `search-terms`):
  `about[]` (≤ 10 course subjects, one tree each; an id that fails validation comes back in `invalid`) and
  `linked[]` (≤ 500 ids, answered in `retired`). Several subjects in one request rather than one id per call,
  so a change of subjects is one request and the retired marks come with it.

### 7.2 Panel

- The chips stay the only selection. A checkbox adds or removes a chip; every chip change (picker, AI
  pre-fill, a chip's ×) fires `oer:chips-changed`, and the tree re-checks itself. Preview, apply, undo and the
  AI (`addChip`) are unchanged; the AI pre-fill gets its proposals checked in the tree for free.
- Knowledge and criteria lose their autocomplete; their chips stay as the summary of the selection. A chip the
  tree cannot show is kept and marked in text: «retirado» with the successor (same wording as the
  `retired_term` warning), or «fuera del árbol» (e.g. an AI proposal for another subject or a legacy link).
- Without a course subject the tree area asks for one and no tree is requested (NFR-004); retired marks are
  still requested when there are linked ids. The trees load when the editor opens and on each change of
  subjects, one request per change; a stale answer is dropped.
- Accessibility (NFR-006): nested lists of native, labelled checkboxes (`label for`), the full
  `skos:definition` as tooltip and `aria-describedby`, disclosure buttons with `aria-expanded` and
  `aria-controls` (blocks are buttons, not choices); Tab/Space native, ArrowRight/ArrowLeft open/close a branch.
  Branches with something selected open by themselves; each block shows how many are selected.
- i18n (NFR-005): the new strings go through `Omeka.jsTranslate()` and are registered in `js_translate_strings`
  with `// @translate`. The repository has no `.po` catalogue yet (`language/` is empty), so the English
  texts are pending with the rest of the module's catalogue.
- Pure core `asset/js/core/leafTreeModel.js` (node tests); DOM in `asset/js/ui/leafTree.js`.

### 7.3 Verification

- Host: `ApiCurriculumTermsTest` (tree, pages, page cap, validation, retired terms) and `CurModelTest` against
  `CurModel::courseSubjectQuery(...)`; `IndexControllerTest` (validation, caps, GET without CSRF);
  `ModuleRuntimeTest` (ACL); `test/js/leafTreeModel.test.js` (tree build, selection, retired, external ids,
  several subjects).
- Browser stub: `test/browser/leaf-tree-smoke.html` runs the real panel modules on a stub drawer in headless
  Chrome (30 checks: lazy load, checkboxes ↔ chips, AI chips, keyboard, two subjects, preview pairs, no chip lost).
- Container (`test/container/curriculum-tree-check.php`, 8080, curriculum 1.1.2): SMAT01 gives 82 knowledge
  items under 6 blocks (depth ≤ 2) and 18 criteria under 5 blocks, equal to independent queries, no retired
  term; validation, two subjects and ACL; cold cost ~45 ms / 158 SQL (one values load per node), warm
  ~19 ms / 9 SQL. With `--write`, a disposable REA got two depth-2 knowledge items through `RecatalogService`
  (preview + apply, read back) and a disposable retired term came back marked with its successor; both were
  deleted (71 REA and 0 test items afterwards).
- **Not verified:** the panel in the real admin with a logged-in session (render with the admin's styles,
  focus and keyboard with a screen reader, the AI pre-fill end to end). Same debt as TASK-030.

## 8. Text filter inside the trees (owner decision, 2026-11-17)

Implemented on `feature/task-064-cur-model` (`03d9e3a`). Slice 4 removed the autocomplete of knowledge and
criteria; the owner asked for a text filter inside each tree instead.

- **Where and what.** One labelled `type="search"` input per dimension («Filtrar: Saberes básicos»,
  «Filtrar: Criterios de evaluación»), above its trees and shown only when a tree has nodes. It filters the
  trees already loaded (every chosen course subject at once): no request.
- **Match.** Case- and accent-insensitive (`NFD` without combining marks, so «angulos» finds «Ángulos»;
  «ñ» also matches «n»), whitespace collapsed; every word of the filter must appear in the node's
  `skos:notation`, label (`skos:prefLabel`) or `skos:definition`. Blocks match by their notation and label too.
- **What stays visible.** Matches; their ancestors (block and parent knowledge), opened to keep the context;
  the descendants of a match, closed (they belong to what was found, e.g. a matching block). Everything else
  is hidden, and a course subject with nothing visible is hidden too. The first keystroke remembers which
  branches were open; an empty filter restores them. A tree redrawn while a filter is active (change of
  subjects) is filtered again from its fresh open/closed state.
- **Selection.** Hiding never unchecks: chips stay the selection (§7.2) and block counters still count
  hidden checked boxes.
- **Count.** A `role="status"`/`aria-live="polite"` line: «1 coincidencia», «N coincidencias» or «Ningún
  término coincide con el filtro.» (count of matching nodes, blocks included). Enter in the input does not
  submit the surrounding form.
- **i18n.** `Filtrar:`, the three status texts, registered in `js_translate_strings` with `// @translate`.
- **Code.** Pure `normalizeFilterText`, `filterTrees`, `filterStatus` in `asset/js/core/leafTreeModel.js`
  (node tests); DOM in `asset/js/ui/leafTree.js` (`applyFilter`); styles in `oer-master-view.css`.
- **Verified.** `test/js/leafTreeModel.test.js` (7 new tests) and `test/browser/leaf-tree-smoke.html`
  (48 checks, 18 new: label, live region, ancestors opened, definition/case/accents, no match, criteria tree
  untouched, restore on clear, no request, selection intact, re-filter after a change of subjects, subject
  without matches hidden). **Not verified:** the filter in the real admin with a screen reader (same debt as §7.3).
