# Multi-Agent Architecture — Project Instructions Context

Working context for designing and building multi-agent systems with **Claude Code**, the **Claude Agent SDK**, the **Claude API**, and other LLMs. It distills current primary literature into decision rules so any agent or human in this repo makes consistent architectural choices.

## Sources synthesized
- **Anthropic Engineering** — *How we built our multi-agent research system* (orchestrator–worker, evals, token economics, prompt-engineering lessons)
- **Anthropic Engineering** — *Building Effective Agents* (workflows vs. agents; the five workflow patterns)
- **Anthropic** — *Building workflows for agents with Skills and Interpreters* (progressive disclosure, code execution + Files API)
- **Claude Code docs** — *Best practices for agentic coding* (CLAUDE.md, subagents, skills, hooks, MCP, plan mode, context management, parallel sessions)
- **LangChain / Anthropic** — *Choosing the Right Multi-Agent Architecture* (the four patterns + performance data)
- **Cognition (Walden Yan)** — *Don't Build Multi-Agents* (the counter-position: context engineering, single-thread reliability) — included for balance
- **Microsoft** — *ai-agents-for-beginners* (12-lesson curriculum: design patterns, tool use, RAG, planning, multi-agent, metacognition, production, protocols, context engineering, memory)

---

## 0. First principle: earn your way up the complexity ladder

Start with the **simplest** thing that works and add complexity only when you hit a named limit:

> **single agent → better prompt → add tools → workflow → multi-agent**

Agentic systems trade latency and cost for task performance. Make that trade deliberately. For many applications, optimizing single LLM calls with retrieval and in-context examples is enough. Add **tools** before you add **agents**.

Two pressures justify going past one agent:
1. **Context management** — specialized knowledge no longer fits comfortably in one prompt; you must surface information selectively as work proceeds.
2. **Distributed development** — different teams own different capabilities with clear boundaries, and a monolithic prompt becomes unmaintainable.

If neither pressure is real, a single agent wins on cost, latency, and debuggability.

---

## 1. Definitions (use these consistently)

- **Agent** — an LLM autonomously **using tools in a loop**. A multi-agent system is multiple such agents working together.
- **Workflow** — LLMs and tools orchestrated through **predefined code paths**. Prescriptive, predictable; best for well-defined tasks where reliability beats flexibility.
- **Agentic system** — umbrella term covering both. Use *workflows* when the path is knowable up front; use *agents* when flexibility and model-driven decisions are needed at scale.

Key empirical insight: letting an agent **iterate over its own process** often beats prescribing the process up front, because the correct path is frequently unknowable in advance. But a **chained-dependency** task usually wins with a deterministic pipeline that has small agents inside it.

---

## 2. Workflow patterns (compose these before reaching for multi-agent)

- **Prompt chaining** — fixed sequential steps, each feeding the next.
- **Routing** — classify input, send each category to a specialized prompt/model. Good for distinct input types (e.g., support-ticket triage).
- **Parallelization** — run independent subtasks at once. *Sectioning* splits work; *voting* runs the same task several times and combines outputs for confidence.
- **Orchestrator–workers** — an orchestrator dynamically decomposes a task and delegates, with no predefined subtasks. Anthropic's coding agents use this for multi-file GitHub issues.
- **Evaluator–optimizer** — one LLM generates, another critiques and requests refinement in a loop. Use when (a) clear evaluation criteria exist and (b) iterative refinement measurably helps (e.g., literary translation, multi-round search).

---

## 3. The four multi-agent architecture patterns

When multi-agent is warranted, pick **one** as the backbone.

### 3.1 Subagents — centralized orchestration
A supervisor calls specialized subagents **as tools**. Supervisor holds conversation context; subagents are **stateless** (strong context isolation) and can run **in parallel**.
- **Best for:** multiple distinct domains needing centralized control; subagents don't talk to the user directly (assistant coordinating calendar + email + CRM; research delegating to domain experts).
- **Tradeoff:** one extra model call per interaction (results route back through the supervisor) → more latency/tokens, in exchange for control and isolation.

### 3.2 Skills — progressive disclosure
A single agent loads specialized prompts/knowledge **on demand**. Technically one agent, but behaves like a "quasi-multi-agent" system by adopting specialized personas.
- **Best for:** one agent with many specializations; no need to enforce constraints between capabilities; team-distributed skill ownership (coding agents, creative assistants).
- **Tradeoff:** context accumulates as skills load → token bloat on later calls. Buys simplicity and continuous direct user interaction.

### 3.3 Handoffs — state-driven transitions
The active agent changes based on conversation state; each can transfer control via a handoff tool that updates shared state. State **survives across turns**, unlocking sequential workflows.
- **Best for:** staged information collection, multi-stage conversations, sequential constraints (capabilities unlock after preconditions).
- **Tradeoff:** most stateful → careful state management. Buys fluid multi-turn context carry-forward.

### 3.4 Router — parallel dispatch and synthesis
A routing step classifies/decomposes input, invokes zero-or-more specialized agents **in parallel**, and synthesizes results. Usually **stateless**.
- **Best for:** distinct verticals (separate knowledge domains); querying multiple sources in parallel and synthesizing (enterprise knowledge bases, multi-vertical support).
- **Tradeoff:** stateless → consistent per-request cost but repeated routing overhead if history matters. Mitigate by wrapping the router as a tool inside a stateful conversational agent.

---

## 4. Pattern selection

| Requirement | Pattern |
| --- | --- |
| Multiple distinct domains, parallel execution, centralized control | **Subagents** |
| One agent, many specializations, lightweight composition | **Skills** |
| Sequential workflow with state transitions; agent converses throughout | **Handoffs** |
| Distinct verticals; query multiple sources in parallel and synthesize | **Router** |

**Capability fit** (★ = strength)
| Pattern | Distributed dev | Parallelization | Multi-hop (series) | Direct user interaction |
| --- | --- | --- | --- | --- |
| Subagents | ★★★★★ | ★★★★★ | ★★★★★ | ★ |
| Skills | ★★★★★ | ★★★ | ★★★★★ | ★★★★★ |
| Handoffs | — | — | ★★★★★ | ★★★★★ |
| Router | ★★★ | ★★★★★ | — | ★★★ |

**Workload fit**
| Pattern | Single requests | Repeat requests | Parallel execution | Large-context domains |
| --- | --- | --- | --- | --- |
| Subagents | — | — | ✅ | ✅ |
| Skills | ✅ | ✅ | — | — |
| Handoffs | ✅ | ✅ | — | — |
| Router | ✅ | — | ✅ | ✅ |

**Heuristics**
- One-shot task: Skills / Handoffs / Router (~3 model calls); Subagents adds one (~4) for centralized control.
- Repeat requests: stateful patterns (Skills, Handoffs) save ~40–50% of calls; Router ~25%; Subagents stays constant (isolation at repeated cost).
- Multi-domain queries: parallel patterns (Subagents, Router) are most token-efficient. Subagents can use **~67% fewer tokens** than Skills on multi-domain work because each runs with only its relevant context. Handoffs must run sequentially and can't fan out.

---

## 5. When orchestrator–worker multi-agent actually pays off

Anthropic's research system put **Claude Opus 4 as lead** over **Claude Sonnet 4 subagents** and beat single-agent Opus 4 by **90.2%** on internal research evals; parallelization cut research time by **up to 90%** on complex queries.

**The economics matter:** agents use ~**4× more tokens** than chat; multi-agent systems use ~**15× more**. Token usage alone explained ~**80%** of performance variance — and upgrading the subagent model beat doubling the token budget. So multi-agent only makes economic sense when **task value is high enough to justify the spend**.

**Use it for** breadth-first, parallelizable tasks: heavy parallelization, information exceeding a single context window, and interfacing with many complex tools. Canonical win: "find all board members of every IT company in the S&P 500" — decomposable into independent subagent searches that a single sequential agent fails.

**Do NOT use it when** all agents must share the same context, or the task has many inter-agent dependencies. Tightly-coupled state, sequential dependencies, and shared mutable context hit coordination overhead before parallelism gains. **Most coding, dialogue, and long-form writing fall outside the sweet spot** — a single agent or deterministic pipeline usually wins on cost and reliability.

Rule of thumb: **multi-agent excels at "wide and shallow" (research, gathering, brainstorming); single agent excels at "deep and narrow" (programming, long-form writing)** where memory consistency and logical coherence are paramount.

---

## 6. The counter-position: when single-thread wins (Cognition)

Hold this tension deliberately. Cognition's *Don't Build Multi-Agents* argues naive parallel sub-agents are **fragile** because of context isolation: subagents lack context of each other's work, and **every action carries implicit decisions** that conflict when agents can't see one another (their example: one subagent builds a Mario-style background while another builds a mismatched bird). Their two principles:

1. **Share full context** — pass the complete agent trace, not just isolated messages.
2. **Avoid parallel task execution by default** — conflicting implicit decisions are hard to reconcile; reserve parallelism for narrowly designed cases. A single-thread agent context is good enough for most tasks.

**Reconciliation:** the two camps aren't truly opposed — they target different task shapes. Anthropic solves **independent-thread** tasks (isolation is a feature). Cognition solves **coherence-critical** tasks (shared context is mandatory). **The task picks the architecture.** When you do use subagents on coupled work, mitigate fragility by sharing traces, summarizing long histories for continuity, and minimizing parallel branches that make implicit, conflicting decisions.

---

## 7. Skills and Interpreters (capability building blocks)

**Skills** are folders of instructions (+ optional scripts/resources) loaded on demand, in an **open, portable format** that runs across **Claude.ai, Claude Code, the Claude API, and the Agent SDK** — build once, deploy anywhere. Package a recurring workflow as a skill instead of re-explaining it.

**Three-level progressive disclosure** keeps context lean:
1. **Name + description** — always loaded; lets the agent judge relevance.
2. **Full instructions** — loaded when the skill triggers.
3. **Bundled files/scripts** — read only as needed.

**Interpreters / code execution** give agents a real filesystem + bash/Python runtime. On the Claude API, combine the **code execution tool** + **Files API** so the agent reads/writes files and runs scripts. Use interpreters to run analysis, transformations, and tests **deterministically** instead of having the model simulate computation, and to let skills ship executable helpers.

**Compose skills + subagents + MCP:** equip subagents with skills so each runs with isolated context and specialized knowledge; connect external data/tools via MCP. **Skills are knowledge (what to do); MCP is action (calling tools to do things).** They compose — a skill can instruct the agent to invoke MCP tools.

---

## 8. Claude Code mechanics (apply these in this repo)

Claude Code is an agentic coding environment that reads files, runs commands, and works autonomously. Nearly every best practice follows from one constraint: **the context window fills fast and performance degrades as it fills.** Context is the fundamental resource — manage it aggressively.

**CLAUDE.md** — loaded at the start of every session. Keep it **short and human-readable**; for each line ask "would removing this cause mistakes?" — if not, cut it. Bloated files cause Claude to ignore real instructions.
- Include: bash commands Claude can't guess, non-default code style, test instructions, repo etiquette, project-specific architecture decisions, env quirks, non-obvious gotchas.
- Exclude: anything inferable from code, standard conventions, frequently-changing info, long tutorials.
- Locations: `~/.claude/CLAUDE.md` (global), `./CLAUDE.md` (team, check into git), `./CLAUDE.local.md` (personal, gitignored), parent/child dirs (monorepos). Import with `@path/to/file`. Run `/init` to bootstrap. Add emphasis ("IMPORTANT", "YOU MUST") for adherence. Prune like code.
- **Put only broadly-applicable rules in CLAUDE.md; put sometimes-relevant domain knowledge in Skills** so it loads on demand without bloating every conversation.

**Subagents** (`.claude/agents/<name>.md`, YAML frontmatter `name`/`description`/optional `tools`/`model` + system prompt) — run in **their own context with their own allowed tools**. Use for tasks that read many files or need focus without cluttering the main conversation. They keep your main context clean and report back summaries. Delegate explicitly: *"use a subagent to investigate X."* If `tools` is omitted, the subagent inherits the thread's tools (including MCP); whitelist for tight control.

**Skills** (`.claude/skills/<name>/SKILL.md`) — extend Claude with project/team/domain knowledge and repeatable workflows; applied automatically or invoked as `/skill-name`. Use `disable-model-invocation: true` for side-effecting workflows you trigger manually.

**Hooks** — shell commands on lifecycle events; **deterministic and guaranteed** (unlike advisory CLAUDE.md). Use for things that must happen every time (lint after edit, block writes to a protected folder). A Stop hook can gate a turn until a check passes.

**Plan mode** — separate **explore → plan → implement → commit**. Skip for small clear changes; use when uncertain about approach, when multiple files change, or in unfamiliar code. Let Claude interview you (`AskUserQuestion`) for larger features, then execute the written spec in a **fresh session**.

**Verification closes the loop** — give Claude a check it can run (tests, build exit code, linter, screenshot diff). Without a check, "looks done" is the only signal and *you* become the verification loop. Have Claude show evidence, not assertions. Gate hardness escalates: in-prompt check → `/goal` condition → Stop hook → adversarial review subagent.

**Adversarial / Writer–Reviewer review** — a reviewer in a **fresh subagent context** sees only the diff + criteria, not the reasoning that produced it, so it grades on its own terms. Tell it to **flag only gaps affecting correctness or stated requirements** — a reviewer asked for gaps always finds some, and chasing all of them causes over-engineering.

**Parallel sessions / scale** — git **worktrees** for isolated checkouts, the desktop app for visual session management, Claude Code on the web for cloud VMs, **agent teams** for coordinated multi-session work. Non-interactive `claude -p` for CI/hooks/scripts; **fan out** across files with a loop + `--allowedTools` (test on 2–3 first). **Auto mode** runs a classifier that blocks risky commands for unattended runs.

**Common failure patterns:** kitchen-sink session (fix: `/clear` between unrelated tasks), repeated corrections (fix: after two, `/clear` + better prompt), over-specified CLAUDE.md (fix: prune), trust-then-verify gap (fix: always provide verification), infinite exploration (fix: scope narrowly or use subagents).

---

## 9. Core agentic design patterns (Microsoft curriculum)

Dependency hierarchy — **Tool Use is foundational**; most others require it.
1. **Tool Use** — controlled access to functions/APIs. Describe each tool with a **distinct, unambiguous purpose** so the agent selects correctly.
2. **Agentic RAG** — agent decides when/what to retrieve across multiple rounds.
3. **Planning** — decompose a goal into ordered subtasks; revise as info arrives.
4. **Multi-Agent** — specialized roles (decomposer, searcher, summarizer, reasoner) under an orchestrator; hierarchical + parallel processing.
5. **Metacognition** — the agent reasons about its **own** process: detects being stuck, repeating queries, or using the wrong tool, and self-corrects.

Keep in scope: **agentic protocols (MCP, A2A, NLWeb)**, **context engineering**, **agentic memory**, **observability / evaluation / governance / red-teaming**, **computer-use agents**.

---

## 10. Orchestration & delegation rules (hard-won lessons)

**Teach the orchestrator to delegate.** Every subagent task must specify a **concrete objective**, explicit **task boundaries** (in/out of scope), required **output format**, and **which tools/sources** to use. Vague delegation produces duplicated work, gaps, and conflicting results. (Early Anthropic agents spawned 50 subagents for trivial queries and searched endlessly for nonexistent sources — fixed by prompting.)

- **Scale effort to query complexity.** Agents can't self-judge effort; embed explicit scaling rules so simple queries don't trigger expensive subagent waves.
- **Start broad, then narrow.** Begin with broad queries to survey the landscape, then focus — mirroring expert researchers. Overly specific first queries return few/irrelevant results.
- **Parallelize for speed.** Lead spins up 3–5 subagents in parallel (not serially); subagents call 3+ tools in parallel. Encourage parallel tool calls explicitly in prompts. This cut research time up to 90%.
- **Subagents run an OODA loop.** Observe what's gathered → orient toward the best next tool/query → decide → act; repeat efficiently.
- **Tool design = interface design.** Agent–tool interfaces deserve the same care as human-computer interfaces; a bad tool description sends agents down wrong paths. Heuristics: survey all tools first, match tool to intent, prefer specialized over generic, use web search for broad exploration vs. specialized tools for specific sources.
- **Let models prompt-engineer themselves.** A tool-testing agent that iteratively rewrote a flawed tool description cut task time **~40%**.
- **Use thinking as a controllable scratchpad.** Extended/interleaved thinking improves planning and tool selection (e.g., lead reasons about how many subagents to spawn).
- **Memory persists the plan.** The lead saves its plan to memory because exceeding the ~200K-token context truncates it; long tasks need summarization/checkpointing for continuity.
- **Simulate before production.** Run agents step-by-step with production tools/prompts to surface failure modes (over-searching, repeated queries, wrong tools), then fix wording.
- **Prompt engineering is the primary lever.** Each agent is steered by its prompt; most fixes are prompt fixes, not architecture changes.
- **Errors compound.** The prototype-to-production gap is wide because errors cascade in agentic systems — build in retry logic, checkpoints, and resume-from-failure rather than expensive restarts; inform agents of tool failures so they adapt.

---

## 11. Evaluation, observability & production

- **LLM-as-judge with an explicit rubric**, not "is this good?". Anthropic scored five criteria: **factual accuracy, citation accuracy, completeness, source quality, tool efficiency.** With criteria spelled out, one judge call can match human graders; without them you get noise.
- **Human evaluation stays essential.** Humans caught early agents preferring SEO content farms over authoritative sources — fixed by adding source-quality heuristics to prompts.
- **Start small.** Don't delay evals waiting for hundreds of cases; begin with a handful of examples immediately.
- **Scoring isn't enough.** Production needs **traces, audit trails, and failure investigation** — a governance layer beneath evaluation supplying visibility scoring alone can't.
- **Design for partial failure.** Synchronous subagent waves stall on one slow subagent; result ordering, state consistency, and partial failures are unsolved for async — handle them explicitly. There is no clean migration path for shared-state tasks; don't force them into parallel topologies.
- **Five production components:** reasoning engine (decides actions), tool integration (executes via APIs), memory systems (retain context), orchestration layer (sequences agents, handles failures, persists state — the most underestimated part), and guardrails (safety/compliance/error prevention).
- **Security widens with agents.** Each tool call/instruction is a potential prompt-injection vector. Use checkpoints, sandboxes, and validator agents — and remember those safeguards add complexity that can itself fail.
- **Constraints shape architecture.** Research blueprints often have no cost ceiling, accuracy SLA, latency budget, or error-rate threshold. Production usually has at least one — design under those, not under unconstrained defaults.

---

## 12. Human-centric UX principles (any pattern)

Operate across **Space, Time, Core**, guided by:
- **Transparency** — disclose AI involvement, explain how it works (incl. past actions), provide feedback/modification mechanisms.
- **Control** — allow customization, preference specification, and the ability to forget data; users control on/off with status always visible.
- **Consistency** — consistent multi-modal experiences with familiar UI cues (mic = voice, paperclip = upload) to reduce cognitive load.

---

## 13. Assess guardrails and hooks before building

Every project must make a **deliberate decision** about guardrails and hooks — including the decision *not* to add them. Don't skip the assessment; record the outcome (even "none needed, because X"). The two are complementary: **guardrails decide what is allowed; hooks make a check actually happen, deterministically.**

### 13.1 Guardrails — should this project have them?
Guardrails are one of the five core production components (safety, compliance, error prevention). The attack and error surface widens with every tool call and every agent, so assess them up front rather than bolting them on after an incident.

Add guardrails when **any** of these is true; if none apply, note that and move on:
- The agent can take **irreversible or side-effecting actions** (writes to prod, sends email/messages, spends money, deletes data, modifies permissions).
- The agent ingests **untrusted content** (web pages, user files, emails, tool output) — a prompt-injection vector. Treat all such content as data, not instructions.
- There is a **compliance, privacy, or safety obligation** (PII, regulated data, sensitive domains).
- The work runs **unattended / autonomously** (CI, fan-out, agent teams) where a human won't catch a bad step in real time.
- A **named non-functional constraint** exists: cost ceiling, accuracy SLA, latency budget, or error-rate threshold (research blueprints often have none; production usually has at least one — design under it).

Mechanisms to reach for: input/output validators, a **validator/critic agent**, **sandboxing** (OS-level filesystem/network isolation, e.g. `/sandbox`), **permission allowlists** (`/permissions` to scope tools like `npm run lint`, `git commit`), **auto mode** (a classifier blocks scope escalation and risky commands on unattended runs), tool-scoping per subagent (`--allowedTools`, or the subagent's `tools` field), and human-in-the-loop approval for high-risk handoffs. Remember: safeguards themselves add complexity that can fail — keep them as simple as the risk allows.

### 13.2 Hooks — should this project have them?
Hooks are shell commands fired on lifecycle events. Unlike CLAUDE.md instructions (advisory, sometimes ignored) hooks are **deterministic and guaranteed**. Convert a rule into a hook whenever "it must happen *every* time, with zero exceptions" is the real requirement.

Add a hook when:
- A check **must run after every edit** (lint, format, typecheck, run tests) rather than "when Claude remembers."
- A path or action **must be blocked** unconditionally (e.g. block writes to a migrations or secrets folder).
- A turn **should not end until a check passes** — use a **Stop hook** to gate it (Claude Code overrides after 8 consecutive blocks).
- You're enforcing a repeated CLAUDE.md rule that Claude keeps violating — that's the signal to promote it from prose to a hook.

Locations/wiring: configure in `.claude/settings.json` or via `/hooks`; Claude can write hooks for you (e.g. *"write a hook that runs eslint after every file edit"*). Pair hooks with the verification ladder in §8: **in-prompt check → `/goal` condition → Stop hook → adversarial review subagent** — escalate hardness to match how unattended and how risky the run is.

### 13.3 Rule of thumb
- **Advisory preference, occasionally relevant** → CLAUDE.md or a Skill.
- **Must happen / must be blocked every time** → a **hook**.
- **What the agent is allowed to do at all, and protection against untrusted input or unsafe actions** → a **guardrail** (validator, sandbox, allowlist, auto mode, HITL).

---

## 14. Pre-build checklist

1. Can a **single agent + better prompt** do this? If yes, stop.
2. Can a **tool** solve it without a new agent? Add the tool.
3. Is the task **coherence-critical / deep-and-narrow** (coding, long-form writing, tightly-coupled state)? → **stay single-agent** or use a deterministic workflow.
4. Is it **wide-and-shallow / independently parallelizable** and **high enough value** to justify ~15× tokens? → **Subagents** or **Router**.
5. Is it a **staged, stateful conversation**? → **Handoffs**.
6. Is it **one agent with many on-demand specializations**? → **Skills**.
7. For every subagent: concrete objective, boundaries, output format, tool/source list — and a plan to share context / minimize conflicting parallel decisions.
8. **Assess guardrails (§13.1)** — irreversible actions, untrusted input, compliance, unattended runs, or a named constraint? Add validators/sandbox/allowlist/auto-mode/HITL, or record why none are needed.
9. **Assess hooks (§13.2)** — any check or block that must happen *every* time? Promote it from prose to a hook (lint/test on edit, protected-path block, Stop-hook gate).
10. Before shipping: an **explicit-rubric evaluation**, **tracing**, **verification checks**, **retry/checkpoint/resume**, and **partial-failure handling** in place.

---

*Default posture: simplest thing that works. Climb the ladder — single agent → tools → workflow → multi-agent — only as far as the task demands, and let the task shape (wide-and-shallow vs. deep-and-narrow) pick the architecture.*