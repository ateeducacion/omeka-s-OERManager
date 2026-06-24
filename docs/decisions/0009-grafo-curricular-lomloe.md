# ADR-0009: Modelo del grafo curricular LOMLOE

## Estado

Aceptado (2026-06-24)

## Contexto

El re-catalogador (TASK-004) y la futura catalogación IA-assistida (TASK-010) anclan los REA contra el currículo, que ya existe en Omeka como un grafo de items enlazados (el módulo lo **consume**, no lo posee ni lo modifica). ADR-0004 fijó las properties de alineamiento y ADR-0006 cómo se localizan las raíces, pero faltaba el modelo conceptual completo: qué nodos forman el árbol, cómo se distinguen y con qué aristas se enlazan. Sin él no se puede acotar la catalogación por contexto (ofrecer solo los saberes de la asignatura elegida, etc.) ni guiar a la IA.

Verificado contra la instalación real (2026-06-24, API del contenedor). Detalle, ejemplos y reglas de autoría en [docs/referencia/curriculo-modelo-rdf.md](../referencia/curriculo-modelo-rdf.md).

## Decisión

Se fija el siguiente modelo como la estructura curricular que el módulo asume y contra la que documenta cómo deben crearse los datos.

1. **Dos clases de recurso:**
   - `schema:DefinedTermSet` = **Etapas educativas** (raíces del árbol). Llevan `lrmi:educationalFramework` (p. ej. `LOMLOE`) y `dcterms:type = "Etapa educativa"`. Son las 4 raíces (Bachillerato, ESO, Ed. Primaria, Ed. Infantil).
   - `schema:DefinedTerm` = todos los **nodos hijos** (Curso, Asignatura, Competencia específica, Saber básico, Criterio de evaluación).

2. **La dimensión de un nodo se distingue por `dcterms:type`** (literal). Valores: `Etapa educativa`, `Curso`, `Asignatura`, `Competencia específica`, `Saber básico`, `Criterio de evaluación`.

3. **Jerarquía y arista al padre:**
   - Curso → `schema:inDefinedTermSet` → Etapa (DefinedTermSet)
   - Asignatura → `lrmi:educationalLevel` → Curso
   - Competencia específica → `schema:inDefinedTermSet` → Asignatura
   - Saber básico → `schema:inDefinedTermSet` → Asignatura
   - Criterio de evaluación → `schema:inDefinedTermSet` → {Competencia específica, Asignatura}

4. **Enlaces ancestro denormalizados** (presentes en asignatura/competencia/saber/criterio), que permiten acotar con una sola query: `dcterms:isPartOf` → Etapa; `lrmi:educationalAlignment` o `lrmi:educationalLevel` → Curso.

5. **Mapeo REA (`lrmi:LearningResource`) → nodo del grafo** (precisa ADR-0004):
   - `lrmi:educationalLevel` → **Curso** (no la Etapa; la Etapa es el ancestro).
   - `schema:about` → **Asignatura**.
   - `lrmi:teaches` → **Saberes básicos**.
   - `lrmi:assesses` → **Criterios de evaluación**.
   - `dcterms:relation` → **Eje temático** (DefinedTermSet aparte, ADR-0006).
   - Etapa y Competencia específica **no** se escriben en el REA: son nodos de navegación.

6. **Acotación contextual del re-catalogador** (RF-014): cada dimensión hija se filtra por el ancestro ya elegido — Cursos de la Etapa (`schema:inDefinedTermSet`), Asignaturas del Curso (`lrmi:educationalLevel`), Saberes/Criterios de la Asignatura (`schema:inDefinedTermSet`), con fallback por curso/etapa vía los enlaces denormalizados. Siempre con filtro `dcterms:type` + búsqueda incremental (NFR-004).

## Consecuencias

- El re-catalogador implementa la cascada Etapa→Curso→Asignatura→{Saberes, Criterios}. La Etapa es una ayuda de navegación en la UI, no se persiste.
- Los autores de datos del currículo deben respetar estas reglas (clase, `dcterms:type`, arista al padre y enlaces denormalizados) para que la catalogación acotada y la IA-assistida funcionen. La guía operativa vive en la referencia.
- La IA-assistida (TASK-010) podrá resolver etiquetas propuestas navegando el grafo por `dcterms:type` + aristas.
- No cambia el mapeo de properties de ADR-0004; lo precisa (educationalLevel = Curso).

## Fuentes

- API del contenedor (2026-06-24): items 5054/5055/5056/5057 (etapas), 24559/24560 (cursos), 24650 (asignatura), 25206 (competencia), 28322 (saber), 38014 (criterio).
- ADR-0004 (mapeo RDF), ADR-0006 (raíces y `dcterms:type`).
