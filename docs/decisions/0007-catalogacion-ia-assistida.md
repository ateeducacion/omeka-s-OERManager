# ADR-0007: Catalogación curricular IA-assistida (la IA propone, el curador confirma)

## Estado

Aceptado (2026-06-22)

## Contexto

El re-catalogador (TASK-004, RF-004/RF-005) escribe alineamiento curricular y ejes temáticos como resource values RDF. Hacerlo a mano sobre un currículo grande es lento. El propietario decidió (plan mode, 2026-06-22) incorporar **asistencia por IA**: un componente, **dentro del módulo**, que proponga la catalogación a partir del contenido del recurso, inspirado en la lógica del `REA-MCP-cataloguer` (que **no** está en el repo y **no** se reusa como servicio externo; se reimplementa en PHP).

Esto **revisa** una decisión previa registrada en `project-memory.md` («Proceso single-agent con Claude Code; sin agentes LLM en el runtime del módulo»): a partir de aquí el módulo **sí** llama a un LLM en runtime, de forma acotada y siempre bajo confirmación humana. La conexión al LLM se trata aparte en ADR-0008.

## Decisión

1. **La IA propone, el curador confirma.** Ninguna escritura RDF ocurre sin confirmación explícita del curador. La IA solo **pre-rellena** el flujo de re-catalogación existente (preview + confirmación + auditoría de RF-004/RF-005, ADR-0002/ADR-0004). El curador puede editar o descartar cualquier propuesta.
2. **Resolución por etiqueta → búsqueda incremental.** La IA devuelve, por dimensión (`educationalLevel`/`about`/`teaches`/`assesses`/`relation`), **etiquetas de texto**, no IDs de item (que desconoce y son específicos de la instalación). El módulo resuelve cada etiqueta a items-término candidatos mediante búsqueda incremental sobre los `DefinedTermSet` raíz (ADR-0006). **Nunca se vuelca el árbol curricular completo al LLM** (respeta NFR-004).
3. **Fuente de contenido para la IA:** metadatos del item + texto de los medios adjuntos (cascada de extracción: texto de PDF; texto y PDF embebido dentro de SCORM/ZIP). La visión para imágenes queda fuera (RF-012, mejora posterior).
4. **Escritura idéntica a la manual.** Confirmada la propuesta, la escritura usa el mismo servicio que el re-catalogador manual (mismas properties, misma validación de destino, misma auditoría `dcterms`). La IA no abre una vía de escritura paralela.
5. **Individual en primer plano.** TASK-004 cataloga un item cada vez, de forma síncrona. El lote (marcar varios → Job en segundo plano) es RF-011, **tarea aparte**.

## Consecuencias

- Nuevos servicios en el módulo: extractor de contenido (`ContentExtractor`) y catalogador IA (`AiCataloguer`), además del cliente LLM (ADR-0008).
- La asistencia IA es la entrega **4b** de TASK-004; la **4a** (re-catalogador manual) no depende de la IA y se entrega y revisa antes.
- Se actualiza `project-memory.md` para reflejar que el módulo invoca un LLM en runtime (acotado, bajo confirmación), revisando la decisión 1 previa.
- El riesgo de catalogación incorrecta lo absorbe el curador (confirmación obligatoria) + la auditoría reversible (ADR-0002).

## Fuentes

- Decisiones de plan mode con el propietario, 2026-06-22 (TASK-004).
- ADR-0004 (mapeo RDF), ADR-0006 (raíces de árboles), ADR-0002 (auditoría reversible).
- Skill `recatalogador` (invariantes de escritura RDF).
