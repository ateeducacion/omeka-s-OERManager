# ADR-0005: Vista maestra v1 — columnas, filtros y panel de detalle (PEND-007 punto 1)

## Estado

Aceptado (2026-06-16)

**Parcialmente modificado por [ADR-0016](0016-ampliacion-reglas-integridad-y-anclaje.md) (2026-08-11):** la regla de estado de anclaje de §4 exige además que ningún curso del REA quede sin materia que lo sostenga. El resto de esta decisión —columnas, filtros, drawer, arquitectura y manejo de errores— sigue vigente y **no** queda reemplazado.

## Contexto

TASK-003 (vista maestra del catálogo en el panel admin) estaba bloqueada por PEND-007, que agrupa todos los detalles de RF/NFR aún sin fijar. El 2026-06-15 se redactó un borrador de diseño (`docs/superpowers/specs/2026-06-15-vista-maestra-design.md`) que cubre el punto 1 de PEND-007 (columnas, filtros y panel de detalle) para un alcance v1 limitado a lectura + curación de visibilidad. El borrador quedó marcado «propuesto (pendiente de revisión del propietario)» y no se formalizó como decisión hasta confirmarlo con el propietario.

## Alternativas consideradas

- **A) Aprobar el borrador tal cual** — desbloquea TASK-003 ya, sin reabrir el diseño.
- **B) Aprobar con cambios** — requeriría especificar qué cambia antes de fijar la decisión.
- **C) No aprobar nada y esperar a que se resuelva PEND-007 por completo** — mantiene TASK-003 bloqueada indefinidamente, incluido el resto de PEND-007 (re-catalogador, matriz rol×acción, integridad, estadísticas, rendimiento, i18n, accesibilidad) que no son necesarios para arrancar la v1 de la vista maestra.

## Decisión

Se aprueba el diseño v1 de `docs/superpowers/specs/2026-06-15-vista-maestra-design.md` **sin cambios** como resolución de PEND-007 punto 1, exclusivamente para el alcance ahí descrito (lectura + curación de visibilidad; re-catalogador, panel configurable, integridad completa y matriz rol×acción propia quedan fuera de v1 y siguen abiertos en el resto de PEND-007). Columnas, filtros, panel de detalle, arquitectura (página server-rendered + drawer jQuery sobre la REST API) y manejo de errores quedan fijados según ese documento.

## Consecuencias

- TASK-003 queda desbloqueada para implementación.
- El resto de PEND-007 (reglas del re-catalogador, integridad/plantilla REA, catálogo de estadísticas, matriz rol×acción completa, rendimiento, i18n, accesibilidad) sigue `[PENDIENTE]` y bloquea TASK-004/005/006 y la matriz rol×acción definitiva.
- Cambios futuros al alcance v1 (p. ej. panel configurable) requieren un ADR nuevo que reemplace o complemente este.

## Fuentes

- `docs/superpowers/specs/2026-06-15-vista-maestra-design.md`
- Confirmación del propietario en el chat (2026-06-16).

---

*Registro append-only: los ADR no se borran ni se reescriben. Para cambiar una decisión, crear un ADR nuevo y marcar este como «Reemplazado por ADR-NNNN». Numeración correlativa de cuatro dígitos; no se reutiliza.*
