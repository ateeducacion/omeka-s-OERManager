# OERManager 1.0: production plan

Agreed with the owner on 2026-11-17 (TASK-066 session). Decisions in
[ADR-0023](../../decisions/0023-profiles-workflow-shared-mediateca.md). The module has never had
a release (`config/module.ini` version `0.0.0`).

## Goal

A first production version of OERManager in the shared mediateca of the Consejería, where the ATE
team curates about 3 000 imported REA, private until curated, and then adds REA one by one.
Interface and workflow come first; tuning the AI's internals waits until after 1.0.

## Milestones

| Milestone | Tasks | Done when |
| --- | --- | --- |
| **0. Owner decisions** | ADR-0023 review; PEND-012 (required fields of the REA template); TASK-039 (reasons to reject and to discard); source, format and a sample of about 20 records of the initial import; AI budget | every input of the table below is in |
| **1. Security and base workflow** | TASK-069 (curriculum and axes in the «Currículo» site, EducationalCurriculum change); TASK-067 (REA roles, ACL, uninstall, new states, out-of-flow detection absorbing TASK-038, «Mis propuestas», queue counter); TASK-039; TASK-074 (private audit annotations) | a `rea_curador` and a `rea_proponente` who are not administrators work the whole flow in the container |
| **2. Panel and queue** | TASK-035 (the panel stops reading through `/api`: today a private REA cannot be re-catalogued, and the whole initial import is private); TASK-070 (curation queue); TASK-065 (usability pass after curators try the queue) | curators confirm and publish imported REA from the queue |
| **3. Volume** | TASK-068 (a) (AI baseline on `cur:`); TASK-011 (batch AI proposal); TASK-052 (statistics at 3 000 REA in 86 000 items, isolation on) | the batch proposal runs on a load with a known accuracy and the pages hold at production scale |
| **4. Production** | TASK-071 (deployment runbook, rehearsed on a copy); TASK-072 (initial import, rehearsed on the copy); container image with glibc and `zip` (TASK-024 (b), TASK-031 (a)); TASK-030 (acceptance with real users of each role); TASK-073 (release 1.0) | the rehearsal on the copy passes TASK-030 and 1.0 is published |

TASK-047 closes whenever the owner reviews it; it does not block the plan.

**Critical path:** owner decisions → TASK-069 and TASK-067 → TASK-035 → TASK-070 → TASK-011 →
TASK-072 rehearsal → TASK-030 → TASK-073. TASK-052 and TASK-071 run in parallel from milestone 1.

**After 1.0:** TASK-068 (b) (model comparison), TASK-058, TASK-016, TASK-053 to TASK-055, the
statistics panels TASK-048, TASK-050 and TASK-051 (TASK-049 is blocked by data), TASK-028 (5c),
e-mail notifications, batch publication, an ACL that limits a curator to REA items.

## What the owner provides, and when

| Input | Unblocks | When |
| --- | --- | --- |
| Review of ADR-0023 | TASK-067 | now |
| PEND-012 and the reason lists | milestone 1 | during milestone 1 |
| A copy of the production mediateca | TASK-071, TASK-072, TASK-030 | before milestone 4 |
| A sample of the initial import | TASK-072 | before milestone 4 |
| The new container image | TASK-030 | before milestone 4 |
| AI budget (about $0.8 for the baseline, about $16 for 3 000 proposals at $0.0052 each, TASK-062) | TASK-068 (a), TASK-011 | milestone 3 |
| Curators who try the queue | TASK-065 | milestone 2 |

## Main risks

1. Batch AI on 3 000 REA without a valid baseline on `cur:`: TASK-068 (a) goes first.
2. No copy of production: deployment and import would be untested. It is an owner input.
3. Isolation: every check by a non-administrator depends on TASK-069; Jobs read without isolation,
   accepted in ADR-0023.
4. Performance with 86 000 items and isolation joins: TASK-052.
5. Checks run as `global_admin` hide ACL and isolation: TASK-067 and TASK-030 use real users with
   REA roles.

## Addendum 1 (2026-11-26): TASK-077 joins milestone 2

The owner added **TASK-077**, a guided screen to add a REA from «Mis propuestas», for proposers and
curators, driven by the configured REA template and centred on the curricular anchoring. It belongs to
milestone 2 (panel and queue): the single additions of ADR-0023 §3 start there. It depends on TASK-069
(isolated users must reach the curriculum) and on PEND-012 (the template's required fields). Its exit
check: a `rea_proponente` who is not an administrator adds and proposes a REA through it in the container.
