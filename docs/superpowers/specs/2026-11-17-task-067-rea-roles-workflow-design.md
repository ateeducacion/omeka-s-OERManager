# TASK-067: REA roles, permissions and workflow — design

Implements [ADR-0023](../../decisions/0023-profiles-workflow-shared-mediateca.md) §1 (profiles and
roles) and §2 (workflow states), milestone 1 of the [1.0 plan](2026-11-17-v1-production-plan.md).
Absorbs TASK-038 (publishing outside the flow is detected, not blocked). Written 2026-11-17 on
branch `feature/task-067-rea-roles-workflow`.

Facts checked in the development container before writing (Omeka 4.2, IsolatedSites v1.0.5):

- `Omeka\Module\Manager::sortModules()` runs `ksort()` on the module ids, and Laminas attaches each
  module's `onBootstrap()` in that order. `IsolatedSites` therefore bootstraps before `OERManager`,
  so `site_editor` already exists in the ACL when OERManager registers its roles.
- IsolatedSites adds `site_editor` (parent `editor`) in `onBootstrap()`, denies item/media
  `update`/`delete` without a permission on one of the item's sites, and limits item searches in
  admin routes to the granted sites (`limit_to_granted_sites`, default 1) with an inner join on
  `item.sites`: an item that belongs to no site is invisible to an isolated user.
- Omeka's `perform-job.php` calls `Application::init()`, so `onBootstrap()` runs in every Job and
  the REA roles exist there. Isolation does not (no route match).
- An API search with `limit => 0` runs only the `COUNT` query and hydrates nothing.
- Negated property queries (`neq`, `nex`) match items that have no value for the property.
- Development data: 71 `lrmi:LearningResource` items, all public; 69 in site 1 «RECURSOS EDUCATIVOS
  ABIERTOS»; 2 with `curation:status` = Propuesto (public), 69 with no status. No user holds a REA
  role; user 2 and user 10 curate today as `editor`.

## 1. Roles

| Role | Label | Parent | Fallback parent (no `site_editor`) |
| --- | --- | --- | --- |
| `rea_proponente` | «Proponente REA» | `site_editor` | `author` |
| `rea_curador` | «Curador REA» | `site_editor` | `editor` |

- Registered in `Module::onBootstrap()` with `addRole()` and a translatable `addRoleLabel()`, so they
  appear in the user form's role select.
- **Parent choice.** `site_editor` when the ACL has it. Without IsolatedSites (another instance),
  the fallback keeps the least privilege that still lets the role work: a proposer only needs to
  create and edit their own items (`author`, `OwnsEntityAssertion`); a curator must edit any REA
  (`editor`). Neither fallback grants more than the native role already does in that instance.
- **Load order.** Relies on the core's `ksort()` above. A container check asserts
  `inheritsRole('rea_curador', 'site_editor')`; a host test covers the fallback.
- **Deactivation risk (not handled, documented).** Omeka has no deactivation hook in
  `AbstractModule`. If the module is deactivated (not uninstalled), a user with a REA role has a
  role the ACL no longer knows and Laminas throws on their requests. Uninstall instead, or move the
  users first. IsolatedSites has the same property for `site_editor`.

### Uninstall

`Module::uninstall()` moves every user with a REA role to `site_editor`, or to the role's fallback
parent if `site_editor` is not in the ACL (one `UPDATE user SET role = ? WHERE role = ?` per role,
through `Omeka\Connection`). Nothing else is removed: `curation:status`/`curation:note` values are
native RDF and stay readable.

### Migration in development

Nothing is reassigned automatically. Users who curate in development with `editor` lose
OERManager after this change (ADR-0023, Consecuencias). To restore access, a global administrator
edits the user (Admin → Users → Edit → Role) and picks «Curador REA» or «Proponente REA»; the
user also needs a site permission on the REA site (Sites → REA site → User permissions) because
isolation and IsolatedSites' write denial both depend on it. Until TASK-069, give a non-administrator
curator `limit_to_granted_sites = 0` (user settings) if they must search the curriculum, or the
cascade, tree and AI find nothing.

## 2. Permission matrix

OERManager grants **only** to `rea_proponente`, `rea_curador` and `global_admin` (which the core
allows everything). `author`, `editor`, `reviewer`, `site_admin`, `site_editor` and `researcher`
get nothing. The core allows `site_admin` («Supervisor») everything (`AclFactory::addRulesForSiteAdmin`),
so it is denied OERManager's three controllers explicitly; `global_admin` keeps everything. The matrix
lives in one pure class (`Permissions\ReaAcl::GRANTS`); `onBootstrap()`
applies it and a host test pins it, including that every `*Action` of the three controllers has a
decision.

| Resource · privilege | `rea_proponente` | `rea_curador` | `global_admin` |
| --- | --- | --- | --- |
| Index · `propose` | yes | yes ¹ | yes |
| Index · `my-proposals` | yes | — | yes |
| Index · `index`, `search` (master view) | — | yes | yes |
| Index · `search-terms`, `curriculum-tree` | — | yes | yes |
| Index · `drawer-details`, `drawer-history` | — | yes | yes |
| Index · `recatalog-preview`, `recatalog-apply`, `recatalog-last-event`, `recatalog-undo` | — | yes | yes |
| Index · `governance-apply` | — | yes | yes |
| Index · `ai-propose`, `ai-propose-status`, `ai-propose-cancel`, `ai-evaluate` | — | yes | yes |
| Index · `set-visibility` | — | yes | yes |
| Index · `reject-proposal`, `publish-proposal`, `discard`, `unpublish`, `reopen` | — | yes | yes |
| Index · `config` | — | — | yes |
| Stats · `index`, `export`, `layout` | — | yes | yes |
| Batch · `form`, `preview`, `apply`, `status`, `cancel`, `recent`, `undo` (own batch) | — | yes | yes |
| Batch · `undo-any-batch` | — | — | yes |

¹ **Decision to confirm by the owner.** ADR-0023 §1 lists «propose» only for the proposer. A user
holds one global role, and ADR-0023 says team members are «both proposers and curators»: without
`propose` a curator could not send a REA they added into the flow, and could not re-propose a
rejected REA on someone's behalf (behaviour kept since the RF-016 final review). Granting it adds no
power a curator lacks (the curator can already publish). «Mis propuestas» stays proposer-only, as
the ADR says.

Per-item reach is not this matrix: every write goes through `$api->update()`, so the native ACL —
IsolatedSites' site denial included — decides item by item, in requests and in Jobs.

The UI follows the same privileges: the item page and the panel show a workflow button only if the
user holds that action's privilege **and** the state allows the transition. The old «curator =
`view-all`» test goes away: `site_editor` inherits `view-all` from `editor`, so a proposer would
have seen the curator's buttons.

## 3. State machine

`curation:status` literals compared by exact equality (`WorkflowStatus`, pure). Borrador is the
absence of a value. Visibility is the native `o:is_public`.

| State | `curation:status` | Visibility after entering |
| --- | --- | --- |
| Borrador | — | unchanged |
| Propuesto | `Propuesto` | private when entered by «Despublicar» or «Reabrir»; unchanged by «Proponer» |
| Rechazado | `Rechazado` | unchanged |
| Descartado | `Descartado` | private |
| Publicado | `Publicado` | public |

| Transition | From | To | Who | Guard |
| --- | --- | --- | --- | --- |
| Proponer | Borrador, Rechazado | Propuesto | proposer, curator | native `update` on the item |
| Rechazar | Propuesto | Rechazado | curator | reason required (≤200 chars, free text until TASK-039) |
| Descartar | Borrador, Propuesto, Rechazado | Descartado | curator | reason required; item becomes private |
| Reabrir | Descartado | Propuesto | curator | — (stays private) |
| Confirmar y publicar | Propuesto; or any public REA not Publicado nor Descartado (regularising «fuera del flujo») | Publicado | curator | optional re-cataloguing first; integrity must be `ok` ignoring `published_out_of_flow`; item becomes public |
| Despublicar | Publicado; or any public REA (regularising) | Propuesto | curator | reason required; item becomes private |

Notes:

- **Regularising out-of-flow REA** is not spelled out in ADR-0023 («appears in the queue to be
  regularised»). The two curator transitions above are the minimum that lets a curator act on the
  warning: confirm it as published (records who and when) or send it back to the queue privately.
  Listed for the owner's review.
- Descartado → Publicado is not allowed: a discarded REA must be reopened first.
- `curation:note` holds the last reason (rejection, discard, unpublish) and is cleared by Proponer,
  Reabrir and Confirmar y publicar.
- **Confirmar y publicar** (panel): one request carries the item id and, if the curator has open
  re-cataloguing changes, the same `alignment[...]`/`justification[...]` fields as `recatalog-apply`.
  The controller applies them with `RecatalogService::apply()` first; if that throws or reports an
  error, nothing is published. Then it re-reads the item, runs the integrity gate and publishes. A
  re-cataloguing that succeeded and a gate that then fails leave the new alignment written and the
  REA unpublished, with the issues listed (the write is reversible from the panel's undo).
- **Writes** keep the targeted `clear_property_values` + `collectionAction => append` pattern
  (WorkflowService's isPartial trap): only `curation:status` and `curation:note` are cleared.

### RDF of each state (ADR-0002)

Every status value the module writes carries a native value annotation, in the same format and
precision as the re-cataloguer (`CurationWriter::annotation()`, one microsecond stamp):

```text
curation:status "Publicado"
  @annotation dcterms:contributor "<user name>"
              dcterms:modified    "2026-11-17T10:21:03.123456+00:00"
              dcterms:provenance  "OERManager flujo: Publicado"
curation:note  "<reason>"            (Rechazado, Descartado, Despublicar only)
```

The status value, the note and every annotation value are written **private** (`is_public => false`,
security review of slice 2): the public API must not show who published a REA or why it was
rejected. Readers keep working: curators and proposers inherit `view-all` from `site_editor`, and
Omeka's value visibility filter also shows an owner the private values of their own items (the
`author` fallback). The re-cataloguer's ADR-0002 annotations (`CurationWriter::annotation()`) stay
public as before and expose cataloguers' names on published REA — **for the owner to decide**, not
changed here.

Publicado is therefore recorded (who and when) instead of deleting the status as before.

### Who may change the workflow values (security review)

`curation:status`/`curation:note` are plain literals, so anyone with item update — a
`rea_proponente` inherits it from `site_editor` — could write «Publicado» by hand in the native form
or the API, make the REA public and skip the curator without raising «Publicado fuera del flujo».
A listener on `ItemAdapter` `api.hydrate.post` (`Module::guardWorkflowValues`, rules in
`Workflow\StatusGuard`) rejects with a validation error any create or update whose resulting status
or note differs from the stored ones, unless:

- the write comes from `WorkflowService` (a request-scoped flag around its own commit), or
- the user is `global_admin` (the initial import, TASK-072).

Compared as text, order-insensitive, so the native form resubmitting unchanged values passes.
It applies to items that are or were `lrmi:LearningResource`; an item that only now becomes one
counts as having no previous values, so a status cannot be written under another class and kept by
switching the class back. Covers the API, the native form and batch update (each sub-update hydrates
the same way). One small query per item save (old class and workflow values). Other items keep
`curation:status` free for other modules. Not covered: hand edits of the annotations themselves. The
annotation of the previous state disappears with its value; the full history of who moved a REA is
not kept (out of scope: ADR-0015 events are for reversible curation, not for workflow).

## 4. Integrity warnings

Both are warnings computed in `IntegrityPolicy` from flat data projected by `IntegrityChecker`:

| Code | Message | Rule |
| --- | --- | --- |
| `published_out_of_flow` | «Publicado fuera del flujo: el REA es público sin el estado Publicado.» | `o:is_public` and status ≠ Publicado |
| `missing_rea_site` | «El REA no está en el sitio REA: el equipo no podrá editarlo.» | REA site configured and the item is not in it |

Cost: visibility and status come from the representation already loaded (no query). Site
membership is one `returnScalar` search per request (`site_id` = REA site, ids only), cached in
`ReaSite`, plus one read of the site to check it still exists — the same pattern as the retired-term
ids (TASK-064). No query per row. At 3 000 REA the id list is 3 000 integers.

**New setting** `oermanager_rea_site_id` (configuration page, «Sitio REA»). Saving checks that the
site exists; an unknown id is not stored and the page says so. While it is empty the rule is not
evaluated, and the configuration page shows a warning that «REA sin sitio REA» is off.
In the development container the REA site is site 1; the setting is not set by this task.

**Under isolation**, the membership search is limited to the user's granted sites; a curator
without a permission on the REA site would see every REA flagged. That is a configuration error the
warning makes visible.

### Existing data (owner decision)

The 69 public REA with no status in development (and the 2 public Propuesto) become «Publicado fuera
del flujo». This task does **not** rewrite them in an upgrade. Options for the owner:

1. **Leave the warning** and let curators regularise from the queue (TASK-070) one by one, with
   «Confirmar y publicar» or «Despublicar».
2. **An explicit regularisation harness** (`test/container/` style, run by hand on a copy first):
   for every public REA without a status, write `Publicado` annotated with a fixed contributor
   («regularización TASK-067») and the run's stamp. Fast, but it records a publication nobody
   reviewed.
3. **Same harness, Propuesto + private**: every such REA goes to the queue and leaves the public
   site until a curator confirms it. Safe, but empties the public catalogue until curated.

The warning works in all three cases.

## 5. «Mis propuestas» and the queue counter

- **Mis propuestas** (`my-proposals`, proposer): the user's own REA (`owner_id` = user,
  `lrmi:LearningResource`), newest first, paginated, with title (link to the native item page,
  where Proponer lives), status badge, reason (`curation:note`) and visibility. One search per page;
  values come from the representations already loaded. It is a separate page: the proposer has no
  privilege on the master view. Under isolation it shows only items in the proposer's granted sites
  — an item in no site (not yet in the REA site) does not appear, and is the «REA sin sitio REA» case.
- **Navigation.** «OER Manager» now requires `index`, so it is shown to curators only; a separate
  top-level «Mis propuestas» entry requires `my-proposals`. Under «OER Manager», a «Cola (N)» entry
  (curators) links to the master view filtered by `status=proposed` (TASK-070 builds the real queue).
- **Counter** N = REA in Propuesto + public REA whose status is none of Propuesto, Publicado,
  Descartado. Two `COUNT`-only searches (`limit => 0`): the API cannot OR a property condition with
  `is_public` in one query. The label is set on `MvcEvent::EVENT_RENDER` for admin, non-AJAX
  requests of users holding `index`, so curators pay two COUNTs per admin page and nobody else pays
  anything.
- **Descartado** is excluded from the counter and from the statistics (`CatalogSnapshot` adds
  `curation:status neq Descartado`, so every block and the CSV skip it).
- The master view gains a «Estado» filter (`status` = `draft`, `proposed`, `rejected`, `discarded`,
  `published`, `out_of_flow`) replacing the «Propuestos» checkbox; `proposed=1` still works.

## 6. IsolatedSites effects

- Both REA roles inherit IsolatedSites' denials (no template, user or site administration; no item
  write outside granted sites) and its isolation.
- Every check by a non-administrator that searches the curriculum depends on TASK-069 (the curriculum
  is in no site). The container harness gives its throwaway users `limit_to_granted_sites = 0` only
  where such a search is exercised, and says so in its output.
- Jobs: no isolation (accepted in ADR-0023 §3), but the native ACL still decides each write; the
  harness checks it with a governance batch launched by a `rea_curador` that includes an item outside
  the curator's sites.

## 7. Risks

1. Load-order reliance on the core's `ksort()` (mitigated by a container assertion).
2. Deactivating instead of uninstalling strands REA-role users (documented above).
3. Development curators with `editor` lose access until reassigned by hand.
4. «Publicado fuera del flujo» flags most of the development catalogue until the owner decides §4.
5. A curator without a permission on the REA site sees «REA sin sitio REA» everywhere.
6. Status annotations on public values are visible in the public JSON-LD (contributor name), as the
   re-cataloguer's annotations already are.

## 8. Test plan

Host (TDD, red first):

- `ReaAclTest`: full role × privilege matrix on a functional ACL fake (inheritance, `global_admin`
  allow-all), site_editor and editor get nothing, parent choice and fallback, labels, every
  controller action decided.
- `ModuleRuntimeTest`: bootstrap registers roles before rules; uninstall moves users (with and without
  `site_editor`); navigation counter listener.
- `WorkflowStatusTest`: every transition guard, badges, out-of-flow and queue predicates.
- `WorkflowServiceTest`: each transition's payload (status, note, visibility, annotation, targeted
  clear, append), invalid transitions write nothing.
- `IndexControllerTest`: discard/unpublish/reopen/my-proposals, confirm-and-publish (re-catalogue
  failure does not publish; gate ignores out-of-flow), UI flags by privilege.
- `IntegrityPolicyTest`/`IntegrityCheckerTest`: both new warnings, not evaluated without a site.
- `ReaSiteTest`, `QueueCounterTest`, `CatalogSnapshotTest`, `MasterViewQueryTest`, `ConfigPayloadTest`.

Container (`test/container/rea-roles-check.php`, disposable data only): creates throwaway users
(`rea_proponente`, `rea_curador`, `site_editor`, `editor`) with site permissions on the REA site,
throwaway REA in it, and one throwaway item in another site; logs in over HTTP as each user and
checks allowed and denied routes, the whole flow (propose, reject, re-propose, confirm and publish,
unpublish, discard, reopen), the warnings, «Mis propuestas», the counter, and a Job by the curator;
then deletes users, permissions and items in batch, and confirms 71 REA, 0 test items and 0 test
users remain.

## 9. Slices

1. Roles, ACL matrix, uninstall.
2. States and transitions, «Confirmar y publicar», UI buttons.
3. Integrity warnings and the REA site setting.
4. «Mis propuestas», queue counter, Descartado out of statistics, status filter.
