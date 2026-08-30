# Flujo autor→curador de REAs — diseño (RF-016)

> **Estado:** aprobado por el propietario (2026-08-28), decisiones fijadas en brainstorming.
> **Decisión de fondo en [ADR-0018](../../decisions/0018-flujo-autor-curador-rea.md).** Este documento es el diseño técnico completo que ADR-0018 resume.
> **Depende de PEND-012** (campos obligatorios de la plantilla REA — `oermanager_rea_template_id` está `NULL` en la instalación real): sin resolverlo, el autor crea items sin la guía que en parte motiva este flujo. No bloquea diseñar ni implementar el resto; sí bloquea que el flujo sea útil en producción.

## 1. Lo que ya hace el core, verificado (no inventado)

Antes de diseñar nada, se comprobó contra el core real del contenedor (2026-08-28):

- **`author` y `reviewer` son roles nativos** (`Omeka\Permissions\Acl::ROLE_AUTHOR`/`ROLE_REVIEWER`), con reglas ya definidas en `AclFactory::addRulesForAuthor()`/`addRulesForReviewer()`: `author` crea/edita/borra solo lo suyo (`OwnsEntityAssertion`); `reviewer` crea/edita cualquier item y tiene `view-all` sobre `Omeka\Entity\Resource` (que `author` no tiene).
- **`view-all` scopa TODA consulta de recursos**, no solo el admin nativo: `ResourceVisibilityFilter` (filtro SQL de Doctrine) y `AbstractResourceEntityAdapter::buildPropertyQuery()` restringen, sin `view-all`, a `is_public=1 OR owner=identidad`. Esto alcanza también a `MasterViewQuery`/`CatalogSnapshot` de este módulo: **un `author` ya ve solo lo suyo (+ lo público) sin tocar código**.
- **`curation:status`/`curation:note` ya existen** como properties instaladas (vocabulario `Curation` id 5, traído por el módulo `Access`), con la semántica exacta que hace falta.
- Ningún módulo instalado resuelve ya el flujo propone→revisa→publica.

**Consecuencia de diseño:** la superficie nueva de código es mucho menor de lo que parecería a primera vista. La mayor parte del trabajo es *cablear* piezas nativas ya presentes, no construirlas.

## 2. Actores y roles

| Actor | Rol Omeka | Qué puede hacer (ya nativo) | Qué gana este flujo |
| --- | --- | --- | --- |
| **Autor** | `author` | Crear/editar/borrar sus propios items; ve solo lo suyo + lo público en cualquier listado (nativo, `view-all` ausente) | Un botón «Proponer para revisión» en la página del item |
| **Curador** | `reviewer` | Crear/editar cualquier item; ve todo (`view-all`) | Acceso a las herramientas de curación del módulo (hoy solo `editor`+`site_admin`); filtro «Propuestos»; acciones «Publicar propuesta»/«Rechazar propuesta» |
| `editor`/`site_admin` | sin cambio | Todo lo anterior + lo que ya tenían | Sin cambio — conservan el superconjunto; pueden hacer todo lo que hace un curador y más |

`editor`/`site_admin` **no se sustituyen** por `reviewer`: se añade `reviewer` a las listas de ACL existentes. Un `editor` sigue pudiendo curar exactamente igual que hoy.

## 3. Modelo de estados

```
Borrador ──(autor: «Proponer para revisión»)──► Propuesto
                                                     │
                                    ┌────────────────┼────────────────┐
                                    │                                 │
                         (curador: «Rechazar»,              (curador: «Publicar propuesta»,
                          con motivo)                        exige IntegrityChecker = ok)
                                    │                                 │
                                    ▼                                 ▼
                                Rechazado                       is_public=true
                                    │                        curation:status limpiado
                          (autor corrige y
                           vuelve a proponer)
                                    │
                                    └──────────────► Propuesto
```

- **`Borrador`** es la ausencia de `curation:status` — no se escribe nada al crear un item normal. Un item creado por un `editor`/`site_admin` fuera de este flujo (como hoy) nunca entra en la máquina de estados: simplemente no tiene el valor.
- **`Propuesto`**: literal `curation:status = "Propuesto"`.
- **`Rechazado`**: literal `curation:status = "Rechazado"` + `curation:note` con el motivo (texto libre del curador).
- **Publicado no es un 4º valor.** `is_public=true` ya es la señal canónica de publicado en todo Omeka (front-end, otros módulos, este mismo módulo); duplicarla en `curation:status` sería otra fuente de verdad que mantener sincronizada. Al publicar, `curation:status` se **elimina** — el item deja de estar "en el flujo" y pasa a ser un REA del catálogo indistinguible de cualquier otro.
- Los dos valores son **literales simples**, constantes en código (`type=literal`), no un `CustomVocab` — ver ADR-0018 §2 para el porqué.

## 4. Flujo del autor

**Creación**: 100% nativa. El autor usa "Añadir item" del admin de Omeka con la **plantilla REA** (`resource_template` identificada por `oermanager_rea_template_id`, PEND-012). Cero código nuevo — es exactamente el "procedimiento nativo de Omeka-S para crear un item con una plantilla" que la pregunta original planteaba como alternativa, y aquí **sí basta** para la parte de creación.

**Proponer**: lo único nuevo del lado del autor. Un botón "Proponer para revisión" inyectado en la página nativa de detalle del item (evento `view.show.after`, mismo mecanismo que ya usa este módulo — `NFR-001`, extender sin parchear), visible cuando:
- el usuario puede editar el item (delegado al ACL nativo: `$this->userIsAllowed($item, 'update')` — no una comprobación de rol propia; así funciona igual para el autor sobre lo suyo y para un curador/editor que quiera reproponer algo en nombre de otro), **y**
- `curation:status` está ausente o es `Rechazado` (no tiene sentido "proponer" algo que ya está `Propuesto` o publicado).

Al pulsar: `$api->update('items', $id, [...], [], ['isPartial' => true])` escribe `curation:status = "Propuesto"` (y borra `curation:note` si venía de un rechazo previo — el motivo viejo no debe sobrevivir a la corrección). CSRF igual que el resto de acciones de escritura del módulo.

El autor ve el estado de sus propuestas en la propia página nativa del item (el valor de `curation:status` es una property más, visible con el resto). No necesita entrar en el panel del módulo: gana exactamente un privilegio ACL nuevo (`propose`, ejercido a través del botón de esta página, no de una ruta del panel), pero sigue sin acceso al resto de `/admin/oer-manager*` (vista maestra, búsqueda, re-catalogador, etc.), que sigue reservado a `editor`/`site_admin`/`reviewer`.

## 5. Flujo del curador

**Encontrar propuestas**: filtro nuevo en la vista maestra existente, "Propuestos" — mismo patrón que los filtros de gobernanza de ADR-0013 (`nex`/`eq` sobre una property literal, sin coste computado porque `curation:status` es un filtro de query nativo, no un `ComputedFilter`). Modelo *pull*: el curador consulta el filtro cuando quiere trabajar; no hay notificación push en esta versión (fuera de alcance, §7).

**Catalogar curricularmente si procede**: las herramientas ya existentes del módulo (RF-004, re-catalogador), sin cambios de comportamiento — solo de quién puede llegar a ellas (§6, ACL). El autor no propone alineamiento; lo decide el curador, tal y como se pidió.

**Rechazar**: acción nueva, `curation:status = "Rechazado"` + `curation:note = <motivo>`. Formulario mínimo (un textarea) porque el motivo es el dato que el autor necesita para poder corregir.

**Publicar propuesta**: acción nueva y **distinta** del toggle de visibilidad general que ya existe (RF-003, que sigue funcionando igual y sin gate — es la vía de escape deliberada para un caso fuera de este flujo). Solo disponible con `curation:status = "Propuesto"`. Al pulsar:
1. `IntegrityChecker::check($item, true)` (mismo criterio que el drawer: `checkLinks=true`, es una acción de un solo item, no un browse).
2. Si `getStatus() !== IntegrityResult::STATUS_OK` (o sea, `error` **o** `warning`): la acción se rechaza, se muestran los issues (`getIssues()`, ya existen) para que el curador sepa qué falta — nada se escribe.
3. Si `STATUS_OK`: `is_public = true` y `curation:status` se elimina, en la misma escritura (evita un estado intermedio "publicado pero todavía marcado Propuesto").

## 6. ACL

En `Module.php::onBootstrap()`, añadir `'reviewer'` al array de roles del bloque de curación existente (visibilidad, re-catalogador, IA, drawer — **no** al bloque de `config`, que sigue exclusivo de `site_admin`+):

```php
$acl->allow(
    ['editor', 'site_admin', 'reviewer'],
    [Controller\Admin\IndexController::class],
    [/* mismas acciones de curación que ya hay, sin cambios en la lista */]
);
```

Dos privilegios nuevos, mismo nivel (`editor`/`site_admin`/`reviewer`): `publish-proposal`, `reject-proposal` (o los nombres de acción que resulten al implementar, siguiendo la convención `kebab-case` del resto de acciones del controlador).

`StatsController` (TASK-006) también se amplía a `reviewer` — un curador debería poder ver las estadísticas del catálogo que está curando; mismo nivel que ya tienen `editor`/`site_admin` ahí.

El re-catalogador es el componente de **alto riesgo** del módulo: cualquier cambio en su ACL exige cargar la skill `recatalogador` antes de tocar código, tal y como ya exige `CLAUDE.md`.

## 7. Fuera de alcance (explícito)

- **Notificaciones al curador** de que hay propuestas pendientes (email, aviso en el admin). Modelo *pull* vía el filtro nuevo. Mejora futura si hace falta.
- **Límite de reintentos** tras un rechazo — el autor puede corregir y volver a proponer tantas veces como haga falta.
- **Propuesta de alineamiento curricular por el autor** — pedido explícitamente como tarea del curador, no del autor.
- **Publicar "de todos modos" pese a fallar el gate** — no se construye un botón de anulación; el toggle de visibilidad general (RF-003) ya sirve de escape estructural para quien tenga motivo legítimo y sea `editor`+`site_admin`.
- **Tocar el modelo del currículo, TASK-035, TASK-011** ni ningún otro trabajo en curso — este diseño no depende de ellos ni ellos de este.
- **Resolver PEND-012** (qué campos son obligatorios en la plantilla REA) — es una decisión editorial del propietario, no de este diseño; se anota como dependencia (§ encabezado), no se decide aquí.

## 8. Testing

- **Puro, TDD real en host:** la lógica de la máquina de estados (qué transición es válida desde qué estado) y el cálculo de qué gate aplica, extraídos a una clase sin dependencia del core — mismo patrón puerto/adaptador que `CurationEvent` (TASK-007) y los agregadores de TASK-006.
- **Toca el core, sin test de host:** la escritura real (`$api->update`), el hook `view.show.after`, y la resolución de ACL — verificados en el arnés de contenedor, mismo patrón que el resto del módulo.
- **Verificación en admin real:** crear un item como `author`, comprobar que solo ve lo suyo; proponerlo; entrar como `reviewer`, comprobar que aparece en el filtro «Propuestos», rechazarlo con motivo, comprobar que el autor lo ve; corregir y reproponer; publicar y comprobar que pasa a público y pierde el estado. Deuda declarada de antemano hacia TASK-030 si no hay sesión de navegador disponible en la fase de implementación, mismo patrón que TASK-006/TASK-032/033/034.

## Fuentes

- ADR-0018 (decisión de fondo, con las citas exactas de `AclFactory.php`/`ResourceVisibilityFilter.php`/`AbstractResourceEntityAdapter.php` del core real).
- `docs/requirements.md` RF-016, NFR-003 (ampliado).
- Brainstorming con el propietario, 2026-08-28 (este chat).
