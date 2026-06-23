# ADR-0006: Identificación de la raíz de los árboles RDF (ejes y alineamiento curricular)

> Renumerada de ADR-0005 a ADR-0006 al resolver un conflicto de stash/cherry-pick (2026-06-16): el ID ADR-0005 ya estaba asignado en `main` a la aprobación de la vista maestra v1 ([decisions/0005-vista-maestra-v1-aprobacion.md](0005-vista-maestra-v1-aprobacion.md)).

## Estado

Aceptado (2026-06-16)

## Contexto

ADR-0004 fijó las properties del alineamiento curricular y de los ejes temáticos (tags), pero dejó abierto (PEND-007) cómo localiza el módulo, dentro del catálogo de items de Omeka, cuáles son los `schema:DefinedTermSet` que actúan de **raíz** de cada árbol de clasificación. Sin esto, el re-catalogador (TASK-004) no sabe sobre qué items-término ofrecer selección.

Confirmado por el propietario contra la instalación real (2026-06-16): son **dos mecanismos distintos**, uno por cada tipo de árbol.

## Decisión

1. **Ejes temáticos (tags, `dcterms:relation`)**: un único `schema:DefinedTermSet` raíz. Se identifica por **ID de item fijado en la página de configuración del módulo** (en la instalación de referencia, item id `40260`). No hay descubrimiento automático: el propietario lo selecciona una vez en la config.
2. **Alineamiento curricular** (`lrmi:educationalLevel`, `schema:about`, `lrmi:teaches`, `lrmi:assesses`): **varios** `schema:DefinedTermSet`, uno por marco/etapa educativa. Cada uno se identifica por el valor de su propiedad **`lrmi:educationalFramework`** (en la instalación de referencia, `LOMLOE`). El valor de `educationalFramework` a usar también se fija en la página de **configuración del módulo** (no se infiere ni se hardcodea en el código).
3. En ambos casos la config del módulo guarda una referencia (ID de item o valor de `educationalFramework`), nunca una búsqueda libre en tiempo de ejecución sin ese parámetro.

## Consecuencias

- La config del módulo (`config/module.config.php` + formulario de configuración) necesita: (a) un campo para el item-raíz de ejes temáticos, (b) un campo para el/los valor(es) de `educationalFramework` que delimitan los DefinedTermSet curriculares válidos.
- TASK-003/TASK-004 pueden diseñarse: el re-catalogador resuelve los items-término recorriendo desde estas raíces, no con una búsqueda global del catálogo.
- Resuelve la parte de PEND-007 relativa a "cómo se localizan los vocabularios controlados"; el resto de PEND-007 (cardinalidad, roles, integridad, estadísticas, rendimiento, i18n, accesibilidad) se cierra directamente en `requirements.md`. La vista maestra v1 (columnas, filtros, drawer, curación de visibilidad) se resolvió por separado en ADR-0005.

## Addendum (2026-06-23) — distinción de dimensión por `dcterms:type`

Al construir el re-catalogador (TASK-004) se confirmó contra la instalación real que, como los términos de las cuatro dimensiones curriculares conviven en los mismos `DefinedTermSet` por marco (punto 2 de arriba), **la pertenencia a set no basta para acotar por dimensión**. El propietario confirmó (2026-06-23) que:

1. La dimensión de un término se distingue por **`dcterms:type`**. El re-catalogador acota cada input curricular filtrando por el valor de `dcterms:type` correspondiente, configurable por dimensión en el módulo (específico de la instalación, no hardcodeado).
2. Los términos forman un **grafo jerárquico**, enlazados a sus contenedores con `dcterms:isPartOf`, `schema:inDefinedTermSet`, `lrmi:educationalAlignment` y `lrmi:educationalLevel`. Una acotación contextual (filtrar Saberes/Criterios por la materia/etapa ya elegida) usando esos enlaces queda como mejora propuesta.

Detalle y muestra en [docs/referencia/curriculo-modelo-rdf.md](../referencia/curriculo-modelo-rdf.md). Esto refina cómo se localizan los términos; no cambia el mapeo RDF (ADR-0004) ni la identificación de raíces por marco/ID (arriba).

## Fuentes

- Conversación con el propietario, 2026-06-16 (TASK-001 / PEND-007) y 2026-06-23 (TASK-004, modelo de términos).
- ADR-0004 (mapeo RDF base).
