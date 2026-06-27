# Diseño: Clasificador semántico curricular con anclaje *bottom-up*

**Fecha:** 2026-06-26
**Estado:** aprobado para implementación (revisa el diseño inicial E1+E2 del mismo día)

---

## Contexto

El clasificador IA (TASK-010) propone saberes básicos y criterios de evaluación **incoherentes**. El análisis identifica **dos** causas raíz, no una:

### Causa 1 — Candidatos opacos (síntoma evidente)
El LLM recibe los saberes/criterios como sus `dcterms:title`, que son **códigos** (`SBIG01SBI.1`, `SBIG01CE1.1`). No existe clasificación *semántica* posible sobre un código. La señal semántica real vive en `dcterms:description` (saberes: 29–375 chars; criterios: 170–500 chars), hoy ausente del prompt.

### Causa 2 — La cascada top-down es frágil por diseño (causa profunda)
El clasificador actual decide **Etapa → Curso → Asignatura ANTES** de mirar las descripciones de las hojas:

- **El Curso (nivel) es genuinamente ambiguo desde el contenido.** Un mismo tema ("el método científico") aparece en 1.º, 2.º y 3.º de ESO con distinta profundidad. El LLM debe *adivinar* el curso con señal débil; si falla, **todos** los saberes/criterios quedan acotados al subárbol equivocado → incoherencia garantizada por construcción. El curso se infiere mejor *a posteriori*, de qué descripciones encajan.
- **Coherencia por restricción, no garantizada.** Se confía en que cada paso acierte; nada asegura que el resultado sea un subgrafo coherente.

La formulación del propietario fija el norte: *"el encaje debe estar asociado semánticamente con el `dcterms:description` del saber básico"* (las **hojas** mandan) y *"se puede delimitar la materia… en una primera fase"* (delimitar la **asignatura**, evidente del contenido — no el curso).

---

## Escala del grafo curricular ESO (verificada 2026-06-26)

```
ESO (item 5055, schema:DefinedTermSet)
  └── 4 Cursos
        └── 66 Asignaturas (12–22 por curso; ~12–22 NOMBRES distintos)
              ├── 2298 Saberes básicos   (29–375 chars/desc, media ~155, ~39 tokens)
              └──  943 Criterios          (170–500 chars/desc, media ~300, ~75 tokens)
```

Aristas verificadas (`docs/referencia/curriculo-modelo-rdf.md`, ADR-0009):

| Nodo | Arista al padre | Ancestros denormalizados | `schema:about` (literal) |
| --- | --- | --- | --- |
| Asignatura | `lrmi:educationalLevel` → Curso | `dcterms:isPartOf` → Etapa | = nombre de la materia |
| Saber básico | `schema:inDefinedTermSet` → Asignatura | `lrmi:educationalAlignment` → Curso, `dcterms:isPartOf` → Etapa | = nombre de la materia |
| Criterio | `schema:inDefinedTermSet` → {Competencia, Asignatura} | `lrmi:educationalAlignment` → Curso, `dcterms:isPartOf` → Etapa | = nombre de la materia |

**Habilitador clave del bottom-up:** tanto saberes como criterios llevan `schema:about` (literal = nombre de la materia) y enlaces denormalizados al curso (`lrmi:educationalAlignment`) y a la etapa (`dcterms:isPartOf`). Esto permite **recuperar todos los saberes de una materia a través de todos sus cursos en UNA consulta**, y **derivar** el curso/asignatura concretos de cada hoja sin fijar el curso de antemano.

---

## Arquitectura aprobada: anclaje semántico *bottom-up*

Decisión del propietario (2026-06-26): subir a este rung de complejidad; **sin embeddings** (queda como roadmap, ver §E3). Cuatro fases:

### Fase A — Delimitación gruesa (barata, reduce contexto)
1. **Etapa** (`searchEtapas`): el LLM elige la etapa (4 candidatos). Evidente del contenido; desambigua nombres de materia que se repiten entre etapas. *No se escribe.*
2. **Familia de asignatura** (`searchSubjectFamilies(etapaId)`): el LLM elige el/los **nombre(s)** de materia entre los ~12–22 distintos de la etapa. **No fija el curso.** *No se escribe.*

### Fase B/C — Selección semántica de hojas (el núcleo)
3. **Saberes** (`searchLeaves('lrmi:teaches', etapaId, subjectName)`): recupera **todos** los saberes de esa materia **cruzando los cursos** y el LLM selecciona los relevantes sobre su `dcterms:description` enriquecida.
4. **Criterios** (`searchLeaves('lrmi:assesses', …)`): ídem.

Cada candidato-hoja viaja enriquecido con su linaje: `{id, title, description, block, courseId, courseTitle, subjectId}`.

### Fase D — Coherencia por derivación (garantía estructural)
5. El **Curso** (`lrmi:educationalLevel`) y la **Asignatura** (`schema:about`) **se derivan de las hojas elegidas**: la unión de los `courseId`/`subjectId` de los saberes y criterios seleccionados. Como cada hoja aporta el id de su padre real, **el subgrafo escrito es coherente por construcción** — exactamente el invariante exigido. El curso "emerge" de qué descripciones encajaron (resuelve la ambigüedad de nivel sin adivinarla).

> Si el recurso es genuinamente interdisciplinar o transversal a cursos, se proponen varios `courseId`/`subjectId`; el curador confirma o poda (ADR-0007: la IA propone, el curador confirma).

### E2 conservado — Pre-filtrado por bloque temático
La recuperación cruzando cursos **aumenta** el número de saberes (peor caso Matemáticas ≈ 61 × 4 ≈ 244). Si supera `BLOCK_THRESHOLD` (30), un paso LLM intermedio elige **bloques temáticos** (`dcterms:subject`, deduplicado entre cursos) y se filtran los candidatos antes de la selección fina. Fallback: si no se elige bloque, se usan todos. **No aplica a criterios** (su `dcterms:subject` son códigos de competencia, no bloques).

### Flujo y coste
```
A.1 Etapa            (LLM, 1 llamada — no se escribe)
A.2 Familia materia  (LLM, 1 llamada — no se escribe, no fija curso)
[E2] Bloques         (LLM, 0–1 llamada, solo si saberes > 30)
B   Saberes          (LLM, 1 llamada — selección por descripción)
C   Criterios        (LLM, 1 llamada — selección por descripción)
D   Derivación curso+asignatura (sin LLM, de las hojas elegidas)
+   Ejes temáticos   (TagClassifier, 1 llamada — sin cambios de flujo)
```
Total ≈ 5–6 llamadas LLM, similar al actual, con coherencia garantizada.

---

## Componentes y cambios

| Archivo | Cambio |
| --- | --- |
| `src/Service/CurriculumSearch.php` | `mapResults` enriquecido; nuevos `searchSubjectFamilies()`, `searchLeaves()`; helpers `firstLiteralValue()`, `firstResourceRef()`, `resourceRefMatchingTitle()` |
| `src/Service/Ai/TermResolverInterface.php` | Añadir `listSubjectFamilies(int $etapaId)` y `listLeaves(string $dim, int $etapaId, string $subjectName)`; `listCandidates` se mantiene para `etapa` y `dcterms:relation` |
| `src/Service/Ai/CurriculumTermResolver.php` | Implementar los dos métodos nuevos delegando en `CurriculumSearch` |
| `src/Service/Ai/PromptBuilder.php` | `buildSelectionPrompt` acepta candidatos ricos; `formatCandidate()` (usa `courseTitle` + `block` + `description` + código) |
| `src/Service/Ai/IndexSelection.php` | Añadir `mapIndicesToRows()` (devuelve las filas elegidas, no solo ids — necesario para el linaje) |
| `src/Service/Ai/CurricularClassifier.php` | Reescribir `classify()` a las fases A→D; `BLOCK_THRESHOLD`; derivación; pre-filtro de bloques |
| `src/Service/Ai/TagClassifier.php` | Pasar candidatos ricos a `buildSelectionPrompt` |
| Tests: `CurricularClassifierTest`, `PromptBuilderTest`, `TagClassifierTest`, `FakeTermResolver` | Reescritos al nuevo contrato (TDD) |

**Sin cambios:** `RecatalogService` (sigue recibiendo `{dimension => int[]}`), `IndexController::enrichLabels` (resuelve ids → títulos de forma genérica; los `educationalLevel`/`about` derivados fluyen igual). Verificar en implementación que no asume claves fijas.

**No romper TASK-004:** `CurriculumSearch` lo comparten el re-catalogador manual y la IA. Enriquecer `mapResults` con campos nuevos es **aditivo** (los consumidores leen por clave); los métodos nuevos no tocan los existentes (`searchDimension`, `searchEtapas`, `searchAxes`).

---

## E3 — Recuperación por embeddings (roadmap, PEND + TASK-011)

**No se implementa ahora.** Decisión de infraestructura **pendiente** (proveedor de embeddings no confirmado): registrar como **PEND** en `docs/requirements.md` y **TASK-011** en `docs/backlog.md`.

Punto de extensión: `TermResolverInterface`. Una `EmbeddingTermResolver` reemplazaría `listLeaves` por recuperación vectorial:
- Pre-calcular embeddings de `dcterms:description` de los ~3241 términos (índice en **fichero**, sin tablas Doctrine → respeta NFR-002; reindexado vía Job).
- En clasificación: embed del contenido → top-K por coseno (K≈15) → LLM rerank → misma derivación bottom-up (Fase D intacta).
- Beneficio: candidatos al LLM de ~30–244 a ~15; permite incluso prescindir de la delimitación por materia.
- Requisito: endpoint de embeddings (Anthropic no ofrece nativo; un proveedor OpenAI-compatible sí, vía `/embeddings`) y métricas de accuracy basales de esta entrega.

---

## Verificación

1. `make test` (PHPUnit) — toda la suite en verde, incluidos los tests reescritos del nuevo flujo bottom-up y la derivación.
2. `make lint` (PSR-12) — sin errores (stop hook activo).
3. Manual end-to-end en el admin de Omeka (`docker compose up -d`): clasificar 2–3 REA y confirmar:
   - El prompt de saberes muestra `[curso · bloque] descripción (código)`, no códigos sueltos.
   - Los saberes propuestos son coherentes con el contenido y `educationalLevel`/`about` **derivados** son sus padres reales.
   - Para Matemáticas (>30 saberes cross-grade) se activa el pre-filtro de bloques.
   - Etapa, familia de materia y ejes temáticos siguen funcionando.
4. Confirmar que el re-catalogador manual (TASK-004) no regresiona (mismas búsquedas, campos extra ignorados).
