# TASK-047 — Statistics as a decision dashboard (design)

> **Status:** in progress (2026-10-04). Slice §5 under implementation; the other recommended views are
> pending tasks (§4). Requirement: RF-019 (`propuesto`). Builds on TASK-006
> ([spec](2026-08-27-task-006-estadisticas-design.md)), ADR-0004, ADR-0009, ADR-0014, NFR-004, NFR-006.

## 1. The owner's request and success criteria

Owner, 2026-10-04 (Spanish, paraphrased faithfully):

> Improve the statistics view. Make proposals bearing in mind the volume of REA to manage (thousands,
> ~3000+) and that the statistics must work as a **dashboard for deciding where to invest in producing or
> buying REA**. Put the **heatmap first**, tune the font sizes to improve the design, and add the **year of
> creation as a filter**. Improve the per-dimension counts: in **Etapa**, group by stage and order by
> stage/level, not by number of REA; in **Materia**, group and allow filtering by stage/course so that
> subjects with the same name are never shown without knowing which course/stage they belong to. Use the
> screen space of that area better. Assess whether another dashboard view makes sense. Create the task,
> write the spec and start the implementation.

Success criteria (they become the RF-019 acceptance criteria):

1. The first thing on the page is the curricular coverage heatmap (subject × course), readable with
   ~130 rows and ~18 columns: text ≥ 12 px, every number printed as text, WCAG AA contrast on every cell.
2. The heatmap tells apart *this subject does not exist in this course* from *it exists and has 0 REA*
   — the second one is the investment signal.
3. A creation-year filter narrows the heatmap, the counts, the completeness bar, the free cross and
   **every CSV export** to the same set of REA.
4. «Etapa» is grouped by stage and ordered by stage then course level, never by count.
5. «Materia» is grouped by stage → course and can be filtered by stage and course; a subject name never
   appears without its course. The CSV follows the filter.
6. The dimension-count area is a compact multi-column grid.
7. The aggregation covers a ~3000-REA catalogue without truncating, with a query count that does not
   grow with the curriculum in a per-REA loop.
8. Read-only: nothing is written to the catalogue or to the curriculum.

## 2. Current state (measured 2026-10-04, by reading the code and the container catalogue)

**Code.** `StatsController` (`index`/`export`) → `CatalogSnapshot::fetch()` → `DimensionFacts::extract()`
→ pure `DimensionCounter`/`DimensionCrosser`/`CompletenessAggregator`/`CsvExport` (`src/Service/Stats/`;
`src/Stats/` is an empty placeholder). The page embeds one JSON and `asset/js/statsMain.js` +
`asset/js/stats/charts.js` paint three blocks, in this order: per-dimension counts, 2-dimension cross,
completeness.

Measured defects and limits:

| # | Finding | Where |
| --- | --- | --- |
| C1 | **Truncation at 2000 REA.** `CatalogSnapshot` reads one page of `ComputedFilter::HARD_CAP` = 2000. At the owner's 3000+ REA, every number on the page is computed on two thirds of the catalogue (the page does warn). | `CatalogSnapshot::fetch()` |
| C2 | **Same-named subjects are merged.** `relabelCrossTable()` sums cells by *label*, so «Educación física» of 1º ESO and of 3º ESO become one row; the Materia count lists one bar per subject item, so «Educación física» appears up to 11 times with nothing to tell them apart. The curriculum has 230 subject items with 126 distinct titles (ADR-0009: one subject item per course). | `StatsController::relabelCounts/relabelCrossTable` |
| C3 | **«Etapa» is really the course** (`lrmi:educationalLevel` → Curso, ADR-0009 §5) and it is sorted by count. There is no stage level at all. | same |
| C4 | **The heatmap is third**, behind five bar charts, and defaults to Materia × Etapa, which with per-course subject items is a near-diagonal matrix. | `index.phtml` |
| C5 | **Font size drifts with the card width.** Bars are SVG with a fixed `viewBox` of 480 px and `font-size: 0.8rem` inside it, so the text scales with the card: ~10 px on a narrow card, ~16 px on a wide one. | `charts.js renderBarChart` |
| C6 | **The heatmap paints in the «ok» green** (`rgba(29,122,95,α)` = `--oer-ok`) with alpha from 0 to 1; dark cells put ink on a dark green below AA, and ADR-0014 reserves the status triad for status. | `charts.js renderCrossTable` |
| C7 | No date filter of any kind; no caching of the aggregation (the TASK-006 spec §8 left it out). | — |

**Data** (container catalogue, read-only probe, 2026-10-04): 20 `lrmi:LearningResource`. Coverage of the
properties a dashboard could use: `schema:about` and `lrmi:educationalLevel` 18/20, `lrmi:learningResourceType`
18/20, `dcterms:relation` 19/20, `dcterms:license` 6/20, `schema:isPartOf` 5/20, `curation:status` 2/20,
`dcterms:creator` 1/20, `dcterms:created`/`dcterms:date`/`dcterms:issued` **0/20**. All 20 have the native
`o:created` (all in 2026). Curriculum: 4 stages with `schema:position` (Infantil 1, Primaria 2, ESO 3,
Bachillerato 4); 18 courses with **no** `schema:position`, title `«Nº <stage>»` and identifier
`curso:<stage>:<title>`; 230 subjects, all with `lrmi:educationalLevel` → course.

## 3. Decisions

| # | Question | Decision | Source |
| --- | --- | --- | --- |
| D1 | Page order | Year filter bar → **coverage heatmap** → compact grid of counts (Etapa/curso, Materia, Eje, Proyecto, Licencia, Completitud) → free 2-dimension cross. | owner |
| D2 | What «the heatmap» is | A fixed **coverage matrix**: rows = subject *names* (normalised like `SubjectTint`, so «Educación física» is one row), columns = courses grouped under their stage, cell = number of distinct REA linked (`schema:about`) to the subject item **of that course**. The course comes from the subject item's own edge (ADR-0009), not from the REA's `lrmi:educationalLevel`, so a REA never lands in a course its subject does not belong to. The free cross (any pair of dimensions) stays, last on the page. | C2, C4, ADR-0009 |
| D3 | Gaps vs. «not applicable» | The row universe is **every subject item of the curriculum** (`dcterms:type` = the configured subject type), not only the subjects some REA uses. A cell is **n/a** (`·`, muted, grey surface) when no subject of that name exists in that course, **0** (printed «0», dotted border, white) when it exists and has no REA, otherwise the count on a heat level. Distinguishing the two needs no colour (text and border, ADR-0014 rule 3). If the type setting is empty, the universe falls back to the subjects REA use and the page says gaps are not shown. | owner: invest decision; ADR-0014 |
| D4 | Heat scale | Five single-hue blue levels `--oer-heat-1..5` (hue ≈ 210°, away from the red accent and the amber warning), declared once in `oer-master-view.css`. Level = `ceil(5 · ln(1+n) / ln(1+max))`, logarithmic so a few large cells do not flatten the rest at 3000+ REA. Levels 1–3 carry the ink, 4–5 carry white; both measured ≥ 4.5:1 in `contrast.test.js`. The number is always printed, so the colour is redundant (ADR-0014 rule 3, NFR-006). The free cross uses the same scale (C6). | C6, NFR-006 |
| D5 | Font sizes | HTML, not SVG, for every chart (fixes C5): table and list text `max(12px, 0.8125rem)`; course headers vertical (`writing-mode: vertical-rl`) so 18 columns fit without abbreviating titles; first column and header row sticky inside a scroll box of at most `70vh`. | C5, owner |
| D6 | Creation-year source | The native **`o:created`** year of the item. It exists on every item, costs no query and needs no new property. `dcterms:created`/`dcterms:date` are on 0/20 REA, so a preference for them would be dead code today. **Caveat stated on the page and in the CSV filename:** `o:created` is the date the REA entered the catalogue, not the date it was produced; for a catalogue loaded in bulk it reflects the load. If the owner wants the production date, it needs RF-015 data first (see open question Q2). | measured §2 |
| D7 | Year-filter scope | Server-side `?year=YYYY` on `index` and `export`. One pure filter over the per-REA facts feeds every block and every CSV, so the numbers cannot diverge. The choice list shows each year with its REA count, from the unfiltered catalogue. Works without JS (GET form); JS submits on change. | owner, criterion 3 |
| D8 | Stage and course order | Stages by `schema:position` (present on all 4), then title. Courses by stage, then the **leading ordinal of the title** (`^\d+`: «1º ESO» → 1), then natural title order, then id. The curriculum has no explicit course order (no `schema:position` on courses); this is the least-bad deterministic rule and it gives the right order on all 18 real courses. **Owner to confirm, or add `schema:position` to the course items** (curriculum data is owned outside this module; the module would then prefer it). Terms that are not a known course go to a last group «Sin etapa». | measured §2, Q1 |
| D9 | «Etapa» card | Groups = stages in D8 order; each shows its **distinct** REA total (a REA in 1º and 2º ESO counts once for ESO) and its courses in level order with their counts, including courses with 0 REA. Card title «Etapa y curso» (the dimension really is the course, C3). CSV: `Etapa, Curso, conteo`. | owner |
| D10 | «Materia» card | Groups = stage → course → subjects; within a course, subjects by count (it is a ranking), ties by name. Two selects, stage and course (course options follow the stage), filter on the client over data already in the page; the CSV link carries `stage`/`course` and the server applies the same pure filter. CSV: `Etapa, Curso, Materia, conteo`. A subject whose course is unknown goes to «Sin curso». | owner, criterion 5 |
| D11 | Compact layout | Cards in `grid-template-columns: repeat(auto-fill, minmax(18rem, 1fr))`; Materia spans two rows; long cards scroll inside (`max-height: 26rem`). Bars are thin HTML bars next to the number. Eje/Proyecto/Licencia keep count order. Completeness becomes a card of the grid. | owner |
| D12 | Free cross labels | Subjects are qualified with their course («Educación física · 1º ESO»), courses follow D8, other dimensions are in natural label order. Fixes C2 without dropping the generic selector of TASK-006. | C2 |
| D13 | Catalogue reading | `CatalogSnapshot` pages through the REA in chunks of 500 up to `STATS_CAP` = 10000, instead of one page of 2000. The facts (a few ints per REA) and the integrity status are extracted per page. Truncation is still announced (ADR-0013) past 10000. | C1, criterion 7 |
| D14 | Integrity status | Computed only when a block needs it (page, completeness CSV), as today; it is carried in the facts so the year filter applies to completeness too. | TASK-006 |

## 4. Dashboard proposals at 3000+ REA

The question the dashboard serves: *where is it worth producing or buying REA next?* Each candidate was
checked against the RDF that exists today (§2).

| # | View | Decision it supports | Data needed / exists today | Cost at 3000+ REA | Verdict |
| --- | --- | --- | --- | --- | --- |
| P1 | **Coverage heatmap** subject × course, gaps vs. n/a (D2–D3) | Where coverage is thin or absent, at a glance | `schema:about` (18/20) + subject→course edge (230/230) + curriculum subject list | Linear in facts; curriculum read once (≈ 250 items) | **Build now** (TASK-047) |
| P2 | **Gap ranking**: the curricular cells (subject × course) with fewest REA, zero first, as a sortable list with CSV | The ordered shopping list behind P1 | Same as P1. «Relative to expectation» needs an expected volume per cell (teaching hours, enrolment, priority) that **does not exist in RDF**; without it the honest ranking is absolute count within stage order | Same as P1, no extra query | **Recommend, defer** → TASK-048. Weighting needs an owner input (Q3) |
| P3 | **Production trend** by creation year × stage | Is production keeping pace, and where | `o:created` (20/20) — but it is the catalogue entry date (D6) and today all REA share one year | Linear, no query | **Recommend, defer** → TASK-049; meaningful only after a second year of data |
| P4 | **Integrity backlog by stage/course**: ok/warning/error per course | Whether to invest in curating what exists before buying more | Integrity status (already computed) + course | Linear, no extra query | **Recommend, defer** → TASK-050 |
| P5 | **Resource-type mix by stage** (`lrmi:learningResourceType`) | Which formats are missing per stage (e.g. no interactive REA in Primaria) | 18/20 REA have it | Linear + one batch for type labels | **Recommend, defer** → TASK-051 |
| P6 | Licence / openness mix | Reuse rights of what is bought | `dcterms:license` on 6/20 | Linear | **Reject as a new view**: the Licencia card already shows it; the missing licences are an integrity issue, covered by P4 |
| P7 | Proposed-but-unpublished backlog (RF-016) | Curator workload | `curation:status` on 2/20 | Linear | **Reject for this dashboard**: an operational queue, already served by the «Propuestos» filter and badge (TASK-044); not an investment signal |
| P8 | Concentration on few authors | Supplier dependence | `dcterms:creator` on 1/20 (RF-015: authorship data still missing) | Linear | **Reject now**: the data cannot support it honestly; revisit when RF-015 data exists |
| P9 | **Scale measurement** of the page with a disposable 3000-REA fixture, and caching only if the measurement asks for it | That the dashboard answers in acceptable time | — | — | **Recommend** → TASK-052 (§6 explains why the cost is bounded by design but not measured) |

## 5. The slice built in TASK-047

### 5.1 Server (`src/Service/Stats/`)

Pure, tested on the host:

- `CurriculumOrder` — stage and course comparators of D8 (`ordinal()`, `sortStages()`, `sortCourses()`).
- `CurriculumMap` — value object: stages (label, position), courses (label, stage), subjects (label,
  course), labels of every other linked term; `orderedStageIds()`, `orderedCourseIds()`, `courseOfSubject()`,
  `stageOfCourse()`, `label()`, `qualifiedSubjectLabel()`.
- `YearFilter` — `years(facts)` (year ⇒ REA count, newest first) and `apply(facts, ?year)`.
- `StageCounts` — groups of D9. `SubjectCounts` — groups of D10, `filter()` and `toRows()` for the CSV.
- `CoverageMatrix` — the matrix of D2/D3, `filterStage()` and `toRows()` for the CSV.

Core adapters, verified in the container:

- `DimensionFacts::extract()` gains `year` (from `o:created`).
- `CatalogSnapshot::walk()` pages the catalogue (D13); `fetch()` stays for the harness.
- `CurriculumOutline::load(linkedIds)` builds the `CurriculumMap` with **three** searches whatever the REA
  count: the linked terms by id (the existing batch), all courses and all subjects by `dcterms:type`
  (settings `oermanager_type_educationallevel`/`oermanager_type_about`); stages are read through the
  courses' `schema:inDefinedTermSet` (4 items). It never reads saberes or criterios: NFR-004 forbids
  loading the curriculum tree for the re-catalogador selector, and this reads only the two upper levels
  (≈ 250 items), once per request.

`StatsController` composes them; `export` accepts `year`, `type=coverage&stage=`, `dimension=etapa`,
`dimension=materia&stage=&course=`, the old `dimension=` and `dimension1/dimension2`, and
`type=completeness`. The filename carries the year (`oer-materia-2026.csv`).

### 5.2 Client

- `asset/js/stats/heatmap.js` — pure: `heatLevel()`, `visibleCoverage(coverage, stageId)` (drops the
  columns of other stages and the rows that become all n/a), `renderHeatmap()`.
- `asset/js/stats/groupedCounts.js` — pure: `renderStageCounts()`, `filterSubjectGroups()`,
  `courseOptions()`, `renderSubjectCounts()`, `renderBarList()`.
- `asset/js/stats/charts.js` — `renderCrossTable()` on the heat scale; `renderCompletenessBar()` kept;
  `renderBarChart()` (SVG) replaced by `renderBarList()`.
- `asset/js/statsMain.js` — wiring only: year select submits, stage/course selects repaint and rewrite
  the CSV links.

## 6. Performance (NFR-004, criterion 7)

Per page view, with *N* REA and the curriculum's ≈ 250 upper-level terms:

- REA reading: ⌈N/500⌉ searches (7 at 3000). Omeka loads each item's values lazily, so reading
  representations costs about one small values query per REA — **inherent to representations and
  unchanged from TASK-006 and the master view's computed branch**. Linked curriculum terms hydrate once
  each (bounded by the curriculum, not by N).
- Curriculum: 3 searches + ≤ 4 stage reads, independent of N.
- Aggregation: linear in the facts (a few ints per REA); the coverage matrix is rows × columns
  (≈ 126 × 18 today). The embedded JSON grows with the curriculum, not with N.
- Integrity: one in-memory `IntegrityChecker::check($item, false)` per REA, as in TASK-006.
- Memory: Doctrine keeps the loaded entities for the request; paging bounds the PHP arrays, not the
  identity map. The container's `memory_limit` is 512M.

**Honesty note:** the container catalogue holds 20 REA, so the scale is **bounded by design, not
measured**. TASK-052 measures it with a disposable fixture and decides on caching (none today, C7) or a
direct aggregate query only if the measurement asks for it.

## 7. Test plan

- PHPUnit (host): `CurriculumOrderTest`, `CurriculumMapTest`, `YearFilterTest`, `StageCountsTest`,
  `SubjectCountsTest`, `CoverageMatrixTest`; `StatsControllerTest` extended (year filter reaches the page
  and every CSV, coverage/etapa/materia CSV shapes, invalid parameters).
- `node --test`: `test/js/stats/heatmap.test.js`, `test/js/stats/groupedCounts.test.js`,
  `charts.test.js` updated; `contrast.test.js` measures the heat levels (ink on 1–3, white on 4–5, no red
  or amber hue).
- Container, read-only: `test/container/stats-check.php` extended — stage/course order on the real
  curriculum, a same-named subject split by course, coverage gaps vs. n/a, the year filter keeps totals
  consistent, CSV of materia/coverage under a filter, no truncation.
- Visual: headless Chrome screenshot of a static page built from the real CSS and the real page JSON.
  **Not** a logged-in admin check; that remains pending.

## 8. Open questions for the owner

- **Q1 — course order source.** No course carries `schema:position`; D8 orders by the leading number of
  the title. Confirm, or add `schema:position` to the 18 course items (then the module prefers it).
- **Q2 — creation date.** D6 uses `o:created` (catalogue entry). If «year of creation» must mean the
  production date of the resource, it needs `dcterms:created` filled on the REA (RF-015).
- **Q3 — expectation for the gap ranking (TASK-048).** Is there a source of expected volume per subject ×
  course (hours, enrolment, priority list)? Without it the ranking is by absolute count.

## 9. Out of scope

PDF export, drill-down from a cell to the master view (TASK-006 §8 still applies), caching (TASK-052),
dark mode, and the views of §4 marked defer or reject.
