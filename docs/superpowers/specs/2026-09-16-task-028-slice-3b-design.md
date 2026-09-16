# TASK-028 slice 3b — editable legal governance and authorship (design)

Approved by the owner in session on 2026-09-16, section by section. Decision on the event format:
[ADR-0020](../../decisions/0020-curation-event-typed-values.md). Field catalogue:
[ADR-0013](../../decisions/0013-vista-maestra-v2-catalogo-campos.md) §5.5 and
`docs/superpowers/specs/2026-07-27-campos-ui-design.md`. Licence model:
[ADR-0019](../../decisions/0019-licencia-dcterms-license-uri.md).

## 1. Problem

The module curates the curriculum and nothing else. A curator can realign a REA and change its
visibility from the panel, but cannot record who wrote it or under which licence it is published —
the two questions RF-015 exists to answer. Today those fields are reachable only through Omeka's
native item editor, which knows nothing about the module's vocabularies, its integrity rules or its
audit trail.

Coverage measured in the container on 2026-09-15 (read-only, 19 REA):

| Property | REA with a value |
| --- | --- |
| `dcterms:license` | 3 |
| `dcterms:creator` | 0 |
| `dcterms:publisher` | 0 |
| `dcterms:rightsHolder` | 0 |
| `dcterms:source` | 0 |

Two artefacts that earlier slices planned to seed already exist, so this slice seeds nothing:
CustomVocab #2 «licencias» is URI-typed with 5 entries and `oermanager_licence_vocab_id` points at
it; resource template #3 «REA» exists and `oermanager_rea_template_id` points at it.

## 2. Scope

In scope: editing the five governance fields of one REA from the panel, with the module's audit
trail and undo, plus the licence validation ADR-0019 left open.

Out of scope, each for a stated reason:

| Left out | Reason |
| --- | --- |
| Batch assignment of licence and authorship | Next slice. It reuses the write path built here |
| Descriptive record (title, description, resource type) | Owner's decision, 2026-09-16. The native editor already edits these, and resource type first needs a decision on the vocabulary mismatch found in slice 1: none of vocabulary #1's 29 terms matches any value the REA carry |
| Seeding vocabularies and the REA template | Both already exist (§1) |
| Mass hygiene of inherited licences | Rewrites RDF already written; ADR-0013 requires its own ADR |
| Optimistic locking | §9 |

## 3. Fields and written values

| Field | Property | Written value | Cardinality | Without a configured vocabulary |
| --- | --- | --- | --- | --- |
| Licence | `dcterms:license` | `customvocab:<id>` with `@id` and `o:label`, from vocabulary #2 | 0..1 | Hand-typed URI (`uri` type) with optional label; the panel says it is uncontrolled |
| Author | `dcterms:creator` | `literal` | 0..n, **ordered** | — |
| Publisher | `dcterms:publisher` | `customvocab:<id>` from the new setting `oermanager_publisher_vocab_id`, carrying `@value` for a term-typed vocabulary and `@id` plus `o:label` for a URI-typed one | 0..1 | Free `literal`, with a notice |
| Rights holder | `dcterms:rightsHolder` | `literal` | 0..1 | — |
| Source | `dcterms:source` | `uri` (`http`/`https`), optional label | 0..1 | — |

Author order is preserved as entered: the first entry is the lead author.

Cardinality is enforced on save, not on read. A REA that already carries two licences — none does
today — shows both in the read view, and saving the form leaves the single selected value.

`oermanager_default_rights_holder` **prefills the form field** when the REA has no rights holder.
It is never written on its own. Writing it silently would assert an ownership nobody confirmed.

`dcterms:rights` is neither read nor written (ADR-0019 §5).

## 4. Writing and audit

Each changed property is written with the targeted clear plus append that the recataloguer already
uses:

```php
$data['clear_property_values'] = [$propertyId];
$data[$term] = $replacementValues;
$api->update('items', $id, $data, [], ['isPartial' => true, 'collectionAction' => 'append']);
```

`isPartial` is not a per-property merge: passing only the edited values would delete the item's
title, description and alignment. The harness in §8 keeps a canary on exactly that.

Every written value carries a value annotation with `dcterms:contributor`, `dcterms:modified` and
`dcterms:provenance` = `OERManager edición de gobernanza de <term>`. The event and all its
annotations share one microsecond stamp, as ADR-0015 requires.

A save that changes nothing writes nothing. Rewriting an unchanged value would reseal every
annotation with a new author and time, falsifying the audit trail.

The event is the v2 payload of ADR-0020, written to the same private `dcterms:provenance` ledger
with the same marker:

```json
{"v":2,"op":"governance","undoOf":null,"terms":{
  "dcterms:license":{"before":[],"after":[{"type":"customvocab:2","uri":"https://creativecommons.org/licenses/by-sa/4.0/","label":"CC BY-SA 4.0"}]},
  "dcterms:creator":{"before":[{"type":"literal","value":"Ana Pérez"}],"after":[{"type":"literal","value":"Ana Pérez"},{"type":"literal","value":"Luis Gil"}]}}}
```

Recataloguing keeps writing v1 unchanged. An event never mixes curriculum terms with governance
terms, so its terms say which service replays it.

## 5. Components

| Component | Responsibility | Host-testable |
| --- | --- | --- |
| `Service\Governance\GovernanceFields` | The field catalogue of §3: term, cardinality, value kind, configuring setting. Validates and normalises submitted input: trims, drops empties, enforces cardinality, requires `http`/`https` on the source | Yes |
| `Service\CurationEvent` (extended) | Builds, encodes and decodes v2 typed values; still reads v1; reports which service owns an event from its terms | Yes |
| `Service\Governance\LicenceStatus` | Classifies a licence value into the three states of §7 against the vocabulary's URIs | Yes |
| `Service\Curation\CurationWriter` | What `RecatalogService` holds today and the curriculum does not own: property resolution, targeted clear plus append, annotations, event value, shared stamp | No |
| `Service\GovernanceService` | Reads the five fields, applies changes, undoes its own events, using the three above | No |

`RecatalogService` keeps its public API and its payload format. Internally it delegates to
`CurationWriter`. That extraction is the riskiest change in this slice and §8 states what covers it.

## 6. Endpoints and data flow

- **Read.** No new endpoint. The existing authenticated `drawer-details` action adds a `governance`
  block: current values, vocabulary options, the default rights holder, a `canEdit` flag, and one
  notice per field whose vocabulary is unconfigured. Opening the panel stays one request.
- **Write.** New `governance-apply` action: POST only, CSRF-validated, behind its own ACL privilege
  `governance-apply`, granted to `editor`, `site_admin` and `reviewer` — the roles that may already
  apply a recataloguing. It answers with the saved values, the integrity recomputed on the server,
  and the event summary.
- **Undo.** The existing endpoint stands. It reads the item's last event and routes it to the
  owning service by its terms.

Opening the panel loads the values; «Editar» opens the form; saving validates, writes and answers.
The panel repaints the section and the integrity area, and the table updates its Licence cell from
the text the server returned. Nothing recomputes state in the browser: slice 1 established that
recomputing there duplicates a rule of ADR-0005.

Failures answer with a field-level validation error, `csrf`, or `denied`. Unexpected exceptions are
logged server-side and answered as `unexpected`, never leaking internals to the client.

## 7. Panel and licence validation

`PanelAreas` gains a `governance` area, labelled «Licencia y autoría», between «Información» and
«Integridad». Reading shows the five fields, one per line. Missing data is stated in words («Sin
licencia», «Sin autoría») in muted ink and **without a glyph**: with 19 of 19 REA lacking
authorship, a mark on every row marks the whole table, which ADR-0014 rule 3 forbids and slice 2
already settled for the Licence column.

The three licence states ADR-0013 deferred to the panel:

| State | Detection | Presentation |
| --- | --- | --- |
| In vocabulary | Its URI is in the configured CustomVocab | Licence label, unmarked |
| Outside vocabulary | Has a licence whose URI is absent from the list, or a value with no URI | ⚠ with «No está en la lista de licencias aprobadas». Here the glyph marks the exception |
| Missing | `dcterms:license` empty | «Sin licencia», muted, no glyph |

This closes the validation ADR-0019 left open: nothing checks vocabulary membership today.
`IntegrityPolicy` gains the warning `license_not_in_vocab`, **evaluated only when the setting points
to a vocabulary that exists**; unconfigured, it stays silent rather than raising a warning no
curator can clear. A value that is not a URI still raises `license_not_uri` alone, never both.

Editing opens in place: licence and publisher as vocabulary selects, authors as a list of text
inputs with add and remove, rights holder prefilled from the setting, source as a URL field. Errors
appear beside their field. The button appears only when the server reports `canEdit`, and the
permission is checked again on save: hiding a button is not access control.

Two JavaScript modules keep slice 1's boundary: `core/governanceModel.js`, pure and DOM-free,
builds the payload, mirrors validation and decides the licence state; `ui/governance.js` paints.
Presentation reuses the existing tokens, already corrected to WCAG AA contrast in slice 2, and the
`node --test` check that reads those tokens from the CSS keeps guarding them.

## 8. Verification

Host, PHPUnit, pure pieces:

- `GovernanceFields`: trimming, dropped empties, cardinality, `http`/`https` on the source,
  preserved author order.
- `CurationEvent` v2: an unchanged save builds no event; typed values round-trip; reordering
  authors counts as a change; `decode()` accepts v1 and v2 and rejects garbage and future
  versions; term-based routing.
- `LicenceStatus`: the three states, including the unconfigured-vocabulary case.
- `IntegrityPolicy`: `license_not_in_vocab` fires only with a configured vocabulary, and never
  together with `license_not_uri`.

Browser, `node --test`: `governanceModel.js` — payload building, mirrored validation, licence
state, author order.

**Declared limit.** `RecatalogService` needs the Omeka core and cannot be instantiated on the host,
so **no host test covers the `CurationWriter` extraction**. Two things do: the unchanged pure tests
of `CurationEvent`, and `test/container/undo-harness.php`, which exercises a full recatalogue and
undo. Running that harness is a gate for closing this slice.

New harness `test/container/governance-check.php`:

- **Read-only** (always safe): current coverage of the five fields, the URIs of vocabulary #2, the
  three-state classification over the real REA, the registered ACL privileges, and the `governance`
  block in the `drawer-details` response.
- **Write mode**, only against a disposable fixture, never the live catalogue (`AGENTS.md`): on a
  fixture REA, saving licence and authorship (a) preserves title, description and alignment — the
  `ValueHydrator` canary; (b) annotates every value with the shared stamp; (c) writes a private v2
  event; (d) undo restores the exact previous state and writes its own event; (e) an unchanged save
  writes nothing; (f) a REA touched by another route makes undo report staleness instead of
  overwriting that work.

Gates before closing: `make lint`, `make test` and `make test-js` green, plus both harnesses.

**Not verified, and declared as such:** behaviour in a real browser session, which remains
TASK-030 debt, as with the rest of the module's JavaScript.

## 9. Accepted risks

- **Concurrent editing is unguarded.** Two curators editing one REA means last writer wins. This is
  what recataloguing does today; optimistic locking would change shipped code and belongs to its
  own task.
- **Two payload shapes coexist** (ADR-0020). `decode()` is the only place that knows both.
- **Downgrading the module** leaves v2 events unreadable to the older `decode()`.
- **`CurationWriter` extraction** touches the high-risk component; covered only in the container.

## 10. Acceptance criteria

1. A curator can set and clear all five fields on a REA from the panel, and the values land on the
   properties of §3 with the types stated there.
2. Saving writes one v2 event and one annotation per value, all sharing a stamp; saving without
   changes writes nothing.
3. Undo restores the previous state exactly and writes its own event; a stale REA is reported, not
   overwritten.
4. Title, description and alignment survive every governance write.
5. The three licence states appear in the panel, and `license_not_in_vocab` fires only when the
   vocabulary setting resolves.
6. `editor`, `site_admin` and `reviewer` can save; other roles get `denied` from the server, not
   merely a hidden button.
7. Recataloguing and undo keep behaving as before, proven by `undo-harness.php`.
