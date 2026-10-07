# ADR-0010: Anclaje curricular bottom-up (revisa la cascada top-down)

## Estado

Aceptado (2026-06-26). Revisa la estrategia de clasificación de ADR-0009/NFR-008
(cascada top-down) manteniendo intactos el modelo del grafo y el mapeo RDF.

## Contexto

El clasificador IA (TASK-010) proponía saberes/criterios incoherentes por dos
causas: (1) candidatos mostrados como códigos opacos, sin la `dcterms:description`
sobre la que clasificar; (2) la cascada top-down fija Etapa→Curso→Asignatura ANTES
de mirar las hojas, y el Curso es ambiguo desde el contenido (un tema aparece en
varios cursos): un error de curso propaga incoherencia a todo el subárbol.

## Decisión

1. **Anclaje bottom-up.** Delimitar gruesamente Etapa + **familia de materia**
   (nombre, sin fijar curso); seleccionar saberes/criterios por su
   `dcterms:description` cruzando los cursos de la materia; **derivar** Curso
   (`lrmi:educationalLevel`) y Asignatura (`schema:about`) como los padres reales
   de las hojas elegidas → coherencia por construcción.
2. **E1 — candidatos enriquecidos.** El prompt muestra `[curso · bloque]
   descripción (código)` para las hojas; solo título para nodos ya legibles.
3. **E2 — pre-filtro por bloque** cuando los saberes superan un umbral; fallback a
   todos si no se elige bloque. No aplica a criterios (su `dcterms:subject` son
   códigos de competencia).
4. **Sin embeddings** en esta entrega: se difiere a TASK-016/PEND-010. El punto de
   extensión es `TermResolverInterface`.
5. **Saberes y criterios son de primera clase: se intentan siempre, se omiten si
   vacíos.** El clasificador ejecuta el paso de selección para `lrmi:teaches` Y
   `lrmi:assesses` en cada clasificación (criterios igual que saberes, decisión del
   propietario 2026-06-26), pero **omite** del resultado la dimensión cuya selección
   queda vacía (`if (!$rows) continue;`). No se fuerza una propuesta no vacía: un REA
   con saberes claros pero sin criterio relacionado devuelve solo `lrmi:teaches`.
   Coherente con ADR-0007 (la IA propone, el curador confirma): forzar `≥1` por
   dimensión obligaría a alucinar. El curador siempre ve el input de criterios en el
   panel y puede rellenarlo a mano; lo que cambia es solo si viene pre-rellenado.

## Consecuencias

- `CurricularClassifier::classify` se reescribe a fases A→D; `CurriculumSearch`
  gana `searchSubjectFamilies`/`searchLeaves` (consulta por materia cross-grade
  con linaje). El mapeo RDF (ADR-0004) y el grafo (ADR-0009) no cambian.
- La coherencia deja de depender de aciertos en cadena: se garantiza por
  derivación. La ambigüedad de curso se resuelve por la semántica de las hojas.
- La IA sigue proponiendo; el curador confirma (ADR-0007). Si el recurso es
  transversal a cursos, se proponen varios y el curador poda.

## Afinado (TASK-018, 2026-06-30)

Verificado en el contenedor con el trazado del intercambio LLM, el flujo bottom-up
de esta decisión (materia antes de hojas; curso derivado sin LLM en la Fase D) ya
corría. TASK-018 afina la **Fase A** sin tocar el mapeo RDF (ADR-0004) ni el grafo
(ADR-0009):

- **Etapa multi-select (Fase A.1):** la etapa deja de ser una conjetura única que
  arrastra toda la cascada cuando el contenido es ambiguo (el mismo item se clasificó
  como Primaria en una pasada y ESO en otra). El LLM puede devolver **varias etapas**;
  sus familias de materia se unen con dedup por nombre.
- **Materia multi-select (Fase A.2):** supera la limitación «Materia única (v1)»; un
  REA interdisciplinar puede anclar a **varias materias**. Las hojas se reúnen por el
  producto etapas×materias con dedup por id y tope `LEAF_CAP=200`.
- **Criterios acotados a los cursos de los saberes (Fase C):** los criterios candidatos
  se filtran a los cursos derivados de los saberes ya elegidos (lista mucho más corta y
  precisa); fallback sin saberes → criterios de la materia. Más preciso que el
  pre-filtro por bloque genérico en el nivel crítico.
- **No bug — criterios vacíos:** que los criterios salgan `[]` sigue siendo coherente
  con el §5 de esta decisión (los criterios LOMLOE son competenciales/genéricos).

## Afinado (TASK-019, 2026-07-03)

Sesgo de **inclusividad en la etapa acotadora** (Fase A.1), sin tocar el mapeo RDF
(ADR-0004) ni el grafo (ADR-0009):

- **Problema.** La etapa solo acota (nunca se escribe, ADR-0009): delimita qué
  saberes/criterios llegan a las Fases B/C. Una etapa **omitida** deja fuera sus
  contenidos, que ya no podrán proponerse (falso negativo silencioso). El
  multi-select de A.1 (TASK-018) lo permite, pero no lo incentivaba.
- **Decisión.** El prompt del paso de etapa incluye una guía explícita: **ante
  duda de nivel, seleccionar TODAS las etapas plausibles** — es preferible incluir
  una de más (inocuo: las hojas se eligen por descripción y curso/materia se
  derivan abajo, §1) que dejar fuera la correcta. Se prima el **recall** del embudo.
- **Acotación del sesgo.** La guía se aplica **solo** a la etapa. NO a materia ni a
  las hojas (saberes/criterios), donde la precisión sí importa porque `schema:about`
  y `lrmi:educationalLevel` se derivan de ellas: sobre-incluir ahí degradaría la
  clasificación escrita. Implementado como parámetro opcional `guidance` en
  `PromptBuilder::buildSelectionPrompt`, pasado únicamente desde `pickEtapaIds`
  (`CurricularClassifier::ETAPA_GUIDANCE`); trazable en `llm_options`/prompt del panel.
- **Por qué aquí y no invertir etapa↔materia.** Se estudió mover la materia al
  primer paso (razón: la materia es «el qué», más identificable). Descartado: en el
  grafo LOMLOE la materia es un nodo **etapa-dependiente** (`listSubjectFamilies`
  está acotado por etapa; no hay índice global), la etapa es el corte coarse más
  barato y fiable (~4-6 candidatos cerrados) y **poda** la lista de materias, y la
  ambigüedad materia-clara/etapa-dudosa ya la absorbe la derivación de la Fase D con
  A.1 multi-select. Invertir solo reordena el embudo (no cambia la salida) y
  reintroduce ambigüedad. El sesgo de recall ataca el punto débil real sin reordenar.

## Afinado (TASK-056, 2026-10-06)

Medido con el conjunto de evaluación de TASK-057 (verdad = alineación que declaran los paquetes eXeLearning):
el clasificador no acertaba ningún saber o criterio declarado (0/57) y el curso nunca (F1 0,00). Tres
supuestos de este ADR no se cumplían; se corrigen sin tocar el mapeo RDF (ADR-0004) ni el grafo (ADR-0009):

- **La etapa de más NO era inocua.** El afinado de TASK-019 la daba por inocua; con el tope `LEAF_CAP`
  (200) y la recogida en orden, las hojas de las etapas sobrantes llenaban el cupo y las de la materia
  correcta no llegaban al LLM (Geometría canaria: Matemáticas elegida, 0 candidatos de Matemáticas). Se
  mantiene el recall solo si el contenido no dice la etapa: si la cita, se elige esa.
- **Paso A.3, cursos plausibles.** Tras la materia, el LLM elige entre los cursos de las materias elegidas
  (con la equivalencia LOMLOE ciclo → cursos en la guía: el modelo leía «2.º ciclo de EP» como 2º y 3º). El
  paso **acota** la búsqueda de hojas; el curso escrito se sigue **derivando** de las hojas (§1, coherencia
  por construcción). Sin curso elegido → todos (sin pérdida de cobertura); con un solo curso no hay llamada.
- **Reparto justo del cupo** entre pares (materia, curso) y etiquetas con contexto: materias con sus cursos,
  bloques con materia y curso, deduplicados por (materia, bloque).

Resultado con el mismo extractor `.elpx` (TASK-053), 3 pasadas × 4 REA: F1 de curso 0,00 → 0,44; saberes y
criterios declarados 0/37 → 9/57; el curso correcto aparece en 3 de 4 REA. El límite que queda está en la
selección fina de hojas dentro del curso correcto, no en la delimitación. Spec:
`docs/superpowers/specs/2026-10-06-task-056-classifier-candidates-design.md`.

## Limitaciones conocidas

- ~~**Materia única (v1):**~~ superada por TASK-018 (etapa y materia multi-select; ver «Afinado» arriba).
- **Tope de candidatos (ENUM_LIMIT=300):** validado para ESO (peor caso ≈244 saberes de una materia cruzando cursos). `searchLeaves` trunca en `per_page` sin señal; otras etapas/marcos curriculares deben revalidar este tope antes de confiar en él. El merge cross-etapa×materia añade su propio tope `LEAF_CAP=200` (coste de tokens, NFR-004/NFR-008).

## Fuentes

- Análisis y decisión de plan mode con el propietario, 2026-06-26.
- Diseño: docs/superpowers/specs/2026-06-26-clasificador-semantico-design.md.
- ADR-0009 (grafo), ADR-0004 (mapeo), ADR-0007 (IA propone/curador confirma).
