---
name: recatalogador
description: USAR OBLIGATORIAMENTE ante cualquier trabajo que toque el re-catalogador, el alineamiento curricular, los tags o la escritura de valores RDF sobre items (diseño, código, revisión o pruebas). Es el componente de ALTO RIESGO del módulo — re-cataloga en masa y puede corromper el catálogo; no trabajes en él sin cargar esta skill.
---

# Re-catalogador — reglas de negocio, escritura RDF y UX jerárquica

## Propósito

Concentrar las reglas de negocio del re-catalogador, el mapeo exacto de properties RDF y la estrategia de UX sobre la jerarquía curricular, para que ninguna escritura de alineamiento/tags se improvise.

## Cuándo dispararse

- Diseñar o codificar la re-catalogación curricular o por tags (individual o por lotes).
- Cualquier escritura de resource values de alineamiento sobre items.
- Revisar diffs que afecten a properties de alineamiento o al chequeo de integridad posterior.

## Invariantes ya fijados (no esperar al destilado)

- El alineamiento se escribe como **resource value apuntando al item-término** del currículo (no literal); validar que el destino existe y es del tipo esperado.
- Operaciones en lote: **previsualización + confirmación + vía de reversión** antes de ejecutar. La reversión es real desde TASK-007: auditoría por value annotations `dcterms` (ADR-0002) **más** un evento de curación con el estado previo sobre el item (ADR-0015), porque la anotación sola no puede registrar lo borrado.
- Tras re-catalogar, el chequeo de integridad debe poder confirmar el estado resultante.
- ACL estricta: solo roles con privilegio de curación (matriz rol×acción en PEND-007).
- UX sobre jerarquía grande: no cargar el árbol completo; búsqueda incremental y lazy-load por nivel (NFR-004).

## Mecanismo de escritura (verificado en el core de Omeka 4.2)

- API interna: `$api = $services->get('Omeka\ApiManager')`. El alineamiento es un **resource value** que apunta al item-término del currículo (data type `resource:item`, en JSON-LD se serializa como `type: "resource"`), **no** un literal. Validar que el destino existe y es del tipo esperado antes de escribir; resolver `property_id` por término en runtime (no hardcodear).

- ⚠️ **TRAMPA CRÍTICA (causó pérdida de datos, 2026-06-25).** `$api->update('items', $id, $data, [], ['isPartial' => true])` con datos de values **NO** es por-property. `ValueHydrator::hydrate` recorre la colección **plana** de TODOS los values del item, sobrescribe las entidades existentes con los nuevos valores y **borra las no reutilizadas** (`if (!isPartial || (!append && valuePassed))`). Pasar solo unas properties **borra el resto** (`dcterms:title`, `dcterms:description`, etc.). `setVisibility` no lo sufre porque no envía values (`valuePassed=false`).

- ✅ **Patrón correcto para tocar SOLO unas properties** (el canónico del core, cfr. `application/src/Job/BatchUpdate.php`): limpiar esas properties con `clear_property_values => [propertyId, ...]` dentro de `$data` y **anexar** los nuevos valores con la opción `collectionAction = 'append'`:

  ```php
  $data['clear_property_values'] = [$pidTeaches, $pidAssesses, ...];
  $data['lrmi:teaches'] = [ /* nuevos resource values */ ];
  $api->update('items', $id, $data, [], ['isPartial' => true, 'collectionAction' => 'append']);
  ```

  En modo `append` Omeka no reutiliza ni borra el resto → título/descripción/licencia/proyecto quedan intactos. **Vaciar** una dimensión = incluir su pid en `clear_property_values` y no anexar valores. Lote: `$api->batchUpdate('items', $ids, $data, ['collectionAction' => 'append'])` (cfr. el Job del core).

- ⚠️ **Búsquedas por property:** `buildPropertyQuery` hace `trim($queryRow['text'])`, así que `text` debe ser **escalar**; un array provoca `TypeError` fatal en PHP 8.4.

- **Value annotations** (auditoría, ADR-0002): se adjuntan en cada value con la clave `@annotation` (verificado en `ValueHydrator`); se hidratan también en modo append.
- Engancharse a `api.batch_update.pre/post` y `api.hydrate.post` para preview/validación/integridad (ver skill `omeka-module`).

## Mapeo RDF confirmado (PEND-005 resuelto 2026-06-15, ADR-0004)

Verificado contra la instalación real (JSON-LD de un item `lrmi:LearningResource`). Resolver siempre **por término**, nunca por `property_id` (específicos de la instalación). Vocabularios permitidos: `dcterms`/`lrmi`/`schema` (ADR-0002).

| Concepto | Property | Valor | Fuente | ¿Re-catalogador? |
| --- | --- | --- | --- | --- |
| Etapa / nivel | `lrmi:educationalLevel` | resource:item | currículo existente | sí |
| Materia | `schema:about` | resource:item | currículo existente | sí |
| Saberes básicos | `lrmi:teaches` | resource:item (varios) | currículo existente | sí |
| Criterios de evaluación | `lrmi:assesses` | resource:item (varios) | currículo existente | sí |
| Eje temático (= tags) | `dcterms:relation` | resource:item | `schema:DefinedTermSet` (config del módulo) | sí |
| Proyecto | `schema:isPartOf` | resource:item | items de proyecto | **no** — lo asigna el gestor (acción aparte) |
| Licencia del REA | `dcterms:rights` | literal | `CustomVocab` controlado | curación/integridad |

- **Tags = ejes temáticos**: `dcterms:relation`, vocabulario **controlado** por un `schema:DefinedTermSet` configurable en el módulo (no libre, no se hardcodea el set).
- **Plantilla**: hoy los items van sin `resource_template`; definir una plantilla REA (properties obligatorias) se evalúa en RF-006 (integridad, ya implementada en TASK-005).

> **Reglas de negocio (PEND-007 resuelto):** cardinalidad **múltiple** en las cuatro properties curriculares y en tags (RF-004/005); ACL **editor o superior** para alineamiento/tags, `global_admin`/`site_admin` para proyecto (NFR-003); el lote pasa a Job en segundo plano (RF-011, tarea aparte). Definición de «completo» en RF-006.

## Grafo curricular (ADR-0009) — clave para la acotación contextual

Verificado contra el contenedor (2026-06-24). Detalle en `docs/referencia/curriculo-modelo-rdf.md`.

- La **dimensión** de un item-término se distingue por **`dcterms:type`** (literal): `Etapa educativa`, `Curso`, `Asignatura`, `Competencia específica`, `Saber básico`, `Criterio de evaluación`. Cada input se acota filtrando por su `dcterms:type` (configurable por dimensión).
- **OJO con los nodos:** en el REA, `lrmi:educationalLevel` referencia un **Curso** (no la Etapa); `schema:about` una **Asignatura**. La Etapa (un `schema:DefinedTermSet` con `lrmi:educationalFramework`) y la Competencia específica **no** se escriben en el REA.
- **Jerarquía y arista al padre:** Curso `schema:inDefinedTermSet`→Etapa · Asignatura `lrmi:educationalLevel`→Curso · Saber/Criterio `schema:inDefinedTermSet`→Asignatura · Criterio `schema:inDefinedTermSet`→{Competencia, Asignatura}. Enlaces ancestro denormalizados: `dcterms:isPartOf`→Etapa, `lrmi:educationalAlignment`→Curso.
- **Acotación contextual (RF-014):** cada dimensión hija filtra por el ancestro elegido (cascada Etapa→Curso→Asignatura→{Saberes, Criterios}) usando esas aristas, además de `dcterms:type` + búsqueda incremental.
- Mejora propuesta RF-013: migrar `dcterms:type` (literal) a `skos:Concept` (recurso) → el filtro pasaría de `eq` a `res`.

## Auditoría (PEND-006 resuelto, ADR-0002) y reversibilidad (TASK-007, ADR-0015)

La auditoría de la re-catalogación es **RDF nativa**: value annotations `dcterms` sobre el valor curado (`dcterms:contributor` quién, `dcterms:modified` cuándo, `dcterms:provenance` qué, más `dcterms:description` con el porqué de la IA en saberes/criterios, TASK-023). Sin módulo Log ni tablas propias (coherente con NFR-002).

> ⚠️ **Una value annotation NO puede registrar una eliminación.** Vive en el valor que anota, así que al borrarse el valor se borra con él: vaciar una dimensión no deja rastro. Es el límite estructural de ADR-0002, y por eso la anotación **nunca** es sitio para el estado previo.

**El estado previo va en un evento de curación sobre el propio item** (ADR-0015): cada `apply` que cambia algo anexa un valor `dcterms:provenance` **privado** (`is_public = false`) con el resumen legible, y en su `@annotation` el marcador `OERManager/curation-event/1` (ASCII, **no traducible**) más el payload JSON en `dcterms:replaces`:

```json
{ "v": 1, "op": "recatalog|undo", "undoOf": null,
  "terms": { "lrmi:teaches": { "before": [...], "after": [...], "why": { "<id>": "…" } } } }
```

Reglas que no se pueden romper al tocar esto:

- **`dcterms:provenance` NUNCA entra en `clear_property_values`.** El registro es append-only; si se limpia, cada re-catalogación borra la historia de las anteriores.
- **El sello `dcterms:modified` lleva microsegundos** (`format('Y-m-d\TH:i:s.uP')`) y es **el mismo** en el evento y en las anotaciones por valor de esa confirmación. Con precisión de segundo, dos escrituras seguidas (un doble clic en «Deshacer») lo comparten y la lectura del último evento tiene que desempatar por el orden de la colección, **que Omeka no garantiza**: restauraría el estado equivocado. El sello común es además la clave `(contributor, modified)` que agrupa el evento en ADR-0013 §7.2.
- **`why` guarda el porqué de TODOS los valores previos**, no solo el de los eliminados: el deshacer reescribe la property entera desde `before` y cada valor restaurado necesita el suyo. Regenerarlo cuesta otra pasada de LLM.
- **El payload va versionado** (`v`): lo que no se sepa leer se rechaza, no se interpreta a medias.
- **La reversión no es un camino privilegiado:** `undo()` reaplica por `apply()`, así que hereda validación de destino, anotaciones y su propio evento. Deshacer un deshacer es rehacer.
- **Guarda de obsolescencia:** si el estado actual no coincide con el `after` del último evento, alguien tocó el REA por otra vía; el servicio se niega y exige confirmación explícita.
- **Confirmar sin cambios no escribe.** Reescribir los mismos valores les pondría anotaciones con fecha y autor nuevos, falsificando la auditoría.

> **Límite vivo:** el valor del evento es privado, así que **no se ve sin autenticar** (comprobado en el contenedor) y un export anónimo no lo lleva. Cualquier lectura del historial —rebanada 3 de TASK-028— tiene que resolverse **en servidor**, no fiándola al JSON-LD que el drawer trae de la API. Y al restaurar, los valores recuperados llevan anotaciones nuevas: la autoría original solo pervive en el registro de eventos.

Cobertura: el formato es puro y se prueba en el host (`CurationEventTest`, 17 tests); `RecatalogService` depende del core y **solo** se verifica con `test/container/undo-harness.php` (⚠️ escribe; exige `--write`; se autorrestaura).
