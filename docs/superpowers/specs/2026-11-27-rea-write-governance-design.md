# REA write governance: visibility and writes follow the curation workflow

- **Date:** 2026-11-27
- **Status:** draft for the owner's review
- **Tasks:** TASK-078 (a REA's visibility changes only through the workflow) and TASK-079 (who
  writes a REA, by state and role, on every write path), to be added to the backlog on approval
- **Decision record:** ADR-0026, amending ADR-0023 §2, its consequences and its addendum 2
- **Requirements:** RF-016, NFR-002, NFR-003, NFR-010
- **Supersedes in practice:** TASK-038 (absorbed by TASK-067 with detection only; closed by
  TASK-078 with prevention)

## 1. Problem

Two incoherences reported by the owner on 2026-11-27, both accepted on purpose by ADR-0023:

1. **A REA that the workflow has not accepted can be public.** A proposer owns their REA and holds
   Omeka's `update` on it, so the native item form, the REST API or the native batch edit can set
   `o:is_public = true` on a Borrador or Propuesto REA. ADR-0023 §2 decided «detected, not
   blocked»: the REA shows the warning «Publicado fuera del flujo» and joins the queue. The module's
   own `set-visibility` endpoint (`IndexController::setVisibilityAction`) makes REA public or private,
   one by one or in a batch, without looking at the workflow state, so the module itself creates REA
   that are out of flow.
2. **The module forbids what Omeka allows.** ADR-0023 addendum 2 lets a proposer re-catalogue only
   their own private Borrador or Rechazado REA, but `RecatalogRule` guards only the module's panel
   and wizard. Through the native item form, the API, the native batch edit and the media pages the
   same proposer edits, re-orders media, deletes or re-classifies the REA in any state, including
   Propuesto and Publicado. ADR-0023 also accepted that a proposer edits someone else's REA of the
   REA site («separation is recorded, not enforced»).

Only the workflow values themselves are protected today: `StatusGuard` (on `api.hydrate.post`)
refuses a hand-made change of `curation:status` or `curation:note` by anyone but `WorkflowService`
or `global_admin`.

## 2. Goals and non-goals

**Goals**

- G1. A governed REA's visibility (item and media) changes only through a workflow transition, or
  by `global_admin`.
- G2. Every write to a governed REA — values, class, template, item sets, sites, media, owner,
  deletion — is decided by one policy, by workflow state and role, whatever the path: the native
  admin, the REST API, batch edits, Jobs or the module's own screens.
- G3. The UI never offers what the server refuses: the module's screens ask the same policy, and
  Omeka's own buttons follow it wherever Omeka asks the ACL.
- G4. Items that are not governed REA (other sites of the shared mediateca, other classes) are not
  affected in any way.
- G5. No custom table (NFR-002), no patch to Omeka's core or to IsolatedSites, no rewrite of
  existing data.

**Non-goals**

- Access control of the files themselves (`/files/original/...` is served by the web server, not
  by Omeka's ACL; the Access module is out of scope).
- Writes that bypass Omeka's API with raw SQL (Omeka's `UpdateSiteItems` job, SQL modes of import
  modules, direct database access): covered only by detection (§12, residual risks).
- Notifications, batch publication, per-class ACL for curators (out of 1.0 by ADR-0023).

## 3. Facts of Omeka S 4.2 this design rests on

Read in the development container on 2026-11-27 (Omeka 4.2, IsolatedSites v1.0.5).

| # | Fact | Source |
| --- | --- | --- |
| F1 | Every create and update goes through `hydrateEntity()`: `authorize($entity, $operation)` on the entity **in its original state**, then `api.hydrate.pre`, `validateRequest`, `hydrate`, `api.hydrate.post`, `validateEntity`. An error in the error store refreshes the entity on update and throws `ValidationException`. | `AbstractEntityAdapter::hydrateEntity()` |
| F2 | Delete is `deleteEntity()`: `authorize($entity, 'delete')`, `api.find.post`, `remove`. The API manager triggers `api.delete.pre` (request only) before the adapter. | `AbstractEntityAdapter::deleteEntity()`, `Api\Manager::execute()` |
| F3 | Batch create, update and delete call the API once per id with `continueOnError`; the native batch edit and batch delete, and the `BatchUpdate` job, pass `continueOnError => true`: a refused item is skipped and logged, the rest proceed. | `batchUpdate()`, `ItemController:168,329`, `Job/BatchUpdate.php` |
| F4 | A media created on its own needs `update` on its item: `MediaAdapter::hydrate` calls `authorize($item, 'update')`. A media's owner is its item's owner. | `MediaAdapter::hydrate()`, `hydrateOwner()` |
| F5 | An item update with `o:media` re-positions the listed media, can set each listed media's `o:is_public`, creates new ones (each through `MediaAdapter::hydrateEntity`) and removes the unlisted ones (orphan removal, no media `delete` authorisation). | `ItemAdapter::hydrate()` |
| F6 | `o:is_public` defaults to `true` on create when the request does not carry it (`setIsPublic($request->getValue('o:is_public', true))`). The native item form sends it from a hidden input; the media rows of the form send `o:media[i][o:is_public]`. | `AbstractResourceEntityAdapter::hydrate()`, `admin/item/form.phtml`, `manage-media.phtml` |
| F7 | Site assignment through the item: assigning or removing a site needs `can-assign-items` on that site, except on create into sites with `assignNewItems`. A create without `o:site` assigns every `assignNewItems` site. | `ItemAdapter::hydrate()` |
| F8 | The site «Resources» page dispatches `UpdateSiteItems`, which rewrites `item_site` with raw SQL (`add`, `replace`, `remove`, `remove_all`): no API, no events, no ACL per item. | `Job/UpdateSiteItems.php` |
| F9 | `change-owner` is not granted to `author`, `reviewer` or `editor`; only `site_admin` (allow-all) and `global_admin` hold it. | `AclFactory` |
| F10 | `author` holds `update`/`delete` on Item and Media with `OwnsEntityAssertion`; `editor` (and so `site_editor`) holds them on every Item and Media, plus `batch_update(_all)`, `batch_delete(_all)` and `view-all`. | `AclFactory::addRulesForAuthor/Editor` |
| F11 | IsolatedSites adds `deny(site_editor, [Item, Media], [update, delete, edit], $denyIfNoAccess)`: a `site_editor` writes only items of the sites they hold a permission on. | `IsolatedSites/Module.php:254-264` |
| F12 | **Laminas ACL keeps one rule per (role, resource, privilege)**: a second `allow`/`deny` on the same triple replaces the first. A rule whose assertion returns false does not apply, and the lookup continues with the role's parents. | `laminas-permissions-acl/src/Acl.php` `setRule()`, `getRuleType()` |
| F13 | Value annotations are written only through their resource: `ValueAnnotationAdapter` refuses create, update and delete (`AbstractAdapter` stubs). | `ValueAnnotationAdapter` |
| F14 | The visibility filter applies to each resource on its own `is_public` (plus ownership and `view-all`); a media's visibility is independent of its item's. | `Db/Filter/ResourceVisibilityFilter` |
| F15 | Jobs run with their owner's identity, so API writes inside a Job are authorised as that user. | Omeka `Job\Dispatcher` |

Module facts:

- `rea_proponente` and `rea_curador` inherit from `site_editor` (ADR-0023 §1; `ReaAcl::FALLBACK_PARENTS` when IsolatedSites is absent), and `ReaAcl` sets rules only on module controllers, never on `Omeka\Entity\*`. So a rule on those two roles for an Omeka entity replaces nothing (F12).
- `StatusGuard::run()` already scopes `WorkflowService`'s writes with a per-request depth counter.
- `ReaWizardSave` creates the item with `o:is_public = false` but its media without `o:is_public`, so they are created public (F6).

## 4. What a governed REA is

The workflow governs learning resources of the REA site, not every item of the site (the site's
other items belong to its site editors) and not learning resources of other sites of the shared
mediateca (owner, 2026-11-27).

```
governed(state) = class is lrmi:LearningResource
                  AND ( the item belongs to the REA site (oermanager_rea_site_id)
                        OR the item carries a curation:status value )
```

A write is governed when the item is governed **before or after** it:

```
governedWrite = governed(stateInDatabase) OR governed(stateAfterHydration)
```

- **Before or after** stops an item from escaping by losing the class or the site in the same write
  in which it is changed (the pattern `StatusGuard` already applies to the class).
- **The status keeps a REA governed outside the site.** A REA that has entered the workflow carries
  `curation:status`, which only `WorkflowService` and `global_admin` can write. So a REA that loses
  the REA site through raw SQL (F8) stays governed. Only a never-proposed draft depends on the site
  alone (§12).
- **REA site not configured:** the predicate falls back to the class alone (fail closed). The
  module configuration shows «Sitio REA sin configurar: el flujo gobierna todos los ítems de clase
  REA».
- **One implementation:** a pure `GovernedRea::is(?string $class, array $siteIds, bool $hasStatus,
  ?int $reaSiteId): bool`, used by every guard and by the catalogue (§10).

## 5. The policy

### 5.1 Inputs

`ReaWritePolicy::denial(string $action, ReaState $before, Actor $actor): ?string` is pure and
returns null or a refusal code.

- `ReaState` is the item as stored: class, site ids, workflow status, `is_public` and owner id. It
  is read from the database, never from the hydrated entity, because in `api.hydrate.post` the
  entity already holds the request's values.
- `Actor` is the role class plus the user id:
  - `admin` is `global_admin`;
  - `curator` is `rea_curador`;
  - `proposer` is `rea_proponente`;
  - `other` is every other role (`site_admin`, `editor`, `site_editor`, `reviewer`, `author`, `researcher`), including API keys of those users and anonymous requests.
- The state is the one `WorkflowStatus` already derives:
  - **Borrador:** no status and private.
  - **Propuesto**, **Rechazado** and **Descartado**: the stored status.
  - **Publicado:** the stored status and public.
  - **Fuera del flujo:** public without Publicado.

### 5.2 Actions

| Action | What it covers |
| --- | --- |
| `create` | Create an item that is governed after hydration. Decided in layer 3, since nothing is governed before. |
| `edit` | Any change to the item through the adapter: values, class, template, item sets, sites, and `o:media` re-ordering, removal and creation. |
| `media` | Create, update or delete one media on its own (media pages, `api/media`). It is decided on the item's state. |
| `delete` | Delete the item. |
| `visibility` | Change the item's `o:is_public`. |
| `owner` | Change `o:owner`. |
| `leave` | A write after which the item is no longer governed. |
| `enter-public` | An existing public item becomes governed, for example by gaining the class or the REA site. |
| `transition:*` | Workflow transitions, which stay with `WorkflowService` and their privileges as today. `transition:withdraw` is new (§8). |

### 5.3 Matrix

For `edit` and `media`. Recommended defaults are marked with the decision they depend on (§14).

| State | proposer, own REA | proposer, other's REA | curator | other roles | admin |
| --- | --- | --- | --- | --- | --- |
| Borrador | yes | **no** (D2) | yes | **no** (D4) | yes |
| Propuesto | **no**, only «Retirar propuesta» (D1) | no | yes | no | yes |
| Rechazado | yes | no | yes | no | yes |
| Publicado | no | no | yes (audited, undoable) | no | yes |
| Fuera del flujo | no | no | yes (to regularise) | no | yes |
| Descartado | no | no | no (until «Reabrir») | no | yes |

**`create`**: proposer, curator and `global_admin`. Other roles are refused with `role` (D4): a
`site_editor` of the REA site without a REA role cannot create a learning resource there. The new
REA is always created private (§6.1).

**`delete`**:
- the proposer deletes only their own Borrador (D5);
- the curator does not delete, and uses «Descartar» (D5);
- other roles: no;
- `global_admin`: yes.

**`visibility`**: nobody by hand except `global_admin`. Transitions change it inside the
workflow scope (§7). How a REA's media follow its item is described in §6.

**`owner`**: `global_admin` only. Omeka already limits it to `site_admin` and `global_admin` (F9),
and `site_admin` is an «other» role here.

**`leave`**: curator and `global_admin`. It is how a curator undoes a cataloguing mistake. The
proposer never leaves, not even from their own Borrador, because a draft could otherwise be turned
into an ordinary item and published.

**`enter-public`**: `global_admin` only, for the initial import of TASK-072. A **new** item that is
born governed and public is not refused: it is created private (§6.1).

The current `RecatalogRule` becomes a view of this matrix: «may re-catalogue» = `edit` allowed.
That is the same table as ADR-0023 addendum 2, extended to the D2 and D4 columns.

### 5.4 Refusal codes and messages (UI copy in Spanish)

| Code | Message |
| --- | --- |
| `state` | «Este REA está en revisión o publicado: solo el equipo curador puede cambiarlo.» |
| `proposed` | «Has propuesto este REA. Si necesitas cambiarlo, retira la propuesta.» |
| `not_owner` | «Solo puedes cambiar los REA de los que eres propietario.» |
| `discarded` | «REA descartado: no se puede cambiar hasta reabrirlo.» |
| `role` | «Este REA sigue el flujo de curación: solo lo cambian los perfiles REA.» |
| `visibility` | «La visibilidad de un REA la decide el flujo de curación: usa «Confirmar y publicar» o «Despublicar».» |
| `leave` | «Solo el equipo curador puede quitar a un REA su clase o el sitio REA.» |
| `enter_public` | «Un ítem público no puede pasar a ser REA: hazlo privado antes, o pídeselo a un administrador.» |
| `owner` | «Solo un administrador puede cambiar el propietario de un REA.» |
| `delete` | «Solo puedes eliminar tus REA en borrador; los demás se descartan.» |

## 6. Visibility (TASK-078)

### 6.1 Item

- **Update.** A change of `o:is_public` on a governed write is refused with `visibility` unless it
  happens inside the workflow scope for that item (§7) or the actor is `global_admin`. Both
  directions are refused: making a Publicado REA private without «Despublicar» is the opposite
  incoherence (Publicado but invisible).
- **Create.** A governed REA created by anyone but `global_admin` is **created private**, whatever
  the request says. The value is normalised, not refused, because the native add form sends
  public by default (F6) and refusing would make the native form unusable for proposers. A flash
  message says «Los REA se crean privados; se publican al curarlos». ADR-0023 §2 already says a
  REA is born private.
- **Entering governance while public** (`enter-public`) is refused, not normalised: making the item
  private silently would unpublish something that was public.

### 6.2 Media (D7)

- **Recommended rule:** the media of a governed REA are public exactly when the item is public. The
  value is derived, like `lrmi:educationalAlignment` in TASK-076:
  - `api.hydrate.post` sets every media to the item's resulting visibility (item update, nested
    media, and a media updated on its own);
  - a request asking for something else is normalised, not refused.
- **Why:**
  - Omeka creates media public by default (F6), and the wizard does too today;
  - a media's visibility is independent of its item's (F14), so a public media of a private draft
    is readable through `api/media` by anyone;
  - deriving the value closes this for every path without breaking the native forms.
- **Transitions:** «Confirmar y publicar» makes the media public and «Despublicar» makes them
  private, through the same derivation.
- **To verify in the spike (S0):** whether the development catalogue already holds public media of
  private REA. An anonymous read of 128 public media on 2026-11-27 found none whose item was
  private.

### 6.3 `set-visibility`

The module's endpoint refuses governed REA and answers with the `visibility` message. For a mixed
batch it applies the change to the non-REA items and lists the REA it skipped. This withdraws the
ADR-0018 addendum's escape route for REA (D3); it stays for other items.

### 6.4 Native forms

- On the item edit and add forms, and on the media edit form, of a governed REA:
  - the module locks the visibility control with a short script (events `view.edit.before`,
    `view.add.before`);
  - it adds the note «La visibilidad la decide el flujo de curación».
- The server rule does not depend on this script.

## 7. The workflow scope

`WorkflowScope` replaces `StatusGuard`'s depth counter as a service of its own, with one shared
instance per request:

```php
$scope->run(int $itemId, callable $write): mixed
$scope->covers(?int $itemId): bool
```

- **It is tied to the item.** The scope covers only the item the transition acts on, so a nested
  write to another item, or a listener reacting to it, gets no bypass. That is stronger than
  today's counter.
- **Who uses it:**
  - `WorkflowService` transitions (propose, reject, discard, reopen, publish, unpublish and the new
    withdraw);
  - the governance-boundary changes those transitions need.
- **What it relaxes:**
  - inside it, the guards accept changes of `curation:status`/`curation:note`, of `o:is_public` and
    of the media visibility for that item;
  - every other check still applies: the transition's own privilege, and the native ACL on the
    write.
- **What does not need it:**
  - the re-cataloguer, the wizard and the AI batch write as the user, under the policy, with no
    bypass;
  - the wizard creates private REA and needs no bypass for that.

## 8. New transition «Retirar propuesta» (D1)

- **What it does:** Propuesto → Borrador. It removes the status, keeps the item private and takes
  the REA out of the queue.
- **Who:** the REA's owner when they are a `rea_proponente` or a `rea_curador`. The curator can
  already reject.
- **Where:** a privilege `withdraw-proposal` on `IndexController`, offered in «Mis propuestas» and
  on the native item page next to the state badge.
- **History:** recorded in the curation history like the other transitions (ADR-0015), with who and
  when, because Borrador carries no status value to annotate.
- **Without it:** a proposer who spots a mistake after proposing depends on a curator rejecting
  the REA.

## 9. Enforcement architecture

### 9.1 Components

```
src/Service/Workflow/
  GovernedRea.php          pure predicate (§4)
  ReaState.php             value object: class, siteIds, status, isPublic, ownerId
  ReaStateReader.php       reads ReaState from the database by item id (memoised until the
                           item's next api.*.post)
  ReaWritePolicy.php       pure matrix (§5); RecatalogRule delegates to it
  WorkflowScope.php        per-item trusted scope (§7); StatusGuard uses it
  StatusGuard.php          unchanged rules, scope from WorkflowScope
src/Permissions/
  ReaWriteAssertion.php    Laminas assertion: denies update/delete on Item and Media when the
                           policy refuses (layer 1)
src/Listener/
  ReaWriteGate.php         api.hydrate.pre and api.delete.pre on Item and Media (layer 2)
  ReaWriteGuard.php        api.hydrate.post on Item and Media: visibility, media derivation,
                           boundary, owner, REA site on create, plus StatusGuard (layer 3)
```

The `Module.php` listeners move into `src/Listener/` classes built by factories, so they can be
tested without the module.

### 9.2 Layers

| Layer | Where it hooks in Omeka | Who it applies to | What it decides | How it fails |
| --- | --- | --- | --- | --- |
| 1. ACL assertion | `deny(['rea_proponente', 'rea_curador'], [Item, Media], ['update', 'delete'], ReaWriteAssertion)` in `ReaAcl::register` | REA roles | `edit`, `media`, `delete` on the original state (F1, F2, F4) | `PermissionDeniedException` (403) |
| 2. Write gate | `api.hydrate.pre` (create and update) and `api.delete.pre` on `ItemAdapter` and `MediaAdapter` | **every role** | the same actions, on the state read from the database | `PermissionDeniedException` (403) |
| 3. Write guard | `api.hydrate.post` on `ItemAdapter` and `MediaAdapter` | every role | changes that need before and after: `visibility`, `leave`, `enter-public`, `owner`, workflow values (`StatusGuard`); normalises media visibility and visibility on create; adds the REA site on create | error store, which becomes `ValidationException` (422 or form messages) and refreshes the entity (F1) |
| 4. Detection | integrity warnings and queue (existing) | — | what bypasses the API (§12) | warning, not refusal |

**Why both layer 1 and layer 2.**
- Layer 1 makes Omeka itself coherent. Every `userIsAllowed($item, 'update')` in Omeka's views and
  controllers then answers «no»: the item page's «Editar ítem», the browse row actions, the batch
  checkboxes, the media «Edit» links and every `authorize()` (F1, F2, F4).
- Layer 1 can only be set on the module's own roles. A rule on `site_editor` would replace
  IsolatedSites' deny, and a rule on `editor` would replace the core's allow (F12): a denying
  assertion would then leave `editor` with no rule at all, so default-deny on every item.
- Layer 2 carries the same policy for every role, so «other roles» are covered without touching
  their ACL rules.
- Layer 2 is authoritative and layer 1 is its mirror. Both call `ReaWritePolicy` on the same
  `ReaState`, so they cannot disagree; a test pins it (§13).

**How layer 1 composes with the existing rules.**
- When `ReaWriteAssertion` allows, the rule does not apply (F12). The lookup continues to
  `site_editor`: first IsolatedSites' site check, then `editor`'s allow. Nothing granted today is
  widened.
- When it denies, the write is refused before Omeka hydrates anything.

**Layer 3 order** (one listener, fixed order, so each check sees the previous one's result):
1. REA site on create;
2. media normalisation;
3. boundary (`leave`, `enter-public`);
4. visibility;
5. owner;
6. `StatusGuard`.

`deriveSubject` (TASK-076) stays after the guard and writes nothing the guard checks.

### 9.3 REA site on create

- When a `rea_proponente` or `rea_curador` creates an item of class `lrmi:LearningResource` without
  the REA site, layer 3 adds it to the entity. ADR-0023 says every REA lives in the REA site; the
  wizard already does this.
- This is a module rule on the entity. It does not widen `can-assign-items` for any other site
  (F7).
- «REA sin sitio REA» stays the warning for what still slips through.

### 9.4 Write paths and their outcome

| Path | Omeka entry | Layers | Outcome on a refused write |
| --- | --- | --- | --- |
| Native item add and edit form | `ItemController::add/edit` → `api->create/update` | 1, 2, 3 | REA roles do not see «Editar»; others get the form's error message |
| Native item batch edit (selected or by query) | `batchUpdate` or the `BatchUpdate` job, `continueOnError` | 1, 2, 3 per item | the refused REA are skipped and logged (F3); the batch form warns beforehand when the selection holds governed REA |
| Native item delete and batch delete | `api->delete` / `batchDelete` | 1, 2 | 403, or the item is skipped in a batch |
| Media edit page, media delete | `MediaAdapter` update/delete | 1, 2, 3 | 403 or the form message |
| Media added on its own (`api/media` create) | `MediaAdapter::hydrate` → `authorize($item, 'update')` (F4) | 1, 2, 3 | 403 |
| Media inside an item update (`o:media`) | `ItemAdapter::hydrate` (F5) | 1, 2 (item `edit`), 3 (media visibility) | the whole item update is refused |
| REST API with a key | same adapters | 1, 2, 3 | 403 or 422 with the message |
| Module: re-cataloguer, governance batch, AI batch Job | API as the user or the Job's owner (F15) | 1, 2, 3 | the existing refusal, now from `ReaWritePolicy` |
| Module: wizard | API as the user | 1, 2, 3 | the existing «no puedes continuar este REA» |
| Module: `set-visibility` | its controller, then the API | refused before the API (§6.3), and 3 | listed as skipped |
| Module: workflow transitions | `WorkflowService` inside `WorkflowScope` | 1 (transition privilege), 2, 3 with scope | unchanged |
| Site «Resources» page, `UpdateSiteItems` | raw SQL (F8) | 4 only | detected (§12) |
| CSVImport and BulkImport in API mode | API | 1, 2, 3 | skipped and logged by the importer |
| BulkImport SQL modes, EasyAdmin database tasks | raw SQL | 4 only | detected; spike S0 lists which ones run on REA |

## 10. One definition for the catalogue

The master view, the queue «Cola (N)», the statistics, the integrity checks and «Mis propuestas»
filter today by class alone. They move to the same predicate, `GovernedRea`, through one SQL
fragment shared by `MasterViewQuery`, `QueueCounter` and the statistics query. Without this, the
queue would show «Publicado fuera del flujo» on learning resources of other sites' teams, and the
statistics would count them. «REA sin sitio REA» keeps its meaning: a governed REA (with a status)
outside the REA site.

## 11. Errors, logging and UI coherence

- **Refusals:** 403 from layers 1 and 2, and an error-store message from layer 3, always with the
  Spanish message of §5.4.
  - The development container answers denials with HTTP 500 because of GlobalLandingPage (known
    since TASK-067). That is not changed here.
- **Logging:** every refusal is logged once at `warn`, from the layer that refused, with user id,
  role class, item id, action and code. No values are logged.
- **«Mis propuestas»:** each row's actions (Continuar, Retirar propuesta, Eliminar) come from
  `ReaWritePolicy`. A Propuesto REA opens read-only with the `proposed` message.
- **Native item page** (`view.show.page_actions`, already used):
  - REA roles lose «Editar ítem» through layer 1;
  - other roles see the note «Este REA sigue el flujo de curación: solo lo cambian los perfiles
    REA», because their Edit button cannot be hidden without touching their ACL rules (§9.2).
- **Native batch edit form:** a notice when the selection includes governed REA. They will be
  skipped, and the job's log names them.

## 12. Residual risks

| Risk | Why it stays | Mitigation |
| --- | --- | --- |
| Raw-SQL writers: `UpdateSiteItems`, BulkImport SQL modes, EasyAdmin tasks, direct database access | no API, no events (F8) | the status keeps a proposed REA governed (§4); integrity warnings and queue; these tools belong to site and global administrators; the TASK-071 runbook lists them |
| A never-proposed draft removed from the REA site with raw SQL | its only anchor is the site | it stays private and is not in the public site; «REA sin sitio REA» does not see it (no status); accepted and documented |
| `global_admin` | bypass by design (import, emergencies) | the curation history and Omeka's log |
| File URLs | served by the web server, not the ACL | random storage ids; out of scope (Access module) |
| SSRF of the wizard's link media (redirects, DNS rebinding in Omeka's `url` ingester) | separate decision pending since TASK-077 | not part of this design |

## 13. Verification

**Unit tests (host, PHPUnit)**

- `ReaWritePolicyTest`:
  - every cell of 6 states × 5 actors × own/other × each action;
  - the refusal codes;
  - `RecatalogRule` equal to `edit` for every cell.
- `GovernedReaTest`: class × site × status × site unset, before and after, including «loses the site
  but keeps the status» and «class removed and site removed in the same write».
- `WorkflowScopeTest`: covers only its item, nested scopes, exception safety.
- `ReaWriteGateTest`, `ReaWriteGuardTest`: entity stubs as in the existing module tests; create
  normalises visibility; update refuses visibility; media derivation; `leave`; `enter-public`;
  owner; REA site on create; order of the checks.
- **`ReaAclCompositionTest`:**
  - builds a Laminas ACL with Omeka's author, editor and site_editor rules and an IsolatedSites-like
    deny, then registers `ReaAcl`;
  - asserts that no rule of `site_editor`, `editor` or `author` was replaced;
  - asserts that the module's roles get exactly the policy's answer for update and delete.
  - This pins F12.
- **`LayerAgreementTest`:** layer 1 and layer 2 give the same answer for every `ReaState` × actor.

**Container (disposable fixture, real non-administrator users, never `global_admin` for the checked
roles)**

- New `test/container/rea-write-check.php`:
  - disposable users: two `rea_proponente`, one `rea_curador`, one `site_editor` and one `editor`
    on the REA site, and one `site_editor` of another site with its own learning resource;
  - for every write path of §9.4 × every state × every actor, the expected result of §5.3;
  - the media visibility after each transition;
  - an LR item of the other site, untouched by every rule (G4).
- Regression: `rea-roles-check.php`, `wizard-check.php`, `batch-ai-check.php`, queue checks.
- Browser, as a disposable `rea_proponente` and `rea_curador`:
  - «Editar ítem» absent on a Propuesto REA;
  - «Retirar propuesta»;
  - the locked visibility control;
  - the batch edit notice.

## 14. Owner decisions (recommended defaults, to confirm)

| # | Decision | Recommended |
| --- | --- | --- |
| D1 | «Retirar propuesta» (Propuesto → Borrador) for the proposer | yes (§8) |
| D2 | A proposer writes someone else's REA | no; this reverses ADR-0023 «separation recorded, not enforced» |
| D3 | `set-visibility` on REA | withdrawn for REA, kept for other items |
| D4 | Other roles (`editor`, `site_editor` without a REA role, `site_admin`) on governed REA | read only |
| D5 | Deletion | proposer: own Borrador; curator: «Descartar», no hard delete; `global_admin`: yes |
| D6 | What a REA is | class and (REA site or status), before or after the write (§4); decided by the owner on 2026-11-27, with the status clause added for F8 |
| D7 | Media visibility | follows the item, derived (§6.2) |
| D8 | Catalogue views use the same predicate | yes (§10); agreed in principle by the owner on 2026-11-27 |

## 15. Delivery

- **S0. Read-only spike.** Close the facts this design leaves to verify:
  - public media of private REA in the development catalogue;
  - which BulkImport and EasyAdmin features write with SQL;
  - whether the native batch edit form exposes the module's notice event;
  - the list of production modules from TASK-071.
- **S1. Core, no behaviour change:** `GovernedRea`, `ReaState`/`ReaStateReader`, `ReaWritePolicy`,
  `WorkflowScope`. `StatusGuard` and `RecatalogRule` delegate to them; listeners move to
  `src/Listener/`. The whole suite stays green unchanged.
- **S2. TASK-078, visibility:**
  - layer 3 item visibility, create normalisation, media derivation;
  - `set-visibility`, and the wizard's media born private;
  - locked controls on the native forms.
- **S3. TASK-079, writes:**
  - layer 1 assertion, layer 2 gate (update, create and delete, item and media);
  - layer 3 `leave`, `enter-public`, owner, REA site on create;
  - «Retirar propuesta»;
  - «Mis propuestas» and native-page coherence;
  - batch edit notice.
- **S4. Catalogue on the predicate:** master view, queue, statistics, integrity.
- **S5. Container and browser verification; governance closure:**
  - ADR-0026 accepted;
  - backlog rows for TASK-078 and TASK-079, closed;
  - TASK-038 marked done with a pointer to TASK-078;
  - traceability and project memory updated together (AGENTS.md).

Each slice is its own commit series with focused tests first (TDD), `make lint`, `make test` and
`make test-js` green.

## 16. Review focus

The inputs and failure modes most likely to bite, for the plan to pin with tests:

1. **A batch that mixes REA and other items** (native batch edit, `set-visibility`): the REA are
   skipped, the others applied, and the user is told which.
2. **The native add form's default `o:is_public = 1`** for a proposer: the REA is created private,
   not refused.
3. **A write that removes the class and the REA site at once** in a proposer's draft: refused as
   `leave`.
4. **A nested write during a transition** (a listener updating another item inside
   `WorkflowScope`): no bypass leaks.
5. **IsolatedSites and the core rules intact** after `ReaAcl::register`: `site_editor` keeps
   writing the other sites' items; `editor` keeps its rights on non-REA items.
