# Modelo RDF del currículo (grafo curricular LOMLOE) — referencia

> Verificado contra la instalación real (API del contenedor, 2026-06-24) para el re-catalogador (TASK-004), la catalogación IA-assistida (TASK-010) y las estadísticas (TASK-006). Es la fuente operativa de **ADR-0009** (modelo del grafo) y complementa ADR-0004 (mapeo) y ADR-0006 (raíces). Muestra de un REA en [samples/item-40427.jsonld](samples/item-40427.jsonld).

## 1. Clases

| Clase | resource_class (ref.) | Qué es |
| --- | --- | --- |
| `schema:DefinedTermSet` | 4042 | **Etapas educativas** = las 4 raíces del árbol (con `lrmi:educationalFramework`). |
| `schema:DefinedTerm` | 4041 | Todos los **nodos hijos** (Curso, Asignatura, Competencia específica, Saber básico, Criterio de evaluación). |

Las 4 etapas (`lrmi:educationalFramework = "LOMLOE"`, `dcterms:type = "Etapa educativa"`):
`5054 Bachillerato`, `5055 ESO`, `5056 Educación Infantil`, `5057 Educación Primaria` (con `schema:position`).

## 2. La dimensión se marca con `dcterms:type` (literal)

Valores exactos: `Etapa educativa`, `Curso`, `Asignatura`, `Competencia específica`, `Saber básico`, `Criterio de evaluación`. Es lo que el re-catalogador usa para que cada input ofrezca solo su tipo (filtro `eq`).

## 3. Jerarquía y aristas

```
Etapa educativa (DefinedTermSet, p. ej. 5054 "Bachillerato")
  └─ Curso                         schema:inDefinedTermSet → Etapa
       └─ Asignatura               lrmi:educationalLevel  → Curso
            ├─ Competencia específica  schema:inDefinedTermSet → Asignatura
            │     └─ Criterio de evaluación  schema:inDefinedTermSet → {Competencia, Asignatura}
            └─ Saber básico            schema:inDefinedTermSet → Asignatura
```

| Nodo (`dcterms:type`) | Ejemplo (id) | Arista al padre | Enlaces ancestro denormalizados |
| --- | --- | --- | --- |
| Curso | 24559 "1º Bachillerato" | `schema:inDefinedTermSet` → Etapa | — |
| Asignatura | 24650 "Literatura Canaria" | `lrmi:educationalLevel` → Curso (24560) | `dcterms:isPartOf` → Etapa (5054) |
| Competencia específica | 25206 "BLCAC1" | `schema:inDefinedTermSet` → Asignatura (24650) | `lrmi:educationalLevel`→Curso, `dcterms:isPartOf`→Etapa |
| Saber básico | 28322 "BLCA02SBI.1.5" | `schema:inDefinedTermSet` → Asignatura (24650) | `lrmi:educationalAlignment`→Curso, `dcterms:isPartOf`→Etapa |
| Criterio de evaluación | 38014 "BLCA02CE1.1" | `schema:inDefinedTermSet` → {Competencia (25206), Asignatura (24650)} | `lrmi:educationalAlignment`→Curso, `dcterms:isPartOf`→Etapa |

Convención de `dcterms:identifier`: `etapa:…`, `curso:…`, `asig:…`, `ce:…` (competencia), `sb:…` (saber), `crit:…` (criterio). Los criterios llevan además en `dcterms:subject` los códigos de competencias clave (p. ej. `CCL1|CCL2|…`).

## 4. Mapeo del REA (`lrmi:LearningResource`) → nodo

Resolver SIEMPRE por término; los `property_id` son de esta instalación (referencia).

| Dimensión (input del re-catalogador) | Property REA | property_id | Nodo del grafo |
| --- | --- | --- | --- |
| Curso | `lrmi:educationalLevel` | 7810 | **Curso** (no la Etapa) |
| Materia | `schema:about` | 7768 | Asignatura |
| Saberes básicos | `lrmi:teaches` | 7820 | Saber básico (varios) |
| Criterios de evaluación | `lrmi:assesses` | 7807 | Criterio de evaluación (varios) |
| Eje temático (tag) | `dcterms:relation` | 13 | Eje (DefinedTermSet aparte, ADR-0006) |
| Proyecto | `schema:isPartOf` | 7799 | item de proyecto — gestor aparte |
| Licencia | `dcterms:rights` | 15 | literal (CustomVocab) |

La **Etapa** y la **Competencia específica** no se escriben en el REA: son nodos de navegación/acotación.

## 5. Acotación contextual (cómo consulta el re-catalogador) — RF-014

Cada dimensión hija filtra por el ancestro ya elegido, además de `dcterms:type` + título + límite (NFR-004):

- **Cursos de la Etapa:** `dcterms:type=Curso` + `schema:inDefinedTermSet = etapaId`.
- **Asignaturas del Curso:** `dcterms:type=Asignatura` + `lrmi:educationalLevel = cursoId`.
- **Saberes de la Asignatura:** `dcterms:type=Saber básico` + `schema:inDefinedTermSet = asignaturaId`.
- **Criterios de la Asignatura:** `dcterms:type=Criterio de evaluación` + `schema:inDefinedTermSet = asignaturaId`.
- **Fallback sin asignatura:** acotar Saberes/Criterios por curso (`lrmi:educationalAlignment = cursoId`) o etapa (`dcterms:isPartOf = etapaId`).

## 6. Reglas de creación de la estructura (para autores de datos)

Para que la catalogación manual acotada y la IA-assistida funcionen, cada término debe crearse así:

1. **Clase correcta:** las 4 etapas como `schema:DefinedTermSet` con `lrmi:educationalFramework`; el resto como `schema:DefinedTerm`.
2. **`dcterms:type`** con el literal exacto de su dimensión (§2).
3. **Arista al padre** según §3 (la property correcta apuntando al item padre, como `resource:item`).
4. **Enlaces ancestro denormalizados** (`dcterms:isPartOf`→Etapa y `lrmi:educationalAlignment`/`lrmi:educationalLevel`→Curso) en saberes, criterios y competencias: son los que permiten acotar en una sola consulta.
5. **Título legible** (`dcterms:title`) e **identificador** (`dcterms:identifier`) con su prefijo (§3).
6. Coherencia: un saber/criterio debe colgar de la asignatura correcta; un criterio, además, de su competencia específica.

Estas reglas son las que la **IA-assistida** (TASK-010) asume al resolver etiquetas propuestas hacia items-término navegando el grafo.

## 7. Confirmado / pendiente

- **`dcterms:type` es literal** (filtro `eq`, confirmado 2026-06-24). Mejora propuesta RF-013: migrarlo a `skos:Concept` (pasaría a `res`).
- **Value annotations (`@annotation`)** de la auditoría (ADR-0002): formato verificado contra el contenedor (2026-06-25), se escribe correctamente.
- **`schema:about` en Saberes y Criterios es LITERAL** (verificado 2026-06-26): los nodos de tipo `Saber básico` y `Criterio de evaluación` llevan `schema:about` con el **nombre de la materia** como literal (p. ej. `"Biología y Geología"`), igual que las Asignaturas. Esto permite recuperar todas las hojas de una materia cruzando cursos en una sola consulta `dcterms:type + schema:about eq <nombre>`. Verificación: `dcterms:type='Saber básico' + schema:about eq 'Biología y Geología' + dcterms:isPartOf res 5055` → 93 saberes en 1º/3º/4º ESO. Este filtro es el que usa `searchLeaves` del clasificador bottom-up (TASK-015/ADR-0010).
