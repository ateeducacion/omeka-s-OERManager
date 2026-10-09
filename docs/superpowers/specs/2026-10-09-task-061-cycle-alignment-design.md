# TASK-061 — The AI cataloguer aligns a REA to the courses of its cycle

Owner decisions of 2026-10-08: in Primaria a REA is aligned to the courses of its cycle (1º–2º, 3º–4º,
5º–6º), and knowledge and criteria of either course are valid; Infantil follows the same rule with two
cycles of three courses (1º–3º, 4º–6º). ESO and Bachillerato have no cycle and are unchanged.
`CurriculumCycle` already gives the cycle of a course title and the cycle key of a leaf.

## Starting point (2026-10-09, after TASK-063)

The evaluation set reaches the declared cycle in 152 of 156 runs (Primaria 81/81, Infantil 25/27), but the
proposal carries the single course of the leaves it chose, so the curator still has to add the sibling
course and its subject by hand.

## Design

1. **Course and subject are completed in the derivation (Phase D)**, not in the delimitation. After the
   courses are derived from the anchor leaves and the subjects from the proposed leaves, every derived course
   whose title has a cycle (`CurriculumCycle::courseKey`) adds the other courses of that cycle **in which the
   same subject exists**, and every derived subject adds the same-name subject of those courses. A sibling
   course without that subject is not added: the proposal stays a coherent subgraph (ADR-0010), every course
   has its subject.
2. **Where the sibling ids come from.** `CurriculumSearch::searchSubjectFamilies` already reads every subject
   item of the stage and the course of each; each course entry of a family now also carries the id of its
   subject item (`subjectId`, the first item when a course has several). No new query.
3. **Leaves are not duplicated.** The cycle twin of a chosen knowledge item or criterion (`PMAT03CE4.1` ↔
   `PMAT04CE4.1`) is not added: by the owner's rule either is valid, and adding both would double what the
   curator reviews. The cycle reading of the evaluation set already counts a twin as a hit.
4. **Delimitation is unchanged.** The plausible-courses step still bounds the leaves; widening it to whole
   cycles would add Jev questions for no gain in reach (95 % of the declared knowledge already reaches Jev).
5. **Evaluation.** Course is already scored in cycle. Subject gains a cycle reading: a proposed subject matches
   a declared one when it has the same name and its course is in the same cycle; strict scores are kept.

## Tests (host)

- `CurriculumSearchIntegrationTest`: family courses carry the subject item id.
- `CurricularClassifierTest`: a Primaria leaf of 3º adds 4º and its same-name subject; an Infantil leaf adds
  the other two courses of its cycle; a sibling course without the subject is not added; ESO is unchanged.

## Measurement

The evaluation set (52 REA × 3) against the TASK-063 run: course and subject in cycle must not regress,
leaves unchanged within noise, and strict course precision is expected to fall in Primaria/Infantil by
construction (two or three courses instead of one).
