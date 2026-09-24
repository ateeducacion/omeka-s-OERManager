# ADR-0020: Curation events carry typed values (payload v2)

## Estado

Aceptado (2026-09-16)

## Contexto

ADR-0015 defined the curation event that makes recataloguing reversible: a private
`dcterms:provenance` value on the item, carrying the non-translatable marker
`OERManager/curation-event/1` and a JSON payload in an annotated `dcterms:replaces`.

That payload records each dimension as `{"before": [int], "after": [int]}` — lists of **item
ids**, because every curriculum dimension is a `resource:item` link.

TASK-028 slice 3b makes legal governance and authorship editable from the module panel (RF-015,
ADR-0013 §5.5). Those fields are not links:

| Field | Property | Written value |
| --- | --- | --- |
| Licence | `dcterms:license` | URI plus label, from the configured CustomVocab (ADR-0019) |
| Author | `dcterms:creator` | literal, repeatable, order-significant |
| Publisher | `dcterms:publisher` | literal or CustomVocab term |
| Rights holder | `dcterms:rightsHolder` | literal |
| Source | `dcterms:source` | URI |

A list of item ids cannot represent any of them. The `rdf-curation` skill requires bulk writes to
be previewable, confirmable and reversible, and the batch assignment of licence and authorship is
the next slice, so an audit trail that cannot describe these values would block it.

Measured in the container on 2026-09-15 (read-only): 3 of 19 REA have `dcterms:license`; 0 have
`creator`, `publisher`, `rightsHolder` or `source`.

## Alternativas consideradas

- **A. Typed values in the payload, version 2; recataloguing keeps writing v1.** `before`/`after`
  hold value objects: `{"type":"literal","value":"…"}`, `{"type":"uri|customvocab:N","uri":"…",
  "label":"…"}` or `{"type":"resource:item","id":N}`. `decode()` reads v1 and v2. One ledger, one
  undo entry point per item; the writer that produced v1 is untouched.
- **B. Migrate every event to v2, recataloguing included.** One payload shape instead of two, at
  the cost of changing the format the high-risk writer emits and the assertions that cover it, for
  a benefit that is cosmetic.
- **C. A second ledger for governance**, with its own marker and its own undo. Leaves
  recataloguing alone, but a single REA then has two histories: "the last change" stops being
  well defined and the panel has to merge two streams.
- **D. No event for governance edits, value annotations only** (a literal reading of RF-015).
  Annotations live on the value, so they cannot record a deletion — clearing the licence would
  leave no trace, and undo would be impossible for exactly the operation that most needs it.

## Decisión

We adopt **A**.

1. The curation event payload gains **version 2**, in which `before` and `after` are lists of
   **typed values** rather than item ids:
   - `{"type": "literal", "value": "Ana Pérez"}`
   - `{"type": "uri" | "customvocab:<id>", "uri": "https://…", "label": "CC BY-SA 4.0"}`
   - `{"type": "resource:item", "id": 4362}`
2. **Recataloguing keeps writing v1, unchanged.** Only governance edits write v2. `decode()`
   accepts both and rejects anything else, as it already does.
3. The **marker does not change** (`OERManager/curation-event/1`). It identifies the value as a
   module event; the payload states its own version. Ledger, history and undo stay single.
4. `op` is `governance` for a governance edit and `undo` for its reversal. **An event never mixes
   curriculum terms with governance terms**, so the terms it contains say which service must
   replay it. Undo keeps reverting the item's last event, whichever kind it is.
5. Equality is by **ordered list**: reordering authors is a change. Curriculum dimensions keep
   comparing as sets, which is what v1 already does.
6. The invariants of ADR-0015 are unchanged and now shared: one microsecond stamp for the event
   and all its annotations, an unchanged apply writes nothing, `dcterms:provenance` is never
   cleared, and undo replays the same validated write path and produces its own event.

## Consecuencias

- Governance edits become reversible and auditable through the surface curators already know, and
  the batch slice inherits the mechanism instead of reimplementing it.
- The mechanics shared by both writers (targeted clear plus append, annotations, event value,
  stamp) move out of `RecatalogService` into `CurationWriter`. `RecatalogService` keeps its public
  API and its payload format; this is the only change to the high-risk component, and **no host
  test covers it** — `RecatalogService` cannot be instantiated without the Omeka core. The
  container harness `test/container/undo-harness.php` is the gate, run against a disposable
  fixture.
- Two payload shapes coexist. `decode()` is the single place that knows both, and the history
  projector renders both.
- Downgrading the module to a version before this ADR leaves v2 events unreadable: that older
  `decode()` rejects them, so its undo would skip a governance event and revert an earlier one.
  Accepted; the module has no downgrade path today.
- Concurrent editing is still unguarded: two curators editing one REA means last writer wins. This
  matches current recataloguing behaviour; optimistic locking would be a larger change affecting
  shipped code.
- ADR-0015 is **not superseded**: its event, its invariants and its undo semantics stand. This ADR
  widens what a payload can describe.

## Fuentes

- Owner's approval in session (2026-09-16), after the four design sections of
  `docs/superpowers/specs/2026-09-16-task-028-slice-3b-design.md`.
- ADR-0015 (curation event and reversibility), ADR-0013 §5.5 and §"sembrar, no poseer",
  ADR-0019 (licence as URI), RF-015.
- Code read while designing: `src/Service/CurationEvent.php`, `src/Service/RecatalogService.php`
  (`apply`, `undo`, `eventValue`, `annotation`, `buildValues`), `src/Service/ItemPanelData.php`,
  `src/Service/Governance/IntegrityPolicy.php`.
- Read-only container probe (2026-09-15): CustomVocab #2 «licencias» is URI-typed with 5 entries,
  `oermanager_licence_vocab_id=2`, `oermanager_rea_template_id=3`, governance field coverage.
