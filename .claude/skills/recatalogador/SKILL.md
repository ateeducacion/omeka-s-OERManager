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

## Mecanismo de escritura (verificado en Omeka 4.2)

- API interna: `$api = $services->get('Omeka\ApiManager')`. Lote: **`$api->batchUpdate('items', $ids, $data, [], ['isPartial' => true])`**. Individual: `$api->update('items', $id, $data, [], ['isPartial' => true])`.
- El alineamiento es un **resource value** que apunta al item-término del currículo (data type `resource:item`), **no** un literal. Validar que el destino existe y es del tipo esperado antes de escribir.
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
- **Plantilla**: hoy los items van sin `resource_template`; definir una plantilla REA (properties obligatorias) se evalúa en PEND-007 (integridad).

> **Pendiente de PEND-007** (reglas de negocio, no mapeo): cardinalidad/obligatoriedad por nivel, mínimo de alineamiento para «completo», quién puede recatalogar qué (ACL), límites de lote. **Refinar esta skill al construir el re-catalogador (TASK-004).**

## Auditoría (PEND-006 resuelto, ADR-0002)

La auditoría de la re-catalogación es **RDF nativa**: value annotations `dcterms` sobre el valor curado (`dcterms:contributor` quién, `dcterms:modified` cuándo, `dcterms:provenance` qué). Sin módulo Log ni tablas propias (coherente con NFR-002). Esa misma anotación es la vía de reversión: permite reconstruir el estado anterior.
