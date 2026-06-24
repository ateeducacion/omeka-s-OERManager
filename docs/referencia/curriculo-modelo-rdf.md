# Modelo RDF del currículo en la instalación real (referencia)

> Notas verificadas contra la instalación real (2026-06-23, JSON-LD del propietario) para alimentar el re-catalogador (TASK-004), la catalogación IA-assistida (TASK-010) y las estadísticas (TASK-006). Complementa ADR-0004 (mapeo) y ADR-0006 (raíces). Muestra de un REA completo en [samples/item-40427.jsonld](samples/item-40427.jsonld).

## Properties de alineamiento sobre el REA (item `lrmi:LearningResource`)

Resolver SIEMPRE por término; los `property_id` son específicos de esta instalación (anotados solo como referencia).

| Dimensión | Property | property_id (ref.) | Tipo | Ejemplo de destino (display_title) |
| --- | --- | --- | --- | --- |
| Etapa / nivel | `lrmi:educationalLevel` | 7810 | resource:item | "1º Bachillerato" (item 24559) |
| Materia | `schema:about` | 7768 | resource:item | "Literatura Canaria" (item 24650) |
| Saberes básicos | `lrmi:teaches` | 7820 | resource:item (varios) | "BLCA02SBI.1.5" (item 28322) |
| Criterios de evaluación | `lrmi:assesses` | 7807 | resource:item (varios) | "BLCA02CE1.1" (item 38014) |
| Eje temático (tag) | `dcterms:relation` | 13 | resource:item | "Patrimonio" (item 40289) |
| Proyecto | `schema:isPartOf` | 7799 | resource:item | "UCTICEE" (item 40433) — gestor aparte |
| Licencia | `dcterms:rights` | 15 | literal | "ccbysa" |

En el JSON-LD de lectura, un resource value aparece como `"type": "resource"`; al escribir, el data type es `resource:item`.

## Estructura de los items-término del currículo (DefinedTerm)

Confirmado por el propietario (2026-06-23):

- **La dimensión de un término se distingue por `dcterms:type`.** Es lo que separa, dentro de un mismo marco, una etapa de una materia, de un saber o de un criterio. El re-catalogador acota cada input curricular filtrando por el valor de `dcterms:type` correspondiente (valores configurables en el módulo, por ser específicos de la instalación).
- **Los términos forman un grafo jerárquico**, enlazándose a sus términos "padre"/contenedores con:
  - `dcterms:isPartOf`
  - `schema:inDefinedTermSet`
  - `lrmi:educationalAlignment`
  - `lrmi:educationalLevel`
- Los `schema:DefinedTermSet` se agrupan por **marco** (`lrmi:educationalFramework`, en la instalación de referencia `LOMLOE`); no hay un set separado por dimensión (ADR-0006). De ahí que la dimensión se resuelva por `dcterms:type`, no por pertenencia a set.
- Los códigos de saberes/criterios (p. ej. `BLCA02SBI.1.5`, `BLCA02CE1.1`) llevan prefijo de materia/etapa: indican su lugar en el grafo.

## Confirmado / pendiente

- **`dcterms:type` es literal** (confirmado 2026-06-24): el re-catalogador filtra con `eq`. Es un literal compartido por los términos de una misma dimensión.

Pendiente de confirmar en contenedor:

- Qué property concreta del grafo conecta cada nivel (saber→materia, criterio→materia/etapa), para una posible **acotación contextual** (filtrar Saberes/Criterios por la materia/etapa ya elegida). Mejora propuesta, fuera del primer corte de TASK-004.
- El formato exacto de las **value annotations** (`@annotation`) al escribir auditoría (ADR-0002).

## Mejoras propuestas

- **RF-013 — `dcterms:type` como `skos:Concept` (recurso):** hoy la dimensión se marca con un literal `dcterms:type`. Migrar esos literales a recursos controlados (`skos:Concept`) daría un vocabulario de tipos enlazable y sin erratas. Afecta al dato del currículo (que el módulo consume, no posee) y al re-catalogador, que pasaría de filtrar `eq` a `res`. Tarea posterior.
- **Acotación contextual por el grafo** (ver arriba).
