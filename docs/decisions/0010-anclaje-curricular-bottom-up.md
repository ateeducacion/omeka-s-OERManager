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

## Consecuencias

- `CurricularClassifier::classify` se reescribe a fases A→D; `CurriculumSearch`
  gana `searchSubjectFamilies`/`searchLeaves` (consulta por materia cross-grade
  con linaje). El mapeo RDF (ADR-0004) y el grafo (ADR-0009) no cambian.
- La coherencia deja de depender de aciertos en cadena: se garantiza por
  derivación. La ambigüedad de curso se resuelve por la semántica de las hojas.
- La IA sigue proponiendo; el curador confirma (ADR-0007). Si el recurso es
  transversal a cursos, se proponen varios y el curador poda.

## Limitaciones conocidas

- **Materia única (v1):** la Fase A.2 ancla a UNA familia de materia (selección de un solo nombre); un REA interdisciplinar a varias materias requeriría selección múltiple de materia (mejora futura). El cruce de cursos dentro de una materia sí está soportado.
- **Tope de candidatos (ENUM_LIMIT=300):** validado para ESO (peor caso ≈244 saberes de una materia cruzando cursos). `searchLeaves` trunca en `per_page` sin señal; otras etapas/marcos curriculares deben revalidar este tope antes de confiar en él.

## Fuentes

- Análisis y decisión de plan mode con el propietario, 2026-06-26.
- Diseño: docs/superpowers/specs/2026-06-26-clasificador-semantico-design.md.
- ADR-0009 (grafo), ADR-0004 (mapeo), ADR-0007 (IA propone/curador confirma).
