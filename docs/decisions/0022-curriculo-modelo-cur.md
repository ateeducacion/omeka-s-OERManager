# ADR-0022: El currículo en Omeka con el modelo `cur:` del repositorio curricular

## Estado

Aceptado (2026-11-09). Reemplaza a ADR-0009 y al punto 2 de ADR-0006; precisa ADR-0002 y ADR-0004.

## Contexto

ADR-0009 describió el grafo curricular tal como estaba cargado en la instancia de desarrollo:
`schema:DefinedTermSet` (etapas) y `schema:DefinedTerm` (resto), la dimensión de cada nodo en un
literal `dcterms:type` y la jerarquía Etapa←Curso←Asignatura←{Saber, Criterio}. Esa carga fue una
primera aproximación, hecha a partir de la exportación de ProIDEAC.

El currículo se mantiene ahora en el repositorio
[`ateeducacion/curriculo_lomloe_canarias`](https://github.com/ateeducacion/curriculo_lomloe_canarias),
que publica versiones (`curriculo.json`, Turtle SKOS y el vocabulario `cur.ttl`) contrastadas
con los documentos oficiales. Su modelo distingue más que ADR-0009: materia de la etapa y materia
en un curso, bloques competenciales, bloques de saberes con apartados anidados, descriptores
operativos y nodos retirados que no se borran. Un módulo de sincronización aparte cargará cada
versión en Omeka siguiendo un contrato escrito
([`docs/omeka.md`](https://github.com/ateeducacion/curriculo_lomloe_canarias/blob/main/docs/omeka.md)).

No hay REA en producción enlazados al grafo de ADR-0009: se puede cambiar el modelo sin migrar.

## Alternativas consideradas

- **Que el módulo de sincronización escriba con la forma de ADR-0009** (ampliada con lo que
  falta). OERManager apenas cambia, pero quedan dos modelos que traducir y se pierde lo que
  ADR-0009 no tiene (bloques, materia de la etapa, retirados).
- **Modelo del repositorio más las aristas de schema.org** (`schema:inDefinedTermSet`,
  `dcterms:isPartOf`) para cambiar menos OERManager. Duplica cada enlace y obliga a mantener dos
  jerarquías coherentes.
- **Modelo del repositorio, `cur:` y SKOS (elegida).** Un solo modelo en Omeka, el mismo que el
  publicado; OERManager se adapta.
- **Que OERManager cargue el currículo** en vez de un módulo aparte. Descartada: OERManager
  consume el currículo, no lo mantiene (ya lo decía ADR-0009), y los dos tienen ritmos de
  versión distintos.

## Decisión

1. **Modelo.** Los términos del currículo son ítems de clase `cur:<Tipo>` (`Etapa`, `Curso`,
   `Materia`, `MateriaCurso`, `CompetenciaEspecifica`, `BloqueCompetencial`, `CriterioEvaluacion`,
   `BloqueSaberes`, `SaberBasico`) con propiedades SKOS y `cur:`, según el contrato
   `docs/omeka.md` del repositorio curricular. Todos pertenecen al conjunto «Currículo LOMLOE de
   Canarias»; los retirados no se borran y llevan `skos:changeNote`.
2. **Propiedades del REA** (se mantienen las de ADR-0004; cambian sus rangos):
   - `lrmi:educationalLevel` → `cur:Curso`;
   - `schema:about` → `cur:MateriaCurso` (la materia en un curso, como la «Asignatura» de ADR-0009);
   - `lrmi:teaches` → `cur:SaberBasico`, de cualquier nivel;
   - `lrmi:assesses` → `cur:CriterioEvaluacion`.

   Etapa, materia de la etapa y competencias son nodos de navegación: no se escriben en el REA.
3. **Vocabularios (precisa ADR-0002 §1).** OERManager sigue escribiendo solo en `dcterms`,
   `lrmi` y `schema`. Lee `skos` y `cur` de los ítems del currículo, que instala y escribe el
   módulo de sincronización, no OERManager («sembrar, no poseer», ADR-0013).
4. **Configuración.** Desaparecen los ajustes `oermanager_type_*` (literal de `dcterms:type`
   por dimensión) y `oermanager_educational_framework`: la dimensión la fija la clase. Queda un
   ajuste: el conjunto de ítems del currículo, referencia fija como pide ADR-0006. El punto 1 de
   ADR-0006 (ejes temáticos) no cambia.
5. **Consultas.** Todas dentro del conjunto del currículo y con `skos:changeNote` sin valor
   (`nex`), que identifica los términos vigentes. Cascada del re-catalogador: etapas (clase
   `cur:Etapa`); cursos (`cur:Curso` con `cur:etapa`); materias en curso (`cur:MateriaCurso` con
   `cur:curso`); saberes y criterios (`cur:SaberBasico` y `cur:CriterioEvaluacion` con
   `cur:materiaCurso`), los saberes en árbol por `skos:broader`. Las familias de asignaturas, que
   hoy se agrupan por nombre, son `cur:Materia` (sus cursos, por `cur:materia`). El texto de un
   término es `skos:definition`; su título, `skos:prefLabel`.
6. **Integridad.** Regla nueva: aviso para los REA que enlazan un término retirado, con el
   sustituto de `dcterms:isReplacedBy` si lo hay.
7. **Instancia de desarrollo.** Se carga de cero con el módulo de sincronización; se eliminan
   los ítems de ADR-0009 y se rehace la configuración.

## Consecuencias

- Cambian `CurriculumSearch`, `Stats\CurriculumOutline`, `RecatalogService`,
  `Governance\CurricularPairs`, `Form\ConfigForm` y el clasificador de ADR-0010 (familias =
  `cur:Materia`; descripción de las hojas = `skos:definition` en vez de `dcterms:description`).
  La adaptación es TASK-064, bloqueada hasta que el módulo de sincronización cargue el currículo
  en la instancia.
- La dimensión deja de depender de un literal configurable: menos ajustes y ningún error por un
  `dcterms:type` mal escrito.
- La cascada gana precisión: la materia de la etapa existe como nodo y deja de inferirse por
  nombre.
- Mientras TASK-064 no esté hecha, OERManager no funciona con un currículo cargado según este
  modelo. No afecta a producción: no hay REA.
- RF-014 se mantiene en su intención (acotación contextual por el grafo); su detalle técnico
  (`dcterms:type`, aristas de ADR-0009) queda sustituido por el punto 5.

## Fuentes

- Contrato: `docs/omeka.md` de `ateeducacion/curriculo_lomloe_canarias`.
- Especificación: `docs/superpowers/specs/2026-11-09-contrato-omeka-design.md` del mismo
  repositorio.
- Decisiones del propietario (chat 2026-11-09): modelo del repositorio en Omeka (opción 2),
  módulo de sincronización aparte, `schema:about` → materia en un curso, carga de cero.
