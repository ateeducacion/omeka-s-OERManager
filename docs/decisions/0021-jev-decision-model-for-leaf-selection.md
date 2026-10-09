# ADR-0021: Jev as the fine selector of knowledge and criteria

## Estado

Aceptado (2026-10-09), by the owner, after TASK-062.

## Contexto

The AI cataloguer (ADR-0010) delimits stage, subject and plausible courses with the LLM and then
asks the LLM to pick the basic knowledge and evaluation criteria among the gathered curriculum
leaves. The TASK-059 baseline (52 REA × 3, 2026-10-08) showed that this fine selection was the
largest loss: 36 % of the declared leaves were shown to the model and not chosen, with micro
precision 0.07 for knowledge and 0.03 for criteria.

TASK-062 evaluated `typesafe/jev-1.13`, a decision model reached through OpenRouter's
`/api/alpha/decisions` endpoint, as a replacement for that selection only: one yes/no (`noul`)
question per candidate, answered with P(yes). The owner accepted the data policy of OpenRouter
and TypeSafe for catalogue content on 2026-10-08. Every figure below comes from the evaluation
set (`test/container/evaluation-set.php`) on the same 52 REA with 3 runs each; reports are kept
outside the repository.

What the measurements established:

1. **API cost does not change with the decision rule.** Every Jev variant costs about $0.0044 per
   REA, against $0.0062 for the baseline, so the trade-off to optimise is recall against the
   curator's review load, not F1 against cost. A missed leaf costs the curator a search among
   7 884 knowledge items; a wrong one costs a rejection. Pure recall is not the target either:
   at P ≥ 0.3 it reaches 0.66 with 58 leaves to review per REA.
2. **P(yes) ranks well within one REA but is not calibrated.** Over the shown candidates, P in
   0.6–0.7 is right 7 % of the time, 0.8–0.9 30 %, 0.9–1.0 60 %. A fixed threshold therefore
   trades badly: at 0.8, 12 % of the proposals had no leaf and the course in the declared cycle
   fell from 135 to 123 of 156, because the course is derived from the leaves.
3. **Proposing the top K of each dimension beats every fixed threshold** on the whole
   recall/review-load frontier, in an offline sweep over the stored probabilities (scorer checked
   to reproduce every stored summary) and in a real run.
4. **Knowledge recall was capped by delimitation, not by Jev.** The block prefilter and the
   200-leaf cap left 40 % of the declared knowledge out of Jev's reach. Asking Jev about every
   gathered item up to 400 (two requests of 200) brings 79 % of it within reach.

## Alternativas consideradas

- **A. Keep the LLM selection.** Dominated by every Jev variant on precision, recall, cost and latency.
- **B. Jev with a fixed threshold (0.6 or 0.8).** 0.6 proposes 27 leaves per REA at precision 0.11;
  0.8 meets the precision targets but loses the course in Primaria and leaves 12 % of the
  proposals empty.
- **C. Jev with a fixed threshold for leaves and a lower one for the course.** Fixes the course,
  keeps the empty proposals and the poor calibration.
- **D. Jev, anchors at P ≥ 0.6, top K proposed, knowledge up to 400 without the block prefilter.**
  Chosen.

## Decisión

1. **Jev chooses knowledge and criteria** when the decision model is enabled. The LLM still
   delimits stage, subject and plausible courses (ADR-0010), and every Jev failure falls back to
   the LLM selection for that step, so a proposal is never lost.
2. **Anchors and proposals.** Candidates with P(yes) ≥ 0.6 are anchors. The derived course and the
   course filter of the criteria candidates come from the anchors. Only the 4 most likely knowledge
   items and the 3 most likely criteria among the anchors are proposed. The subject is derived from
   the proposed leaves. With no anchor, nothing is proposed for that dimension.
3. **Knowledge candidates.** With Jev, the knowledge step skips the block prefilter and caps the
   gathered items at 400, split fairly across subject × course. The LLM fallback still sees the
   usual set (200 cap and block prefilter).
4. **Configuration.** Native settings, editable on the module's configuration page:
   `oermanager_llm_decision_enabled` (off by default), `_model` (blank = `typesafe/jev-1.13`),
   `_threshold` (0.6), `_max_teaches` (4) and `_max_assesses` (3), 0 meaning every anchor.
   Jev uses the configured OpenRouter key and base URL; with any other base URL the step falls
   back to the LLM.
5. **No change to the review flow.** Jev's answer is a proposal like the LLM's: untrusted data,
   resolved against the real curriculum and confirmed by the curator before any RDF write.

## Consecuencias

Real run of the adopted configuration (2026-10-09, 52 REA × 3, 0 failed), against the TASK-059 baseline:

| | Baseline | Adopted |
| --- | --- | --- |
| Knowledge micro P (strict) | 0.07 | 0.32 |
| Criteria micro P (strict) | 0.03 | 0.21 |
| Knowledge / criteria F1 in cycle | 0.14 / 0.12 | 0.39 / 0.32 |
| Leaves to review per proposal | 19.0 | 6.7 |
| Proposals without any leaf | 0 % | 0 % |
| Course in the declared cycle | 135 / 156 | 142 / 156 |
| Subject micro F1 | 0.31 | 0.46 |
| Cost per proposal | $0.0062 | $0.0052 |
| Latency mean / p90 | 20.4 s / 28.9 s | 12.4 s / 15.8 s |

- Every TASK-062 acceptance threshold is met. By stage, leaves improve on the baseline everywhere
  (ESO knowledge F1 in cycle 0.28 → 0.47, criteria 0.13 → 0.43; Primaria 0.11 → 0.31 and
  0.10 → 0.26; Infantil 0.05 → 0.49 and 0.16 → 0.27). The course improves in Primaria (60 → 68 of
  81) and stays at 27/27 in Infantil. ESO has 47/48 against 48/48: one REA in one run, where the
  LLM delimitation never showed a leaf of the declared course.
- Widening the knowledge candidates, against the same top 4/3 policy without it: knowledge recall
  +0.06 [+0.02, +0.11] and knowledge F1 +0.04 [+0.00, +0.08] (bootstrap over REA), for $0.0008
  more per REA. Criteria F1 in cycle moved 0.34 → 0.32 while the criteria reaching Jev rose from
  86 % to 89 %, so the difference is selection noise, not reach.
- The largest remaining loss is delimitation: 91 of the 741 declared leaves (knowledge and
  criteria, cycle reading) were never gathered. Improving it belongs to the LLM delimitation steps, not to this selector.
- Jev's endpoint is OpenRouter's alpha route, so the API may change; the LLM fallback covers an
  outage or a breaking change. TypeSafe documents English as Jev's primary language: questions
  are written in English and the curriculum data stays in Spanish as stored.
- Thresholds and caps were chosen on the same 52 REA they are reported on, after comparing about
  170 policies, so the gain over a fixed threshold may be optimistic. It held on four real runs,
  but they share those REA. A new evaluation set should re-check it.

## Fuentes

- TASK-062 in `docs/backlog.md` (measurements, sweep and runs, 2026-10-08 and 2026-10-09).
- Spec `docs/superpowers/specs/2026-10-08-task-062-jev-hybrid-design.md` and its addenda.
- ADR-0008 (configurable LLM connection), ADR-0010 (bottom-up classifier), ADR-0011 (LLM context layer).
- Commits `0f2d9ea` (top K), `568754f` (knowledge up to 400), `a1cef64` (admin form).
