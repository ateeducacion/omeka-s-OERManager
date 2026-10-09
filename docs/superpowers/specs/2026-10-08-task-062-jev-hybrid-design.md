# TASK-062 — Jev as the fine knowledge/criteria selector (hybrid design)

Approved by the owner on 2026-10-08. Context: the assessment recorded in the TASK-062 backlog row and the
TASK-059 baseline (`gpt-4o-mini`, 3 runs × 52 REA): of the declared leaves, 36 % reach the model and are not
chosen, and precision is 0.07 (knowledge) and 0.03 (criteria). Jev (`typesafe/jev-1.13`) is reached through
OpenRouter's `POST /api/alpha/decisions` with typed questions; `noul` returns P(yes) (checked with synthetic
requests, 2026-10-08).

## 1. Scope

Only the step that picks knowledge and criteria among the candidates changes (`CurricularClassifier`'s leaf
selection). Delimitation (stage, subject, plausible courses, cycle rule), distillation, axes and the
derivation of course and subject from the chosen leaves stay as they are. **Off by default**: with the option
off, behaviour is exactly today's.

## 2. Components

| Component | Responsibility |
| --- | --- |
| `Llm\DecisionModelInterface` | `decide(string $state, array $questions): DecisionResult` |
| `Llm\DecisionResult` | typed answers by question id (`noul` → P(yes); `choice` → option, probabilities, confidence) plus usage (tokens, real cost, served model, ms) |
| `Llm\OpenRouterDecisionClient` | `POST {https://…/api}/alpha/decisions` with the configured OpenRouter key and Jev's native body; reuses `HttpTransportInterface`; the response is untrusted data (unknown ids, non-numeric or out-of-range probabilities are rejected) |
| `Ai\JevLeafSelector` | builds one `noul` per candidate, splits requests above 200 questions, returns P(yes) per candidate, the anchors (P ≥ threshold) and the proposed ones (the top K anchors per dimension, section 4) |
| `CurricularClassifier` | optional selector: when present, the knowledge and criteria steps use it; on any failure of the selector the step falls back to the current LLM selection and the trace says so |

The decisions endpoint is derived from the configured base URL (`…/api/v1` → `…/api/alpha/decisions`); any
other base URL is a configuration error, never a guess.

## 3. Questions

- **State**: the same text the fine step sees today (distilled summary + resource content, `ItemContext::fineText()`).
- **One `noul` per candidate**, question id = its index (ids never reach the model). Instructions in English
  (Jev's primary language), the candidate's own data in Spanish as stored: code, course, block, statement.
  Example: «Does the resource in `state` work on this curriculum item: [3º Primaria · III. Sentido espacial]
  Figuras geométricas… (PMAT03SBIII.1)?» with criteria `true` «The resource teaches or practises it» / `false`
  «It is unrelated or only mentioned in passing».
- At most 200 questions per request (Jev's choice limit is about 240; the 32 000-token budget is checked by
  splitting); several requests for larger candidate sets.

## 4. Decision and abstention

- A candidate is proposed when P(yes) ≥ threshold (default 0.6, setting). Its justification is «Jev P = 0.87».
- No candidate above the threshold → nothing proposed for that dimension (an absent edge is better than a
  wrong one).
- The trace stores P(yes) for **every** candidate, so the evaluation set can sweep thresholds offline without
  calling Jev again, and the threshold is chosen on data.

**Addendum 2026-10-09 — top K per dimension.** The offline sweep over the stored probabilities showed that P(yes)
ranks well within one REA but is not calibrated (candidates with P 0.8–0.9 are right 30 % of the time, P 0.9–1.0
60 %), so a fixed threshold trades recall for review load badly: at 0.8 the course in the declared cycle
regressed and 12 % of proposals had no leaf. The candidates at or above the threshold are now **anchors**; only
the K most likely anchors of each dimension are proposed (settings `oermanager_llm_decision_max_teaches` and
`_max_assesses`, default 4 and 3, 0 = every anchor). The derived course and the course filter of the criteria
candidates come from the anchors; the subject comes from the proposed leaves. The trace adds `anchor_ids` and
`cap`; the evaluation set takes `--caps=k,c` (default 4,3).
Measured on 2026-10-09 (52 REA × 3): leaf F1 in cycle 0.35 knowledge / 0.34 criteria, 6.3 leaves per
proposal, none empty, course in cycle 136/156; figures and caveats in the TASK-062 backlog entry.

**Addendum 2026-10-09 — knowledge up to 400, adoption.** With the decision model, the knowledge step skips the
block prefilter and caps the gathered items at `CurricularClassifier::DECISION_LEAF_CAP` = 400, split fairly across
subject × course (two requests of 200); if Jev fails, the LLM fallback sees the usual set (200 cap and block
prefilter). The declared knowledge reaching Jev rose from 60 % to 79 %, for about $0.0008 more per REA. The owner
adopted the configuration and asked for the admin form: section 5's settings are now on the module's configuration
page. Decision and figures: ADR-0021.

## 5. Configuration and piloting

Settings, no admin form yet: `oermanager_llm_decision_enabled` (default off), `oermanager_llm_decision_model`
(default `typesafe/jev-1.13`), `oermanager_llm_decision_threshold` (default 0.6),
`oermanager_llm_decision_max_teaches` / `_max_assesses` (default 4 / 3, addendum of section 4). The evaluation set gains
`--strategy=jev`, which builds the classifier with the selector **in memory** for the run, without changing
the stored settings, and `--threshold=x` to re-score stored probabilities. An admin form comes only if the
strategy is adopted (done on 2026-10-09, ADR-0021).

## 6. Tests (host)

`OpenRouterDecisionClient` with `FakeTransport` and fixtures shaped as the real synthetic responses (noul,
choice, usage with cost, served model), endpoint derivation and rejection of malformed answers;
`JevLeafSelector` with a fake decision model (questions per candidate, splitting, threshold, abstention);
`CurricularClassifier` with the selector (proposals, justifications, trace) and its fallback on failure.

## 7. Measurement and acceptance

52 REA × 3 with `--strategy=jev --label=…` against the TASK-059 baseline, plus a threshold sweep. Acceptance
(TASK-062): no regression in any stage; leaf precision ≥ 0.25 knowledge and ≥ 0.15 criteria; leaf micro F1 in
cycle ≥ baseline + 0.10; no invalid ids; cost per REA ≤ $0.0062; p90 latency ≤ 28.9 s; abstention ≤ 30 %.

**Prerequisite for the real run:** the owner checks OpenRouter's and TypeSafe's data policy for this model;
until then only host tests and synthetic requests are used.
