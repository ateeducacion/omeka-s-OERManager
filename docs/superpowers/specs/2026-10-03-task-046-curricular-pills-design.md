# TASK-046 — Curricular anchor column as compound pills (design)

Short spec for a bounded change to an existing column. Implements the owner's choice of **option B** of the
TASK-046 design proposal, recorded as the 2026-10-03 addendum of
[ADR-0014](../../decisions/0014-lenguaje-visual-vista-maestra.md). The addendum left three things to this
spec: the tint palette, the stable subject→tint assignment, and how a long subject wraps.

## 1. Decisions

| # | Question | Decision | Source |
| --- | --- | --- | --- |
| D1 | Shape | One compound pill per subject: the subject in its tint, its abbreviated courses in the white half of the same pill. `Curricular::renderContent` and `oer-master-view.css` only | ADR-0014 addendum, owner option B |
| D2 | Palette | Eight soft tints, `--oer-tint-1..8` (pastel fill) plus `--oer-tint-N-edge` (border), hues 210, 172, 140, 265, 300, 192, 235, 85. None near red (≈0°) or amber (≈35°) | ADR-0014 addendum rule 1 |
| D3 | Assignment | `SubjectTint::indexFor($subjectName)`: `crc32` of the **normalised subject name** (lower case, no diacritics, collapsed whitespace) modulo 8. Stable across rows, sessions and installs; collisions between subjects are allowed | ADR-0014 addendum rule 2; owner answer: hash with 8 tints |
| D4 | Why the name, not the term id | A subject item carries its own course (ADR-0009), so the same subject exists as one item per course and the cell already groups by title. Measured in the container catalogue: 230 subject items, 126 distinct titles, 24 titles on more than one id («Educación física» on 11). Hashing the id would paint one subject in 11 colours down a column | measured 2026-10-03 |
| D5 | Literal subject | A subject typed as a literal is a state (⚠ literal), not a linked term: pill without tint (`oer-tint-none`), italic. The glyph stays outside the pill | ADR-0014 addendum rule 3 |
| D6 | States | ⚠ incomplete, ✗ unanchored, ⚠ literal and «Sin materia» orphan courses are unchanged: shape-coded, outside the pills, orphan line keeps the warning ink and is not a pill. A correct anchor still adds no mark | ADR-0014 addendum rules 3–4 |
| D7 | Wrapping | The pill is as wide as its content up to the cell. Inside, the subject may shrink down to its longest word and wraps by words; the courses half shrinks and wraps first. A single word longer than the cell breaks (`overflow-wrap: break-word`). Nothing is truncated or clipped | checked in a headless browser on real cell HTML (item 4362) |
| D8 | Contrast | The module ink on each of the eight tints ≥ 4.5:1; courses text is `--oer-muted` on white, already measured. Both in `test/js/contrast.test.js`, which also rejects a tint with a red or amber hue | NFR-006, ADR-0014 pending obligation |

## 2. Out of scope

Dark mode (the module stylesheet has none), a configurable palette, and colouring any other column.

## 3. Checks

`SubjectTintTest` (range, stability, normalisation, spread over twelve real subjects), a `ColumnsTest` case
(pill class and tint, literal untinted and flagged, orphan courses not a pill), `contrast.test.js`, and the
existing `columns-check.php` in the container (pairs appear exactly where there is an anchor).
