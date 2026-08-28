# ADR-0018: Flujo autor→curador de REAs — roles nativos, `curation:status`, gate de publicación

## Estado

Aceptado (2026-08-28)

## Contexto

El propietario pide un flujo de trabajo nuevo: un **autor de REA** propone un recurso que ha creado o adaptado como candidato al catálogo; un **curador de REA** lo verifica, lo cataloga curricularmente si procede, y lo publica. Ninguno de los dos actores existía antes en el gobierno del módulo — solo "curador" como término genérico para quien ya cura vía ACL `editor`+. El módulo, hasta ahora, solo *gestiona* items ya existentes (RF-001); no tiene ningún flujo de creación o propuesta propio.

Antes de diseñar nada propio, se verificó contra el core real del contenedor (2026-08-28, no por memoria):

- **Omeka-S ya trae roles nativos `author` y `reviewer`.** `author` puede crear/editar/borrar solo **sus propios** items (`OwnsEntityAssertion` en `AclFactory::addRulesForAuthor()`); `reviewer` puede crear/editar **cualquier** item (`AclFactory::addRulesForReviewer()`, sin `OwnsEntityAssertion` en `create`/`update`) y sí tiene el privilegio `view-all` sobre `Omeka\Entity\Resource`, que `author` no tiene.
- **`view-all` gobierna el scoping en TODA consulta de recursos**, no solo en el admin de items: `ResourceVisibilityFilter` (filtro SQL de Doctrine, `application/src/Db/Filter/ResourceVisibilityFilter.php:80-90`) y `AbstractResourceEntityAdapter::buildPropertyQuery()` (`:556`) restringen, sin `view-all`, a `is_public=1 OR owner=identidad`. Esto alcanza también a las consultas de este módulo (`MasterViewQuery`, `CatalogSnapshot` de TASK-006): un `author` ya ve solo lo suyo (más lo público) sin ningún cambio de código.
- **Ya existe una property instalada, semánticamente correcta, para el estado de flujo:** `curation:status` (vocabulario `Curation`, `https://omeka.org/s/vocabs/curation/`, vocabulario id 5, traído por el módulo `Access` ya instalado) — *"The status of the resource, generally for internal purposes"*. Junto a `curation:note` (*"A specific or generic information on a resource, generally for internal purposes"*), cubre estado y motivo sin vocabulario nuevo.
- **Ningún módulo instalado implementa ya un flujo propone→revisa→publica** (no hay `CurationProtocols`, `Contribute` ni equivalente): hace falta lógica propia, pero apoyada en piezas nativas ya presentes.
- **PEND-012 (campos obligatorios de la plantilla REA) sigue abierto de verdad**: `oermanager_rea_template_id` está `NULL` en la instalación real.

## Decisión

1. **Autor** = rol nativo `author`. **Curador** = rol nativo `reviewer`, añadido **junto a** `editor`/`site_admin` en el ACL del módulo (no los sustituye; `editor`/`site_admin` conservan el superconjunto de privilegios que ya tenían).
2. **Estado del REA**: literal `curation:status` con **dos** valores reales, `Propuesto`/`Rechazado`, como **constantes propias del módulo** (`WorkflowStatus::PROPOSED`/`::REJECTED` o equivalente), **sin** `CustomVocab`. Se descarta a propósito el mecanismo «sembrar, no poseer» de ADR-0013/PEND-011: ese patrón vale para vocabularios que un `site_admin` legítimamente necesita poder ampliar o editar (licencias, tipos de recurso); aquí el valor lo compara la lógica de la máquina de estados por igualdad exacta, así que un `CustomVocab` editable sería un riesgo sin beneficio — un admin que renombrara «Propuesto» a otra cosa rompería el flujo en silencio. Los dos valores viven como literal simple (`type=literal`), no como `customvocab:N`. La **ausencia** de valor es `Borrador` (implícito) — no se escribe nada al crear un item normal, ni al publicar. Motivo de un rechazo en `curation:note`.
3. **Máquina de estados**: `Borrador` →(autor, «Proponer para revisión»)→ `Propuesto` →(curador)→ `Rechazado` (con motivo) →(autor corrige y vuelve a proponer)→ `Propuesto`, o →(curador, «Publicar propuesta»)→ **publicado** (`is_public=true` + `curation:status` se limpia; no hay un 4º valor «Publicado» que mantener sincronizado con `is_public`, que ya es la señal canónica).
4. **Gate de publicación**: «Publicar propuesta» es una acción **nueva y distinta** del toggle de visibilidad general (RF-003), que sigue sin gate — vía de escape deliberada para `editor`/`site_admin` que necesite forzar algo fuera del flujo. «Publicar propuesta» solo está disponible con `curation:status=Propuesto` y exige `IntegrityChecker::check($item, true)->getStatus() === IntegrityResult::STATUS_OK` — ni `error` ni `warning` (decisión del propietario: publicar por este flujo exige el registro de gobernanza limpio, alineado con RF-015). `checkLinks=true` porque es una acción explícita de un item, mismo criterio que ya usa el drawer, no el browse.
5. **ADR-0002 se amplía**: el vocabulario `curation` se añade a los permitidos, **acotado únicamente** a `curation:status` y `curation:note` — no se abre el resto de ese vocabulario (`curation:featured`, `curation:access`, etc., son de otro módulo y no aplican aquí).
6. **«Proponer» no crea un privilegio ACL nuevo**: se apoya en el permiso nativo de edición del item (el autor sobre lo suyo, el curador sobre cualquiera), mismo patrón que RF-003 ya usa para la visibilidad. Los privilegios nuevos son solo los de las dos acciones del curador («publicar propuesta», «rechazar propuesta»).

## Consecuencias

- El módulo gana su primer flujo de **creación/propuesta**, hasta ahora inexistente (RF-001 solo describía gestión de lo ya existente). Nuevo RF-016.
- El ACL del módulo se amplía a `reviewer` en las acciones de curación (visibilidad, re-catalogador, y las dos nuevas de este flujo); `config` sigue exclusivo de `site_admin`+.
- El re-catalogador (componente de alto riesgo) pasa a ser accesible también por `reviewer`, no solo `editor`+`site_admin` — cualquier cambio en `RecatalogService`/su ACL exige cargar la skill `recatalogador`.
- Depende de que **PEND-012 se resuelva** (campos obligatorios de la plantilla REA) antes o junto con la implementación: sin plantilla configurada, el autor crea items sin la guía de campos obligatorios que motivaba en parte este flujo.
- Fuera de alcance de esta decisión: notificaciones al curador (modelo *pull*, consulta un filtro nuevo en la vista maestra), límite de reintentos tras un rechazo, y cualquier propuesta de alineamiento curricular por parte del autor (la cataloga el curador, tal como se pidió).

## Fuentes

- `application/src/Service/AclFactory.php` del core (contenedor real, 2026-08-28): `addRulesForAuthor()`, `addRulesForReviewer()`.
- `application/src/Db/Filter/ResourceVisibilityFilter.php`, `application/src/Api/Adapter/AbstractResourceEntityAdapter.php` del core (contenedor real).
- Vocabularios/properties/plantillas/CustomVocabs instalados, leídos vía `Omeka\ApiManager` en el contenedor real (2026-08-28): vocabulario `curation` (id 5), properties `curation:status`/`curation:note`, plantilla `Ficha mediateca` (id 2, no configurada como plantilla REA), `oermanager_rea_template_id=NULL`.
- Brainstorming con el propietario, 2026-08-28 (este chat).
