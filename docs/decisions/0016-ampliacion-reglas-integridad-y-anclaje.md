# ADR-0016: Ampliación de las reglas de integridad (RF-006) y de anclaje curricular (ADR-0005 §4)

## Estado

Aceptado (2026-08-11)

## Contexto

La rebanada 2 de TASK-028 estrenó en la vista maestra dos señales que hasta entonces existían pero no se veían: el estado de anclaje curricular, calculado desde TASK-003, y el comprobador de integridad, que desde TASK-005 se ejecutaba en cada guardado y **volcaba sus incidencias solo al log**. Al exponerlas, dos reglas heredadas resultaron insuficientes contra el dato real.

**Primera: la comprobación de integridad podía silenciarse a sí misma.** `IntegrityChecker` aplicaba las reglas mínimas —anclaje presente y licencia presente— como rama **`else`** de «¿el recurso tiene plantilla?». Con 0 de 19 REA con plantilla asignada, los 19 iban por la rama mínima y nadie lo había notado. En cuanto PEND-012 aterrizase la plantilla REA, un recurso con plantilla habría dejado de evaluar anclaje y licencia salvo que la plantilla los declarase obligatorios: la integridad podía pasar de 18 avisos a cero **sin que el catálogo mejorase en nada**. Es la trampa que TASK-027 §8.1 anticipó. Al mismo tiempo, `checkCompleteness` contaba un valor **literal** en una property de enlace como valor presente, de modo que los 4 valores literales del catálogo (`schema:about` ×3, `lrmi:educationalLevel` ×1) pasaban por «ok» — el defecto D2 del estudio de campos.

**Segunda: un anclaje podía declararse completo apuntando a cursos que su propio grafo no sostiene.** La regla de ADR-0005 §4 definía `complete` como «etapa + materia + ≥1 criterio + ≥1 saber». No comprobaba la **coherencia** entre ambos: un REA podía declarar `lrmi:educationalLevel = 3º ESO` sin que ninguna de sus materias perteneciera a ese curso. Medido sobre el catálogo real, **7 de los 19 REA** están en esa situación (`3º Primaria`, `2º ESO`, `1º Bachillerato`…) y los 19 se contaban como completos.

El segundo hallazgo salió al renderizar la tabla en el admin con sesión real por primera vez, cosa que ni el estudio de campos (TASK-027) ni las revisiones de la rebanada 2 habían podido hacer.

## Alternativas consideradas

**Para la integridad:**

- **A) Dejar RF-006 intacto** — la columna se limita a exponer lo que el comprobador ya sabe. No toca gobierno, pero asume que duplica a la columna de Licencia y que la trampa de la plantilla sigue armada para cuando se cierre PEND-012.
- **B) Aplicar el mínimo siempre y sumarle la plantilla** — cierra la trampa antes de que se abra y da a la columna tres motivos distintos de aviso.
- **C) Lo anterior más avisos nuevos por descripción y tipo de recurso ausentes** — más varianza desde el primer día, pero amplía RF-006 bastante más allá de lo que PEND-007 punto 4 aprobó.

**Para el anclaje:**

- **D) Mostrar el curso huérfano como nota neutra** — informa sin cambiar el estado. Barato, pero deja que un anclaje incoherente siga contando como correcto.
- **E) Degradar el estado a `partial`** — trata el curso huérfano como lo que es: un anclaje mal hecho.
- **F) Un estado nuevo, distinto de `partial`** — más preciso, pero rompe el contrato de tres estados del filtro y del atributo `data-*`, y multiplica el vocabulario visual sin que el curador gane una acción distinta.

## Decisión

**Se acepta B para la integridad.** Las reglas mínimas de RF-006 —anclaje presente y licencia presente— se aplican **siempre**; los campos obligatorios de la plantilla REA se **suman** en vez de sustituirlas. Se añade el aviso `literal_in_link_property` (severidad *warning*) cuando una de las cuatro properties de anclaje trae un valor literal donde debería enlazar a un item-término. Se descarta C: ampliar RF-006 a descripción y tipo excede lo aprobado en PEND-007 punto 4 y se decidirá, si procede, cuando lo pida el trabajo.

**Se acepta E para el anclaje.** Un curso del REA al que ninguna de sus materias corresponde degrada el estado de `complete` a `partial`. El estado `none` sigue mandando sobre todo lo demás: sin criterios ni saberes no hay anclaje que graduar, y un curso huérfano no lo empeora. Se descarta F para no romper el contrato de tres estados que sostienen el filtro y el atributo `data-*` de la fila.

Ambas decisiones las tomó el propietario en conversación: la de integridad el **2026-08-03**, al elegir entre las alternativas de arriba; la de anclaje el **2026-08-10**, tras revisar la tabla renderizada en el admin real («debe marcarse como advertencia y como tipo anclaje curricular: parcial»).

**Alcance sobre ADR-0005.** Este ADR **modifica exclusivamente la regla de estado de anclaje de ADR-0005 §4**. El resto de ADR-0005 —columnas, filtros, drawer, arquitectura y manejo de errores de la vista maestra v1— sigue vigente y **no queda reemplazado**. ADR-0005 se anota en consecuencia, sin reescribir su decisión.

## Consecuencias

- **El reparto de anclaje del catálogo cambia de 19 completos / 0 parciales a 12 / 7.** Siete REA que se daban por bien anclados dejan de estarlo. No es una regresión: es el dato que la regla anterior no sabía ver.
- **El filtro «parcial» tiene datos con los que ejercitarse por primera vez.** El proyecto arrastraba desde TASK-028 que los 19 REA estaban todos en `complete` y ese filtro nunca se había podido probar contra dato real. Cierra parte de la deuda que TASK-030 tenía anotada.
- **La integridad deja de poder silenciarse por asignar una plantilla**, así que PEND-012 puede cerrarse sin abrir el agujero que TASK-027 §8.1 preveía.
- **Los 4 valores literales del catálogo dejan de pasar por «ok»** y se hacen visibles y filtrables.
- **Coste aceptado, no medido a escala:** `AlignmentStatus::statusFor()` pasa de cuatro lecturas en memoria a recorrer el grafo curricular (`CurricularPairs`), porque necesita saber qué cursos cubre cada materia. Medido a 19 REA: 0,059 s (~3 ms por item). El filtro computado lo evalúa sobre hasta 2 000 items; el coste real debería estar acotado por el tamaño del grafo curricular, que se comparte entre REA vía el *identity map* de Doctrine, **pero eso no se ha medido con datos suficientes**. Queda en la deuda de TASK-030.
- **El listener de TASK-005 empieza a volcar los avisos nuevos al log de Omeka** en cada guardado de los REA afectados. Es consecuencia buscada: detectar es el objetivo.
- Las reglas viven en clases **puras y probadas en host** (`Governance\IntegrityPolicy`, `Governance\AlignmentStatusValue`); los comprobadores quedan como proyectores. Cambiarlas en el futuro no exige tocar código que solo se puede verificar en contenedor.

## Fuentes

- `docs/superpowers/specs/2026-08-03-task-028-rebanada-2-design.md` (D-3 y D-6).
- ADR-0013 §Afinado (TASK-028 rebanada 2, 2026-08-05), donde el cambio de RF-006 se describió al cerrar la rebanada.
- ADR-0005 §4 (regla de estado de anclaje que este ADR modifica) y ADR-0004 (mapeo RDF de las properties implicadas).
- Verificación sobre el catálogo real: `test/container/columns-check.php`, **8 OK · 0 FAIL · 1 SKIP**, exit 0 — `complete=12 partial=7 none=0`, `literal_in_link_property=4`, `missing_license=18`.
- Decisiones del propietario en el chat: 2026-08-03 (integridad) y 2026-08-10 (anclaje).

---

*Registro append-only: los ADR no se borran ni se reescriben. Para cambiar una decisión, crear un ADR nuevo y marcar este como «Reemplazado por ADR-NNNN». Numeración correlativa de cuatro dígitos; no se reutiliza.*
