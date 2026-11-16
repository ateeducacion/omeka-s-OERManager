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
