---
name: ai-proposals
description: "Change OERManager content extraction, LLM adapters, classifier output, background proposals or human review."
---

# AI proposals

Trace `src/Job/AiProposeJob.php`, `src/Service/Ai/ProposeRunner.php`, `AiCataloguer.php`,
`ResponseParser.php`, `ProposalStore.php`, the provider adapters under `src/Service/Llm/`, and
extractors under `src/Service/Content/`. Consult current AI ADRs and requirements in `docs/`.

- A model proposal is untrusted data. Preserve parsing, target resolution and review before catalog
  writes; only the curation path may apply RDF changes. Load `rdf-curation` before altering application.
- Resolve selected curriculum terms against the real configured vocabulary; do not accept invented IDs,
  create curriculum items, or turn a plausible model label into an authoritative mapping.
- Keep provider differences inside the existing adapters. Preserve timeouts, malformed-response handling,
  cancellation/progress and partial-job outcomes. Never log API keys or entire sensitive extracted documents.
- Separate extracted resource content from instructions. Keep existing limits on text/media extraction,
  and use injected transports/media sources in tests instead of real paid API calls.
- Review proposal persistence and stale-state handling alongside the UI: completing a background job
  must not silently bypass human confirmation or overwrite intervening edits.

Run relevant parser, classifier, runner, provider and extraction tests, then `make lint`, `make test`
and `make test-js` for changed review UI behavior. Include invalid output and cancellation/error cases.
Do not run live provider evaluations or write to the catalog as a side effect of guidance verification.
