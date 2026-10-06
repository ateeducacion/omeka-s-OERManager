# TASK-056 — Curricular classifier: candidate starvation and ambiguous labels (design)

Approved in conversation with the owner on 2026-10-06, section by section (approach B). Evidence:
[analysis of four eXeLearning REA](2026-10-06-ai-cataloguer-elpx-analysis.md) §3 and the TASK-057 baseline.
Refines [ADR-0010](../../decisions/0010-anclaje-bottom-up.md) (an addendum is part of this task); does not
change the RDF mapping (ADR-0004) nor the graph (ADR-0009).

## 1. Problem and success criteria

On the TASK-057 evaluation set the classifier hits **0/57** declared knowledge/criteria leaves and the course
F1 is **0.00** in 12 of 12 runs (3 runs × 4 REA, `main`, 2026-10-06), although the distilled summary quotes
the level verbatim in 4/4. The right answer is unreachable whatever the model:

1. `gatherLeaves()` walks stages × subjects in order and **returns at `LEAF_CAP` (200)**; the extra stages the
   inclusive stage step adds fill the cap first (Geometría canaria: Matemáticas chosen, 0 Matemáticas and
   0 4º Primaria candidates).
2. Subject candidates are names **without course**, deduplicated by name («Tecnología» 4º ESO lost to
   «Tecnología e Ingeniería I» and «Tecnología y Digitalización»).
3. Block candidates carry **no subject or course** and are deduplicated by title only.
4. The course is never asked; it is derived from the leaves, so wrong candidates cannot recover.

**Success:** on the evaluation set, with the same extractor before and after, course F1 > 0 and declared
leaves hit > 0 in at least 3 of the 4 REA; no failed run attributable to the code (provider timeouts are
counted apart); no regression in the existing tests. No higher numeric target is set on a 4-REA sample.

## 2. Decisions

| # | Question | Decision |
| --- | --- | --- |
| D1 | Approach | **B**: a new «plausible courses» step after the subject; the course is still derived from the leaves (ADR-0010 coherence by construction). Rejected: A (fix without a course step: candidate lists stay long) and C (regex course detection: fragile, overlaps TASK-055) |
| D2 | Stage step (A.1) | The guidance stops asking for unconditional inclusiveness: if the content quotes the stage, choose it; be inclusive only when the stage is not stated |
| D3 | Subject step (A.2) | Candidates carry their courses, ordered by `CurriculumOrder::sortCourses()` and collapsed into ranges: «Tecnología (4º ESO)», «Tecnología y Digitalización (1º–3º ESO)» |
| D4 | Course step (A.3, new) | Candidates: the courses of the chosen subjects (multi-select). Guidance: prefer the course the content quotes; add a neighbour course only on doubt. **Fallback**: no course chosen → every course of those subjects (today's behaviour) |
| D5 | Leaves (B) | Gathered per chosen (subject, course) pair. If they still exceed `LEAF_CAP`, the cap is **split fairly** across the pairs instead of cut in order, and the trace records that it cut |
| D6 | Blocks (B, prefilter) | Labelled «[subject · course] block», deduplicated by (subject, block), never by title alone |
| D7 | Criteria (C) and derivation (D) | Unchanged: criteria bounded to the courses of the chosen knowledge; course and subject derived from the chosen leaves |
| D8 | Thematic axes | **Out of this task** (the evaluation set cannot score them: packages declare no axes). New pending task TASK-058 |
| D9 | Cost | One more, small LLM call per proposal. Accepted under NFR-008 (accuracy prevails over tokens) |

## 3. Data and interfaces

- `CurriculumSearch::searchSubjectFamilies()` returns, per subject name, its courses (`id`, `title`), read
  from each subject item's course edge (`CurricularPairs::COURSE_TERMS`) in the query it already runs: no
  extra query.
- `CurriculumSearch::searchLeaves()` takes an optional list of course ids and filters by them in the query
  (`lrmi:educationalAlignment`), one query per course because the Omeka adapter cannot group AND/OR
  conditions. This also removes the risk of the per-subject page limit (`ENUM_LIMIT` = 300) cutting courses
  sorted last.
- `TermResolverInterface` (implemented by `CurriculumTermResolver` and the test `FakeTermResolver`):
  `listSubjectFamilies()` returns `{name, courses}`; `listLeaves(..., array $courseIds = [])` gains an
  optional parameter, so existing callers keep working.
- `CurricularClassifier`: step A.3, per-pair gathering with fair cap and trace, labelled blocks, new stage
  guidance. Its public contract (`classify(ItemContext)`) does not change, so `AiCataloguer`, the proposal
  store and the review UI are untouched.

## 4. Measurement

The evaluation set (`test/container/evaluation-set.php --runs=3`) runs before and after on a **local,
unpublished** combination of this branch with the TASK-053 extractor branch, so the classifier sees the
`.elpx` content; same extractor both times, so the difference is the classifier's. Results go to this spec
and to the TASK-056 backlog row (F1 per dimension, declared leaves hit, failed runs).

## 5. Tests (host, no real LLM)

With `FakeLlmClient` and `FakeTermResolver`: fair cap (no pair left without candidates), the course step
bounds the leaves, fallback with no course chosen, subject and block labels, block dedup by (subject, block),
stage guidance present. `CurriculumSearch` with a mocked API: courses per family, course filter in
`searchLeaves`. Then `make lint`, `make test`, `make test-js`.

## 6. Governance

ADR-0010 addendum (TASK-056, 2026-10-06), append-only: the inclusive stage bias was not harmless (with the cap
it displaced the right candidates, 0/57); the plausible-courses step bounds the search without fixing the
course; fair split of the cap. Backlog: TASK-056 row with the measured result, new TASK-058 for the axes;
traceability and project memory on closing.
