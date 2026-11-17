---
name: rdf-curation
description: "Change curricular alignment, tags, RDF value writes, curation audit history, or undo in OERManager."
---

# RDF curation and undo

Read `src/Service/RecatalogService.php`, `CurationEvent.php`, `IntegrityChecker.php`,
`src/Service/Curriculum/CurModel.php`, and ADR-0002/0004/0015/0019/0022 under `docs/`
(ADR-0022 replaces ADR-0009; `docs/referencia/curriculo-modelo-rdf.md` documents the old graph).
Resolve property IDs by term at runtime. Linked targets must exist and have the expected type.

## Safe writes

Omeka's `isPartial => true` is not a per-property merge. Passing only selected values can delete
unrelated title, description, license and project values. Preserve the existing targeted clear + append:

```php
$data['clear_property_values'] = [$propertyId];
$data[$term] = $replacementValues;
$api->update('items', $id, $data, [], ['isPartial' => true, 'collectionAction' => 'append']);
```

To clear a dimension, include its ID and append no values. Batch writes also need append semantics.
Never include `dcterms:provenance` in the clear list: the audit ledger is append-only.
Property-search `text` must be scalar; core trims it and arrays cause a PHP TypeError.
Visibility updates are different: they send no RDF values.

## Mapping and hierarchy

| Meaning | Property | Target |
| --- | --- | --- |
| Course | `lrmi:educationalLevel` | `cur:Curso`, not `cur:Etapa` |
| Subject | `schema:about` | `cur:MateriaCurso` (subject in a course) |
| Knowledge | `lrmi:teaches` | `cur:SaberBasico`, any depth |
| Assessment criteria | `lrmi:assesses` | `cur:CriterioEvaluacion` |
| Tags/thematic axes | `dcterms:relation` | Items from the configured `schema:DefinedTermSet` |
| Project (separate action) | `schema:isPartOf` | Project item |
| License | `dcterms:license` | URI plus label, configured CustomVocab (`oermanager_licence_vocab_id`) |

Do not read legacy `dcterms:rights` as the license. Alignment and tags are multiple resource:item
values, not free literals. Editors and above curate alignment/tags; project assignment is for
site/global administrators. Verify current ACL implementation at every write entry point.

Curriculum terms (ADR-0022) are items of the configured curriculum item set with a `cur:` class;
current terms have no `skos:changeNote`. Build every curriculum query with `CurModel` (set + class +
`nex` + page). Ancestors: `cur:etapa`, `cur:curso`, `cur:materiaCurso`; knowledge nests by
`skos:broader`. Validate a target by class and set; refuse a retired term unless already linked.
Stage and Materia are navigation, never written to the REA. Preserve incremental search and per-level
lazy loading; filter child choices by every selected ancestor (one query per id, merged).
Links to a retired term are kept and warned about (`retired_term`, compared by id with the retired set).
The panel chooses knowledge and criteria in a tree per block (`curriculum-tree`, TASK-064 slice 4) and
still writes chips, with a client-side text filter per tree. The AI classifier and the declared-alignment
resolver also read `cur:` (slice 6): codes by `skos:notation`, courses and course subjects by `skos:prefLabel`.

## Audit and reversal

Curated values carry native `@annotation` entries for contributor, modified time, provenance and
AI rationale in description. Deleting a value deletes its annotation, so annotations cannot store
undo's previous state. A changed apply also appends a private `dcterms:provenance` event to the item,
with the non-translatable marker `OERManager/curation-event/1` and JSON in annotated `dcterms:replaces`:

```json
{"v":1,"op":"recatalog","undoOf":null,"terms":{"lrmi:teaches":{"before":[],"after":[],"why":{}}}}
```

- Use the same microsecond timestamp for the event and all its value annotations. Second precision
  can make rapid undo operations restore the wrong event; collection order is not a reliable tie-breaker.
- Preserve `why` for every previous value, not only removed ones. Reject unknown payload versions.
- Undo calls the same validated apply path and produces its own event; undoing undo is redo.
- Reject stale undo when current values differ from the recorded `after`, unless explicitly confirmed.
- No-op confirmations write nothing; otherwise they falsify author/time annotations.
- Read private history server-side with authorization. Anonymous JSON-LD omits those values.
  Restored values get new annotations; original authorship survives in the event history.
- Bulk changes require preview, confirmation and reversal. Check resulting integrity.

Run the existing CurationEvent/curation/integrity tests and `make lint`, `make test`.
Inspect `test/container/undo-harness.php` for integration coverage: it writes and restores data,
so run it only against disposable fixtures. Test preservation of unrelated properties, clearing a
whole dimension, consecutive undo, stale state, private history, invalid targets and no-op behavior.
