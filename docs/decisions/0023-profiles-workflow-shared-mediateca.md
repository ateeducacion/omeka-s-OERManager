# ADR-0023: REA profiles, permissions and workflow in the shared mediateca

## Estado

Aceptado (2026-11-17), by the owner, in the TASK-066 session. Replaces the role decisions of
ADR-0018 (points 1 and 6, and its addendum) and amends NFR-003 and RF-016. The workflow states of
ADR-0018 point 3 are extended, not replaced.

## Contexto

ADR-0018 mapped the proposer to the native `author` role and the curator to the native
`reviewer` role, added alongside `editor` and `site_admin`. It was written for an instance that
held only the REA repository. The owner has now fixed where version 1.0 runs and who works on it,
and the facts below were checked on 2026-11-17.

**Production is the shared mediateca of the Consejería** (public API, read only):

- 818 sites (school mediatecas and area channels) and 86 524 public items;
- 1 265 users with a site permission, 1 236 of them on a single site, 1 257 «editor» site
  permissions: about 1 200 people from schools edit their own site;
- installed vocabularies: `bibo`, `curation`, `dcterms`, `dctype`, `foaf`. There is no `lrmi`,
  `schema`, `skos` or `cur`, no curriculum, no REA template and no REA;
- global roles are not visible (`/api/users` answers 403).

**IsolatedSites v1.0.5** (an ATE module, installed in production and in the development
container):

- `addRole('site_editor', 'editor')`: `site_editor` inherits from `editor`;
- a `site_editor` cannot update or delete an item unless they hold a permission on one of its
  sites, and cannot edit resource templates, users or sites;
- isolation is not tied to a role: on every item search in an admin route
  (`ItemAdapter`, `api.search.query`), any user who is not `global_admin` and has the user setting
  `limit_to_granted_sites` (default 1, also set on CAS sign-up) only gets items of the sites they
  hold a permission on. It does not apply outside admin routes, so it does not apply inside Jobs.

**OERManager before this decision:**

- the curation ACL is granted to `editor`, `site_admin` and `reviewer`, and propose also to
  `author`; through inheritance a `site_editor` gets all of it, the paid AI proposal included;
- Omeka holds one global role per user (`User::$role`);
- `WorkflowService::publish()` makes the item public and deletes `curation:status` and
  `curation:note`, so a REA published through the flow cannot be told apart from one published in
  the native edit form, and nobody's publication is recorded;
- the 15 438 curriculum terms and the 72 thematic-axis terms belong to no site. For an isolated
  user every curriculum search of OERManager (cascade, tree, AI, statistics outline, retired
  terms) would return nothing. Every check so far ran as `global_admin`, which isolation skips.

**Owner decisions** (2026-11-17): only the ATE team proposes REA; proposer and curator are usually
different people, without enforcing it; about 3 000 REA arrive in one initial import and stay
private until curated, then REA are added one by one; some team members are also `site_editor` of
a channel, both proposers and curators.

## Alternativas consideradas

- **Native roles (ADR-0018).** No new role code, but nobody knows what `author` or `reviewer`
  already mean in an 818-site instance, inherited grants have to be denied by hand, and
  `reviewer` can edit any of the 86 524 items.
- **Native roles plus membership of the REA site.** No new roles, but every action checks two
  things and the rule is hard to see for whoever manages users.
- **Dedicated REA roles (chosen).** Only people who are deliberately given the role can work on
  REA, whatever native roles mean in production.

For curriculum visibility under isolation: a configurable list of shared item sets in
IsolatedSites; switching isolation off for the REA team (they would see 86 000 items); assigning
the curriculum to the REA site (its 15 438 public terms would mix with the REA in the public
site); **a dedicated, unpublished «Currículo» site (chosen)**.

## Decisión

### 1. Profiles and roles

1. OERManager defines two roles, both inheriting from `site_editor`:

   | Role | Label | OERManager grants |
   | --- | --- | --- |
   | `rea_proponente` | «Proponente REA» | propose, «Mis propuestas» |
   | `rea_curador` | «Curador REA» | queue, re-cataloguer, AI (single and batch), publish, reject, discard, unpublish, governance batches, statistics |
   | `global_admin` (native) | — | everything, including the module configuration with the LLM key and undoing someone else's batch |

2. OERManager grants its actions **only** to these two roles and to `global_admin`. `author`,
   `editor`, `reviewer`, `site_admin` and `site_editor` get nothing from OERManager.
3. Inheriting from `site_editor` keeps a team member's channel work, IsolatedSites' denials and
   per-site isolation, with a single global role.
4. Reach comes from site permissions. **Every REA belongs to the REA site**, and the team holds a
   permission on it. A REA with no REA site is an integrity warning: the team could not edit it.
5. **The curriculum and the thematic axes belong to a dedicated, unpublished site «Currículo»**,
   and the team holds a permission on it, so isolation lets them through and the public REA site
   holds only REA. EducationalCurriculum assigns the configured site to the terms it creates on
   every synchronisation (change in that repository, TASK-069).
6. On uninstall, users holding a REA role go back to `site_editor`, so nobody is left with a role
   the ACL no longer knows.
7. A curator who publishes a REA they added is recorded and shown, never blocked.

### 2. Workflow states

`curation:status` (ADR-0018) gains two values and a transition:

| State | Meaning | Who moves it |
| --- | --- | --- |
| Borrador (no status, private) | being written | proposer |
| Propuesto | in the curation queue | proposer («Proponer»), the initial import, «Despublicar» |
| Rechazado | back to the proposer with a reason from a list (TASK-039) | curator |
| **Descartado** (new) | not publishable as a REA; terminal, a curator can reopen it; excluded from statistics and from the queue | curator, with a reason from a list |
| **Publicado** (new) | public; the status is kept and annotated with who published and when (`dcterms:contributor`, `dcterms:modified`, ADR-0002) | curator («Confirmar y publicar») |

«Despublicar» moves Publicado back to Propuesto with a reason. A public REA without the status
Publicado is an integrity warning, «Publicado fuera del flujo», and appears in the queue to be
regularised: publishing through the native form is detected, not blocked (closes TASK-038).

### 3. Flows

- **Initial import.** Rehearsed on a copy of production first. Each REA lands private, Propuesto,
  with the REA template, in the REA site and in an item set of its own load. Curators launch the
  batch AI proposal (TASK-011) on part of the queue; it runs as a Job. Each curator works the
  queue, finds the AI proposal pre-filled in the panel, adjusts it and confirms and publishes in
  one step, or rejects or discards it. There is no batch publication.
- **Single additions.** The proposer adds the REA with the template, whose required fields the
  native form enforces (PEND-012), and proposes it; it joins the same queue. A rejected REA shows
  in «Mis propuestas» with its reason; the proposer corrects it and proposes it again.
- **Notifications.** A «Cola (N)» counter in the curator's navigation; no e-mail in 1.0.
- **Jobs.** Isolation does not apply inside Jobs, so a batch Job may read the whole catalogue; the
  native ACL still decides every write.

## Consecuencias

- TASK-067 implements sections 1 and 2; TASK-038 is absorbed by it; TASK-069 is a prerequisite
  of any check made by a non-administrator.
- Users who curate in development with `editor` or `reviewer` lose access until they get a REA
  role.
- Every container check of profiles and workflow must run as real users with REA roles, never as
  `global_admin`.
- Imported REA do not go through the native form, so required fields are not enforced on them:
  the queue shows what each REA lacks.
- The proposer, inheriting from `site_editor`, can edit someone else's REA in the REA site, as any
  `site_editor` can in their sites. Accepted: separation is recorded, not enforced.
- Production needs, before OERManager works: the `lrmi`, `schema` and `skos` vocabularies, the
  EducationalCurriculum module and the curriculum (`memory_limit` 512M for its jobs), the licence
  vocabularies, the REA template, the REA and «Currículo» sites, the team's roles and site
  permissions, and the module configuration (TASK-071).
- Out of 1.0: e-mail notifications, batch publication, a curator limited by ACL to REA items only
  (needs per-class assertions).

## Fuentes

- Production API, `https://www3.gobiernodecanarias.org/medusa/mediateca/api` (GET, 2026-11-17):
  sites with `o:site_permission`, vocabularies, resource templates, item counts.
- IsolatedSites v1.0.5, `Module.php` (`addRole`, ACL rules) and
  `src/Listener/ModifyQueryListener.php`, read in the development container (2026-11-17).
- `Module.php::onBootstrap()` and `src/Service/Workflow/WorkflowService.php` of OERManager.
- Owner decisions, TASK-066 session (2026-11-17).

## Addendum (2026-11-17) — owner decisions while implementing TASK-067

1. **`rea_curador` may also propose.** A user holds one global role and some people both propose
   and curate; who proposed and who published stay recorded (§1.7).
2. **Regularising «Publicado fuera del flujo».** A curator may act directly on a public REA without
   the status Publicado: «Confirmar y publicar» (never on a Descartado one) or «Despublicar»
   (back to Propuesto, private, with a reason).
3. **Existing data.** Public REA without a status (69 in development, none in production) keep the
   warning; they are not rewritten and curators regularise them one by one from the queue
   (TASK-070).
4. **The re-cataloguer's ADR-0002 annotations become private** (they expose who catalogued each
   value on published REA): TASK-074.

