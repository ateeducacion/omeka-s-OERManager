# TASK-070: the curation queue is a mode of the master view

Approved by the owner on 2026-11-24 (brainstorming session). Milestone 2 of the
[1.0 plan](2026-11-17-v1-production-plan.md). Requirements: RF-016, RF-002, NFR-004.
Decisions: ADR-0023 §2–3, ADR-0017, ADR-0013.

## Why

The initial import (TASK-072) brings about 3 000 REA, all private and in Propuesto. Curators must
work through them one by one: open the panel, adjust the proposal, then confirm and publish, reject or
discard (ADR-0023 §3; there is no batch publication). Public REA without the status Publicado («fuera
del flujo», 69 in development) must be regularised from the same place (ADR-0023 addendum 1, §3).
Milestone 2 is met when curators confirm and publish imported REA from the queue.

## Owner decisions (2026-11-24)

| Question | Decision |
| --- | --- |
| Own page or master view | **A mode of the master view, not a page.** The master view is curator-only and already has the status, collection, «sin X», alignment and integrity filters and the same panel. An own page would duplicate the list (columns, filters, panel wiring) for little more than focus. This replaces the backlog wording «a list». |
| «AI proposal ready» filter | **Deferred to TASK-011.** Nothing stores a pending proposal yet: a single proposal lives in a temporary file that expires in an hour, and where batch proposals live is TASK-011's decision. TASK-011 adds the filter and its row badge. |
| «What the REA lacks» | **Exact native filters, plus the integrity cell already in each row.** No filter by a rule-based integrity issue: that needs every REA read whole, today capped at 2 000. |
| Age | **Catalogue entry date (`o:created`), oldest first** in the queue, switchable as any column sort; filter «Creado hace más de» 7 / 30 / 90 days. The date a REA became Propuesto lives in the status annotation, which the native search cannot sort by. For imported REA the entry date is the import date. |
| After an action in the panel | **Mark the row and offer «Siguiente».** The row is greyed with its new state, «Cola (N)» drops by one, and the panel offers the next REA of the page. |

## Behaviour

1. **Status option «En cola».** The first option of the status filter after «Estado: todos», in the
   quick bar and in the advanced search. It shows REA in Propuesto plus public REA whose status is none of Propuesto, Publicado and
   Descartado: the same definition `QueueCounter` and `WorkflowStatus::inQueue()` use. It combines with
   every other filter (title, collection, licence, type, «sin X», alignment, integrity, age).
2. **«Cola (N)» opens the master view with `status=queue&sort_by=created&sort_order=asc`.** The master
   view's own default order does not change (`modified`, descending).
3. **«Sin campo obligatorio»** in the «sin X» filters (advanced search, checkbox `missing[]=required`):
   REA lacking at least one property that the configured REA template
   (`oermanager_rea_template_id`, `GovernanceSettings::REA_TEMPLATE_ID`) marks as required. Every
   required property counts, licence and alignment included («lacks any required field»). The option
   is not offered when no REA template is configured or it cannot be read, and the parameter is ignored
   then. Exact over the whole catalogue: no cap.
4. **«Creado hace más de» 7, 30 or 90 days** (advanced search, `age=7|30|90`): native `created_before`
   set to today minus N days. Any other value is ignored. Not limited to the queue.
5. **After «Confirmar y publicar», «Rechazar» or «Descartar»** succeed in the panel:
   - the row of that REA gets a «done» look (greyed) and shows the new state, without reloading;
   - the «Cola (N)» label drops by one when the REA was in the queue (all three actions take it out);
   - once the panel has re-rendered, it shows a **«Siguiente»** button that opens the next REA of the
     current page in the current order;
   - on the last row of the page the button reads **«Recargar la lista»** and reloads the same URL: with
     «En cola» the finished REA have left the list and the next ones move up.

   It works under any filter, not only the queue. «Proponer», «Despublicar» and «Reabrir» are unchanged.
6. **Filter chips** show the new parameters: «Estado: En cola», «Sin campo obligatorio», «Creado hace
   más de N días». The quick bar keeps `age` as a hidden field, as it does for the other advanced
   filters.

### Out of scope

- The «AI proposal ready» filter (TASK-011).
- A filter by a specific integrity rule.
- Assigning REA to curators or locking a REA while someone works on it. Two curators on the same REA:
  the second one's action is refused by the server's transition check, as today.
- Any change to the panel's actions, permissions (`RecatalogRule`, ACL) or the proposer's «Mis
  propuestas».

## Design

### Why id sets

The queue is a union: «Propuesto» OR «public and not Publicado». The core cannot express it in one
search: `is_public` is not a property, so it cannot sit in an OR branch, and the core joins property
rows with AND/OR **without parentheses** (`AbstractResourceEntityAdapter::buildPropertyQuery()`),
so AND binds tighter than OR across the whole list. «Lacks any required field» is a union too (one
`nex` per property). Two paginated searches merged in PHP would need a merge by sort key and is fragile.

Instead each union is resolved to a set of item ids with id-only searches
(`return_scalar => 'id'`: no item is hydrated), the sets are combined in PHP, and the page is one
native search with `id => [...]`, its sort and its pagination.

### Components

1. **`Service\IdConstraints` (pure).** From the request query and the required terms, it returns the
   **constraint groups** the request needs: a list of groups, each group a list of **branches**; a
   branch is a fragment of search parameters (`property` rows and, for the queue, `is_public`). The
   result is the intersection of the groups, each group being the union of its branches.
   - `status=queue` → one group, two branches: `[status eq Propuesto]` and
     `{is_public: true, [status neq Propuesto, neq Publicado, neq Descartado]}`.
   - `missing[]=required` with required terms `t1…tn` → one group, `n` branches `[tn nex]`. With no
     required terms → no group (the parameter is ignored).
   - Nothing else produces a group. No group → the master view runs exactly as today.

   It also exposes the queue branches for `QueueCounter`, so the queue has one definition.
2. **`Service\IdConstraintResolver` (core adapter).** For each branch it runs
   `api->search('items', ['resource_class_id' => REA class, 'return_scalar' => 'id'] + branch)` **with
   only the branch's own rows**, never the master view's filters: mixing them would hit the flat
   AND/OR precedence above (see TASK-075). It unions each group and intersects the groups. It returns
   `null` when there is no group, else the list of ids (possibly empty).
3. **`IndexController::indexAction()`** asks the resolver after `buildSearchParams()`:
   - `null` → unchanged;
   - `[]` → **zero results without calling the API**: the core ignores an empty `id` filter and would
     return the whole catalogue;
   - ids → `$searchParams['id'] = $ids`, then the existing plain or computed branch runs unchanged
     (the computed filter reads the same `id`-bounded search).
4. **`MasterViewQuery::buildSearchParams()`** gains `age` → `created_before` (clock injected, so it is
   testable). `status=queue` and `missing[]=required` are not native: it leaves them to the resolver
   (they are not in `STATUS_FILTERS` / `MISSING_FILTERS`).
5. **Required terms**: a small reader of the REA template's required property terms (from the setting,
   via the API), `[]` when unset or unreadable. Shared by the resolver and the views (to offer or hide
   the checkbox).
6. **`QueueCounter`** keeps its two COUNT-only searches (`limit => 0`) but takes the branches from
   `IdConstraints`. The two branches are disjoint (the second excludes Propuesto), so the sum is exact.
7. **Navigation**: the `oer-queue` entry's query becomes `status=queue&sort_by=created&sort_order=asc`.
8. **Views**: «En cola» right after «Estado: todos» in both status selects; «Sin campo obligatorio» in the advanced search
   when required terms exist; the age select in the advanced search; `age` passed through the quick
   bar's hidden fields; chip labels in `Module::addSearchFilters()`.
9. **JavaScript**:
   - `ui/workflowDrawer.js` dispatches `oer:workflow-changed` (`{ itemId, action, status }`) on success,
     before `reopenDrawer()`.
   - `core/queueNav.js` (pure, node tests): the next item id of the page, or `null` at the end.
   - `ui/queueFlow.js`: on `oer:workflow-changed` for `publish-proposal`, `reject-proposal` or `discard`
     it marks the row and lowers the «Cola (N)» number; on the next `oer:drawer-rendered` of that item
     it adds «Siguiente» or «Recargar la lista» to the panel. «Siguiente» clicks the next row's
     `.oer-open-drawer` trigger, so the core opens the panel as usual.
   - `asset/css/oer-master-view.css`: the «done» row and the button.

### Cost (NFR-004)

With «En cola»: two id-only searches, one hydrating search of a page. With «sin campo obligatorio»:
one id-only search per required property (the REA template has a handful). The final `IN` list holds
at most the REA catalogue (~3 000 integers). No REA is read whole beyond the page, except under the
existing computed filters, whose 2 000 cap is unchanged. If the catalogue grows far beyond 3 000,
measure (as TASK-052 does for statistics) before adding anything.

### Errors and edge cases

- No `lrmi:LearningResource` class → `QueueCounter` returns 0 as today; the resolver returns `[]`
  (zero results).
- Empty id set → zero results without an API call (above).
- Unknown `status`, `missing[]` or `age` values → ignored, as the current filters do.
- Isolated users (IsolatedSites): the id-only searches go through the same API, so they see the same
  sites as the page.
- An action on a REA another curator has just moved: the server refuses the transition; the row is
  not marked and the panel shows the error, as today.

## Testing

- **PHP, TDD on the host:** `IdConstraints` (groups and branches per query, no group when nothing
  applies, no group for `required` without terms); `IdConstraintResolver` with a mocked `ApiManager`
  (only branch rows are sent, `return_scalar`, union and intersection, `null` vs `[]`); `indexAction`
  short-circuit on `[]`; `MasterViewQuery` `age` with a fixed clock; `QueueCounter` still sends the
  same two COUNT searches; the required-terms reader; `addSearchFilters` chips; the navigation query.
- **JS (`make test-js`):** `core/queueNav.js` (middle, last, unknown current id, single row).
- **Container harness:** a read-only check that «En cola» returns exactly the REA that
  `WorkflowStatus::inQueue()` accepts and that its total equals `QueueCounter::count()`; it must run on
  a disposable fixture catalogue, never the live one (AGENTS.md).
- **Manual:** as a `rea_curador` (not an administrator), open «Cola (N)», publish, reject and discard
  from the panel, follow «Siguiente» to the end of a page and «Recargar la lista».

## Related finding (TASK-075)

`MasterViewQuery::addAlignmentFilter()` writes `alignment=partial` as `assesses ex OR teaches ex`
appended to the other property rows. With the flat precedence above, title X plus partial alignment
reads `(title ∧ assesses) ∨ teaches`, so REA not matching the title may show. Suspected from reading
the core, not reproduced. Recorded as TASK-075; out of TASK-070's scope, which avoids the problem by
never mixing branch rows with the master view's rows.
