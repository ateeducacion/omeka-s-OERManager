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
- Operaciones en lote: **previsualización + confirmación + vía de reversión** (auditoría vía value annotations `dcterms`, ADR-0002) antes de ejecutar.
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

## Auditoría (PEND-006 resuelto, ADR-0002)

La auditoría de la re-catalogación es **RDF nativa**: value annotations `dcterms` sobre el valor curado (`dcterms:contributor` quién, `dcterms:modified` cuándo, `dcterms:provenance` qué). Sin módulo Log ni tablas propias (coherente con NFR-002).

> ⚠️ **Reversibilidad — estado real (TASK-004 4a):** la anotación actual registra quién/cuándo/qué, pero **no** guarda el conjunto de valores previo, así que tras un apply destructivo no permite reconstruir automáticamente lo borrado. Para que la reversión de ADR-0002 sea real, la anotación (o `dcterms:provenance`) debe incluir el estado anterior (ids added/removed). Pendiente al cerrar TASK-007 (auditoría); hoy la garantía efectiva es **preview + confirmación**, no el deshacer.
