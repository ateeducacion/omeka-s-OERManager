# TASK-035: one authenticated call for the panel, and who may re-catalogue — design

Closes TASK-035 (backlog row: design, risks and verification) and adds the re-cataloguing rules by
workflow state and role that the owner decided on 2026-11-18 while reviewing TASK-067
([spec](2026-11-17-task-067-rea-roles-workflow-design.md)). Recorded as an addendum to
[ADR-0023](../../decisions/0023-profiles-workflow-shared-mediateca.md), with a note on
[ADR-0018](../../decisions/0018-flujo-autor-curador-rea.md), which left alignment to the curator.
Written 2026-11-18 on branch `feature/task-067-rea-roles-workflow`.

## Problem (reported by the owner, 2026-11-18)

A private REA cannot be re-catalogued from the panel, neither by `global_admin` nor by its owner.
`asset/js/ui/drawer.js` fetches `row.dataset.apiUrl` against `/api`, which does not see the admin
session, so a private item answers 403/404 and the re-cataloguer receives `itemJson: null` and
does not mount. Under ADR-0023 everything that is curated (initial import, Propuesto) is private:
this blocks 1.0.

## 1. Data: the panel brings its own pre-fill (TASK-035)

- `drawer-details` already reads the item with the session and `ItemPanelData::forItem()` already
  walks `RecatalogService::ALIGNMENT_TERMS` without N+1 reads. It now also returns `current`:
  `term => list<{id:int, title:string}>`, the linked targets in value order, one list per
  alignment term (empty list when the term has no values).
- The partial writes, on `.oer-anchor-slot`, one JSON attribute `data-recatalog`:
  `{"allowed": bool, "reason": string, "current": {term: [{id, title}]}}`. `allowed` and
  `reason` come from §2, decided on the server for this user and this item; `reason` is the
  translated text shown when the user holds the re-cataloguing privilege but the rule refuses
  (empty otherwise, so a user without the privilege sees nothing new).
- `drawer.js` loses the `fetch`; it re-emits `oer:drawer-rendered` synchronously with
  `{itemId, content}`. Table rows lose `data-api-url`.
- `drawerDetails.js` reads the slot's attribute through the pure `core/anchorData.js`
  (`parseRecatalog()`: malformed or missing data means «not allowed», never an exception) and
  emits `oer:anchor-slot` with `{itemId, recatalog, slot, bar}`. Raw JSON-LD is gone from the
  contract.
- `termPicker.buildDimensionSelector(term, label, values, isHelper)` takes the `{id, title}` list;
  the Etapa helper passes `[]`. `recatalog.js` mounts the editor only when `recatalog.allowed`,
  shows `recatalog.reason` otherwise, and loses the `if (!itemJson)` branch and its copy.
  `config.canRecatalog` (one flag for the whole page) is replaced by the per-item decision.
- Retired as dead code (TASK-035 «de paso»): `drawerTitle()`/`drawerRows()`/`DRAWER_FIELDS` of
  `core/drawerModel.js` with their tests (`TERM_LABELS` stays), and `core/values.js`, whose only
  callers were the JSON-LD readers above.

## 2. Who may re-catalogue (curricular alignment and thematic axes)

Owner decision, 2026-11-18:

| State | `rea_curador` / `global_admin` | `rea_proponente` (only REA they own) |
| --- | --- | --- |
| Borrador (no status, private) | yes | yes |
| Propuesto | yes | no |
| Rechazado (private) | yes | yes |
| Publicado | yes, directly (audited and undoable) | no |
| Out of flow (public without Publicado) | yes (to regularise) | no |
| Descartado | no (read only until «Reabrir») | no |

The AI proposal (`ai-propose`, `-status`, `-cancel`, `ai-evaluate`) and governance batches stay
for `rea_curador` and `global_admin` only.

Implementation: the pure `Service\Workflow\RecatalogRule` (host-tested), used by the server and,
through `allowed`/`reason`, by the panel, so the UI never offers what the server refuses.

- `profile(role)`: `global_admin` and `rea_curador` → curator; `rea_proponente` → proposer; any
  other role → none (refused; the ACL already denies them the actions).
- `denial(status, isPublic, profile, isOwner, canUpdate)` returns null (allowed) or a code, in
  this order: `role`; `discarded` (everyone); `no_update` (no native `update` on the item:
  IsolatedSites or a foreign item); curator → allowed; proposer → `not_owner` unless they own the
  item, `status` unless the item is private and Borrador or Rechazado — the same condition as
  `WorkflowStatus::canPropose()`. A Rechazado REA that is public is out of flow, hence a curator's.
- Each code has a translatable message (`RecatalogRule::MESSAGES`, `// @translate`).

Enforced in `IndexController` before any work, on every curricular/axes entry of the panel:

- `recatalog-preview`, `recatalog-apply`, `recatalog-undo`: refused with
  `{updated:false, error:<code>, message}`; an unreadable item is `not_found`.
- `recatalog-undo` additionally needs the `governance-apply` privilege when the last event is a
  governance one (`UndoRouter` would otherwise let a proposer undo a licence change).
- `recatalog-last-event` answers `{event:null}` when the rule refuses, so the panel offers no undo.
- `publish-proposal` checks the rule before writing a pending re-cataloguing (always allowed for a
  curator in the states that can be published; defensive).
- `governance-apply` (licence and authorship) is not curricular/axes and is unchanged.

The native API still decides each write item by item (NFR-003): the rule narrows, never widens.

## 3. How the proposer reaches the panel

`rea_proponente` gets, on `IndexController`, the panel reads and the re-cataloguer:
`drawer-details`, `drawer-history`, `search-terms`, `curriculum-tree`, `recatalog-preview`,
`recatalog-apply`, `recatalog-last-event`, `recatalog-undo`. Not the master view (`index`,
`search`), visibility, governance, AI, batches or statistics.

«Mis propuestas» opens the same sidebar panel: each row gets a «Catalogar» trigger
(`a.oer-open-drawer.sidebar-content`, as in the master view), the page loads the same
`asset/js/main.js` with the same configuration attributes (a shared partial,
`index/panel-config.phtml`, so both pages declare the same URLs) and `aiEnabled` off. The panel
shows the editor or the reason from §2, and the workflow buttons follow their own privileges as
before (a proposer sees none there; proposing stays on the item page).

Curriculum searches as a non-administrator depend on TASK-069 (the curriculum is in no site, so
IsolatedSites hides it). The container check sets `limit_to_granted_sites = 0` on its throwaway
users and says so.

## 4. Risks

- The re-cataloguer is the high-risk write surface: the apply path (`RecatalogService`) is not
  touched; only the entry guards and the pre-fill source change.
- Contract change of `oer:anchor-slot` (second owner change in four tasks): one producer
  (`drawerDetails.js`), one consumer (`recatalog.js`); `buildDimensionSelector` has two callers
  (dimension and Etapa helper), both updated.
- A proposer with `drawer-history` reads the curation history of items they can read natively,
  which the item page already shows them; the history becomes private with TASK-074.

## 5. Verification

- Host: `RecatalogRuleTest` (whole table), `IndexControllerTest` (each guarded action, refusal
  codes, governance undo, last event), `ReaAclTest` (full matrix), `ItemPanelDataTest`
  (`current`), JS `anchorData.test.js`.
- Container 8080 with throwaway non-administrator users (`test/container/recatalog-rules-check.php`):
  private REA re-catalogued as `rea_curador` and as the owning `rea_proponente` (Borrador and
  Rechazado); refusals for the proposer on Propuesto, Publicado and a foreign REA, for everyone on
  Descartado; `global_admin`; the partial carries `current` for a private item. Everything created
  is deleted at the end; 71 REA by the public API and no residue.
- Not covered by the suites: the panel in a real logged-in browser (owner's review in the admin).
