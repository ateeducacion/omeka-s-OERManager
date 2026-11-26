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

## Addendum 1 (2026-11-25, TASK-011): the batch stores proposals in the REA

The batch proposal (RF-011, ADR-0023 §3) runs as `AiBatchProposeJob` and keeps the rule of point 1:
the AI proposes and the curator confirms.

1. The Job stores **one private `curation:data` value per REA**, a literal whose text is the stable,
   untranslatable marker `OERManager/ai-proposal/1`, a space and a versioned JSON object: term ids per
   dimension, the justifications, the model and the dates. It stores no prompts and no titles.
2. It **never applies** the proposal. Alignment, axes, status and visibility are untouched; the curator
   opens the REA, finds the proposal pre-filled as pending chips and confirms it through the
   re-cataloguer (point 4, RF-009 unchanged).
3. The value is written directly on the entity, not through the API: imported REA usually fail the
   template's required fields, and the write must not move `o:modified`.
4. The value is removed when the re-cataloguer applies a change, on «Confirmar y publicar», on
   «Descartar» and on «Descartar propuesta». A rejection keeps it.
5. Only users allowed `ai-propose` (curators) receive it in the panel; only `curation:data` values
   carrying the marker are read or written.

Spec: `docs/superpowers/specs/2026-11-25-task-011-batch-ai-proposal-design.md`.
