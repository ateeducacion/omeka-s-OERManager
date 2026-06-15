# ADR-0002: Estrategia RDF — vocabularios permitidos y auditoría de curación nativa

## Estado

Aceptado (2026-06-15)

## Contexto

El módulo OERManager escribe alineamiento curricular y etiquetas como valores RDF sobre items `lrmi:LearningResource`, y necesita auditar las acciones de curación (quién cambió qué y cuándo). NFR-002 prohíbe tablas Doctrine propias; la única excepción evaluable era la auditoría (PEND-006), planteada con cuatro opciones (A: módulo Log de Daniel-KM, ya instalado; B: value annotations nativas; C: log a fichero; D: riesgo aceptado).

Dos huecos lo bloqueaban: qué vocabularios y properties usar (PEND-005) y cómo auditar (PEND-006). El propietario decide (chat 2026-06-15) auditar como RDF nativo —no con el módulo Log— y acota el conjunto de vocabularios.

## Alternativas consideradas

- **Auditoría con el módulo Log (Daniel-KM)** — ya instalado, sin dependencia nueva, log central consultable. Contra: la traza no viaja con el ítem en un export; menos «RDF puro».
- **Auditoría como value annotations `dcterms` (elegida)** — anota el propio valor curado con quién/cuándo/qué; nativo (core ≥3.2), portable, respeta NFR-002. Contra: no cubre cambios que no son valores (la visibilidad) y exige confirmar las properties contra la instalación.
- **Auditoría como statements `dcterms:provenance` a nivel de ítem** — uniforme, pero mezcla auditoría con la metadata descriptiva y pierde granularidad por-valor.
- **Renuncia (riesgo aceptado)** — descartada: se quiere traza de la curación.

## Decisión

1. **Vocabularios permitidos.** El módulo usa exclusivamente vocabularios ya presentes en Omeka-S: **`dcterms`, `lrmi`, `schema`**. No se añaden vocabularios nuevos. (`lrmi`/`schema`/`dcterms` para alineamiento y tags; la auditoría se apoya en `dcterms`, opcionalmente `schema`.)
2. **Auditoría de curación = RDF nativa vía value annotations.** Cada escritura de alineamiento/tag se anota sobre su propio valor con properties `dcterms`: **quién** (`dcterms:contributor`), **cuándo** (`dcterms:modified`), **qué** (`dcterms:provenance`). Sin módulo Log y sin tablas propias (respeta NFR-002).
3. **Alcance.** La visibilidad (`is_public`) se gestiona con el campo nativo del recurso (RF-003) pero **queda fuera de la auditoría RDF**: no se registra traza `dcterms` de sus cambios.
4. **Properties exactas pendientes.** El conjunto de vocabularios queda fijado aquí, pero las **properties concretas** (tanto del alineamiento por nivel como de la auditoría) se confirman contra la instalación real en **PEND-005**; no se inventan.

## Consecuencias

- La auditoría viaja con el ítem en cualquier export RDF; sin dependencia del módulo Log; sin tablas propias (coherente con NFR-002, sin necesitar la excepción).
- Se pierde el «log central» consultable que daría el módulo Log; consultar la auditoría exige recorrer las value annotations vía API.
- La visibilidad no tiene traza histórica en RDF (riesgo aceptado menor; el cambio es reversible y de bajo impacto).
- TASK-007 (auditoría) deja de depender de PEND-006: ahora depende de PEND-005 (properties exactas) y de TASK-004 (las acciones de curación que se auditan).

## Fuentes

- `docs/open-questions.md` PEND-005 y PEND-006.
- `docs/requirements.md` §1 (PEND-005, PEND-006), RF-003, RF-008, NFR-002.
- Skill `recatalogador` (invariantes de escritura RDF; mecanismo de value annotations en Omeka 4.2).
- Instrucciones del propietario en el chat (2026-06-15).
