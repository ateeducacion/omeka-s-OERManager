# TASK-076: a REA's stage subject, so public search can facet by subject

Owner decisions taken on 2026-11-24 (below). Requirements: RF-004, RF-006, RF-008. Decisions:
ADR-0022 (addendum 1, written with this task), ADR-0002, ADR-0015, ADR-0013, ADR-0023. Reported on
2026-11-24 from the REA ATE theme (`omeka-s-theme-rea-ate`).

## Problem

Since ADR-0022, `schema:about` on a REA points to a `cur:MateriaCurso` (a subject in one year,
e.g. «Matemáticas · 1º ESO»). The stage subject (`cur:Materia`, e.g. «Matemáticas (ESO)») is
reached through that item's `cur:materia` (the edge ADR-0022 §5 already uses; in development it
matches `skos:broader` on all 232 `MateriaCurso`) and, by ADR-0022 §2, is not written on the REA.

The public site's «Materia» facet must filter by subject, not by subject-and-year: a teacher
looking for Mathematics resources expects one entry, not one per year. It cannot today:

- The site searches with AdvancedSearch 3.4.60 and its internal querier. Facet values come from
  `Reference`'s `references` plugin over **one property** (`InternalQuerier::fillFacetResponse()`):
  the title of the linked resource in that property. It does not follow a property path such as
  `schema:about/cur:materia`.
- Clicking a value filters that same property with `eq` on the title. With the field
  `schema:about`, the facet lists and filters `MateriaCurso` titles.
- The 232 `MateriaCurso` titles are all different («<subject> · <year>»), so they do not collapse
  by label either.

A theme-only workaround (grouping `MateriaCurso` values by subject in the facet template) was
rejected by the theme: one checkbox sends one value, so it needs JavaScript or multi-value links;
summed counts overcount a REA catalogued in two years of the same subject; active-filter chips
still name `MateriaCurso`; and the facet `limit` cuts values before grouping. It would also leave
the API, the Moodle repository and other consumers without a subject filter.

## Owner decisions (2026-11-24)

| Question | Decision |
| --- | --- |
| Which property | **`lrmi:educationalAlignment` → `cur:Materia`.** LRMI has no subject property: it expresses the subject as an alignment to a framework node (an `AlignmentObject` with `alignmentType` «educationalSubject»). Omeka has no nested objects, so the value points straight at the framework node, as the pre-ADR-0022 curriculum did. It is within ADR-0022 §3 (`dcterms`, `lrmi`, `schema`) and unused on REA today. Rejected: `dcterms:subject` (the «Ficha mediateca» template uses it, so REA imported from the mediateca by TASK-072 may bring free keywords there that the derivation would overwrite) and `cur:materia` (extends §3 to a vocabulary OERManager does not own, ADR-0013). |
| Swap the two (`schema:about` → `Materia`, `educationalAlignment` → `MateriaCurso`) | **No.** Semantically tidier, but it moves the curated dimension across about 20 code and 38 test files (re-cataloguer, tree, integrity, AI, statistics, panel), rewrites ADR-0022 §1–2 and the curriculum contract, breaks undo of stored events, and still needs the same derivation. |
| Facet label | **One entry per stage**: the `cur:Materia` title as is («Matemáticas (ESO)», «Matemáticas (Bachillerato)»). Under LOMLOE they are different subjects. |
| Stage too (`cur:Etapa`) | **Later.** Same mechanism, but in its own property (e.g. `dcterms:educationLevel`), so the subject facet does not mix stages in. A possible follow-up, not part of this task. |
| ADR-0022 §2 | **Addendum 1 to ADR-0022**: stage, stage subject and competences are not *catalogued* on the REA; the stage subject is *derived* into `lrmi:educationalAlignment` for search. The same note goes to the curriculum contract (`docs/omeka.md` of `curriculo_lomloe_canarias`, «Consumidores») as a PR to that repository. |

## Behaviour

1. **Derived value.** For a REA (`lrmi:LearningResource`), `lrmi:educationalAlignment` holds the
   distinct `cur:materia` targets of class `cur:Materia` of its `schema:about` values, as public
   `resource:item` values, in a stable order (by target title, then id). Literal or URI
   `schema:about` values, targets that are not `cur:MateriaCurso`, and `MateriaCurso` without
   `cur:materia` contribute nothing. Retired subjects are derived like any other (the retired-term
   rule of ADR-0022 §6 already flags the REA through its `MateriaCurso`).
2. **Never edited by hand.** Any value written to `lrmi:educationalAlignment` on a REA, from any
   path, is replaced by the derived set on the same save. Items of other classes are untouched.
3. **Every save path.** Native form, REST API (create, update, partial update), native batch edit
   (including append, remove and `clear_property_values`), the re-cataloguer and its undo, the
   governance batches, the workflow actions and the import of TASK-072.
4. **Not a curation dimension.** The re-cataloguer, the panel, the diff and the history do not show
   or annotate it: no value annotations (ADR-0002), no entry in the curation event (ADR-0015).
   Integrity (`IntegrityPolicy::ALIGNMENT_TERMS`), the alignment status, the master view filters and
   the statistics do not read it.
5. **Resync job.** A background job recomputes the property for every REA and updates only those
   whose stored set differs from the derived one, so a second run changes nothing and `o:modified`
   of the other REA does not move (the master view sorts by it). It logs how many REA it examined
   and changed. A global administrator launches it from the module configuration page with a
   button «Recalcular la materia derivada de los REA», separate from the settings form. It runs
   after deploying this version and after each curriculum release loaded by EducationalCurriculum
   (a `MateriaCurso` may change its `cur:materia`); TASK-071's runbook and the curriculum-release
   procedure gain that step.
6. **Search configuration (deployment, not module code).** In the AdvancedSearch configuration
   of the REA site, the «Materia» facet uses `lrmi:educationalAlignment` as `field`. TASK-071's
   runbook gains this step. The theme needs no change: `view/search/facets-list.phtml` renders any
   facet the search configuration provides.

### Out of scope

- Deriving the stage (`cur:Etapa`); see decisions.
- An integrity warning for a stale derived value. The first proposal had one; it is dropped. A
  stale value only appears when a curriculum release changes a `MateriaCurso`'s `cur:materia`; the
  check would read each row's targets on every master view page (NFR-004); and the resync job run
  after each release is the fix. Revisit if releases ever change subjects without that step.
- Solr (SearchSolr) indexing a property path. Not in production; out unless production moves to Solr.

## Design

### Where the derivation runs

`api.hydrate.post` on `ItemAdapter`, the same point as `Module::guardWorkflowValues()`. Checked
against Omeka S 4.2 (`AbstractEntityAdapter::hydrateEntity()`, `Entity\Resource`):

- At `hydrate.pre` only the request is known. Batch updates are always partial and carry
  `collectionAction` (`append`, `remove`, `replace`) and `clear_property_values`, so the final
  `schema:about` cannot be read from the request. At `hydrate.post` the entity holds the final
  values whatever the path.
- `Resource::$values` is mapped `orphanRemoval=true, cascade={"persist", …}`: adding a new `Value`
  entity to the collection, or removing one, is persisted by the same flush. No second write and no
  recursion through `api.update.post` (`handleItemPostSave`, integrity logging).
- An error from another `hydrate.post` listener (the workflow guard) still aborts the whole write;
  the derivation adds no error of its own.
- Every module write goes through `api->update()` (`CurationWriter`, `WorkflowService`, the
  governance batches), so it reaches the hook.

The derivation reads `$entity->getValues()`: for each `schema:about` value whose
`getValueResource()` is an item of class `cur:MateriaCurso`, the `cur:materia` values of that item
whose target is of class `cur:Materia`. It then compares the result with the entity's current
`lrmi:educationalAlignment` values. It removes those not in the derived set (and any value that is
not an item link), and adds a `Value` (`resource:item`, public, no language, no annotation) for each
missing target. When the sets already match it changes nothing.

### Components

1. **`Service\Curriculum\SubjectDerivation` (pure).** `derive(list<array{class:?string,
   subjects:list<array{id:int, class:?string, title:string}>}> $about): list<array{id:int,
   title:string}>` gives the ordered, distinct `cur:Materia` targets. `diff(list<int> $stored,
   list<int> $derived): array{add:list<int>, remove:list<int>}`. Host-tested.
2. **`Service\Curriculum\DerivedSubjectWriter` (Doctrine adapter).** `apply(Item $entity): bool`:
   returns false for a non-REA; otherwise it projects the entity's `schema:about` targets for
   `SubjectDerivation`, applies the diff to the entity's value collection and returns whether
   anything changed. `derivedIds(Item $entity): list<int>` and `storedIds(Item $entity): list<int>`
   serve the job. It looks the `lrmi:educationalAlignment` `Property` up once (entity manager); if
   the vocabulary is missing it does nothing.
3. **`Module::deriveSubject()`**, a listener on `api.hydrate.post` for `ItemAdapter` that calls the
   writer.
4. **`Job\ResyncDerivedSubjects`.** Pages through REA ids (`return_scalar => 'id'`, batches of
   100). For each REA whose stored ids differ from the derived ones it calls
   `api->update('items', $id, [], [], ['isPartial' => true])`, which reaches the hook. It clears the
   entity manager between batches and logs `examined` and `changed`.
5. **Launch.** A POST-only action `resync-subjects` in `IndexController`, under the `config`
   privilege (global administrator, as the configuration page), with its own CSRF token. It
   dispatches the job and redirects to the configuration page with a message linking to the job.
   The button is a separate small form in `config.phtml`, so it never mixes with
   `ConfigPayload::write()`.

### Cost (NFR-004)

The hook reads the targets of the REA's own `schema:about` values (a handful) and their
`cur:materia` edges: a few lazy loads per save. After the first back-fill the job writes only the REA
that changed; the first back-fill updates each REA with `schema:about` once (about 3 000 after
TASK-072), in batches.

### Errors and edge cases

- Item of another class: untouched, even if it has `lrmi:educationalAlignment`.
- A REA whose class changes away from `lrmi:LearningResource`: its derived values stay. They are
  harmless and the item is out of the catalogue; not worth an extra rule.
- `schema:about` pointing to a deleted item: the core hides that value (no value resource); it
  contributes nothing.
- Two `MateriaCurso` of the same subject («Matemáticas · 1º ESO», «· 2º ESO»): one derived value,
  so the facet counts the REA once.
- A partial update with no values (the job's call) keeps every other value of the REA.

## Testing

- **PHP, TDD on the host:** `SubjectDerivation`: two years of a subject give one value; two
  subjects give two values in title order; literal, URI and non-`MateriaCurso` targets are ignored;
  a `MateriaCurso` without `cur:materia`; a `cur:materia` to a non-`Materia`; empty input. `diff`:
  add, remove, no-op, and a stored id missing from the derived set. The `resync-subjects` action:
  POST only, CSRF checked, dispatches the job. The configuration view shows the button.
- **Container harness** (`test/container/subject-derivation-check.php`, disposable or development
  catalogue only, AGENTS.md). It creates a REA with two `MateriaCurso` of one subject through the
  API and checks one derived value. It then checks a partial update that removes one, a batch
  append and `clear_property_values`, a hand-written `lrmi:educationalAlignment` being replaced,
  and the re-cataloguer write and its undo. It runs the job twice (the second run reports 0
  changed) and deletes what it created.
- **Manual:** with the facet configured on `lrmi:educationalAlignment` in the REA site, the public
  search lists one entry per stage subject, with exact counts, working chips and pagination.
- `make lint`, `make test` and `make test-js` pass; coverage gate ≥ 90 %.
