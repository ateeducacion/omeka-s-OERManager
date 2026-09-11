# ADR-0004: Mapeo RDF — alineamiento curricular, ejes/tags, proyecto y licencia

## Estado

Aceptado (2026-06-15). **§5 (licencia) y la fila «Licencia del REA» de la tabla: reemplazados por [ADR-0019](0019-licencia-dcterms-license-uri.md) (2026-09-10)** — la licencia es `dcterms:license`, guardada como URI desde un CustomVocab de tipo URI. El resto del mapeo sigue vigente.

## Contexto

PEND-005: el re-catalogador escribe valores RDF de alineamiento; un mapeo erróneo rompe la interoperabilidad LRMI. ADR-0002 fijó los vocabularios permitidos (`dcterms`/`lrmi`/`schema`); faltaba la **property concreta por concepto**. Se confirma **contra la instalación real** (JSON-LD de un item `lrmi:LearningResource`, no por conjetura).

## Decisión

Mapeo confirmado:

| Concepto | Property | Valor | Fuente controlada | ¿Re-catalogador? |
| --- | --- | --- | --- | --- |
| Etapa / nivel | `lrmi:educationalLevel` | resource:item | currículo existente | sí |
| Materia | `schema:about` | resource:item | currículo existente | sí |
| Saberes básicos | `lrmi:teaches` | resource:item (varios) | currículo existente | sí |
| Criterios de evaluación | `lrmi:assesses` | resource:item (varios) | currículo existente | sí |
| Eje temático (= tags) | `dcterms:relation` | resource:item | `schema:DefinedTermSet` (config del módulo) | sí |
| Proyecto | `schema:isPartOf` | resource:item | items de proyecto | no — lo asigna el gestor (acción aparte) |
| Licencia del REA | `dcterms:rights` | literal | `CustomVocab` controlado | curación/integridad |

Reglas asociadas:

1. Todo alineamiento se escribe como **resource value a item** (`resource:item`); el `type: "resource"` del JSON-LD es solo la serialización de Omeka. Validar que el destino existe y es un item.
2. Resolver las properties **por término** (`lrmi:teaches`…), nunca por `property_id` (son específicos de la instalación).
3. **Tags = ejes temáticos**: `dcterms:relation`, vocabulario **controlado** por un `schema:DefinedTermSet` **configurable en el módulo** (no libre, no hardcodeado).
4. **Proyecto** (`schema:isPartOf`): acción de gestor independiente del re-catalogador (editable, flujo propio), análoga a la visibilidad.
5. **Licencia** (`dcterms:rights`): literal de un `CustomVocab` controlado (p. ej. `ccbysa`).
6. **Auditoría** (ADR-0002): value annotations `dcterms` (`dcterms:contributor`/`dcterms:modified`/`dcterms:provenance`) sobre el valor curado. La visibilidad queda fuera.
7. **Plantilla**: hoy los items van sin `resource_template`; definir una plantilla REA con properties obligatorias se evalúa en PEND-007 (integridad).

## Consecuencias

- TASK-004 (re-catalogador) puede diseñarse: properties conocidas; solo faltan las reglas de negocio (cardinalidad, obligatoriedad, ACL, lotes) → PEND-007.
- TASK-005 (integridad): campos a validar conocidos (alineamiento vivo + `dcterms:rights` presente); la definición de «completo» → PEND-007.
- TASK-006 (estadísticas): dimensiones = etapa, materia, eje, proyecto, licencia.
- La **config del módulo** gana dos parámetros: el `schema:DefinedTermSet` de ejes y el `CustomVocab` de licencias.
- La skill `recatalogador` se actualiza con esta tabla; se refinará al construir TASK-004.

## Addendum (2026-06-24) — precisión de nodos

Verificado contra el grafo real (ADR-0009): el valor de `lrmi:educationalLevel` del REA referencia un **Curso** (p. ej. "1º Bachillerato"), no la Etapa educativa; la Etapa es el ancestro (un `schema:DefinedTermSet`). `schema:about` referencia una **Asignatura**. No cambia el mapeo de properties de arriba; precisa qué nodo del currículo ocupa cada una. Modelo completo en [ADR-0009](0009-grafo-curricular-lomloe.md) y [docs/referencia/curriculo-modelo-rdf.md](../referencia/curriculo-modelo-rdf.md).

## Fuentes

- JSON-LD de un item REA real de la instalación (chat 2026-06-15).
- ADR-0002 (vocabularios permitidos y auditoría RDF).
- `docs/requirements.md` §1 (PEND-005); skill `recatalogador`.
