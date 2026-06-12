# Matriz de trazabilidad — OERManager

> Cruza necesidad ↔ RF/NFR ↔ ADR ↔ TASK ↔ criterio de aceptación. Eslabón roto = defecto; eslabón aún sin decidir = `[PENDIENTE]` explícito. Fuentes: [requirements.md](requirements.md), [backlog.md](backlog.md), [decisions/](decisions/).

| Necesidad (origen: docs/referencia/contexto-modulo-rea.md) | RF/NFR | ADR | TASK | Criterio de aceptación |
| --- | --- | --- | --- | --- |
| Gestionar solo el catálogo de REA (`lrmi:LearningResource`) | RF-001 | `[PENDIENTE]` | TASK-002, TASK-003 | Listado admin filtra por `resource_class` |
| Vista maestra de gestión en panel admin | RF-002 | `[PENDIENTE: ADR vista maestra híbrida, decisión previa del propietario aún sin ADR]` | TASK-003 | `[PENDIENTE]` (PEND-007: columnas, filtros, panel) |
| Curar visibilidad público/privado | RF-003 | `[PENDIENTE]` | TASK-003 | `[PENDIENTE]` (PEND-007: roles y confirmación) |
| Re-catalogación curricular fiable y reversible | RF-004, NFR-004 | `[PENDIENTE: ADR mapeo RDF, depende de PEND-005]` | TASK-004 | `[PENDIENTE]` (PEND-005 + PEND-007) |
| Re-catalogación por etiquetas | RF-005 | `[PENDIENTE]` | TASK-004 | `[PENDIENTE]` (PEND-005, PEND-007) |
| Garantizar integridad del catálogo | RF-006 | `[PENDIENTE]` | TASK-005 | `[PENDIENTE]` (PEND-007: reglas de «íntegro») |
| Estadísticas visuales + export | RF-007 | `[PENDIENTE]` | TASK-006 | `[PENDIENTE]` (PEND-007: catálogo de gráficos) |
| Saber quién curó qué y cuándo | RF-008 | `[PENDIENTE: ADR auditoría, depende de PEND-006]` | TASK-007 | `[PENDIENTE]` (PEND-006) |
| No romper el core de Omeka | NFR-001 | ADR-0001 (guardrails como parte del gobierno) | TASK-008 | `git diff` del core vacío; hook PreToolUse bloquea escrituras fuera del repo |
| Portabilidad de datos (sin tablas propias) | NFR-002 | `[PENDIENTE: ADR si se acepta la excepción de auditoría]` | TASK-007 | Sin entidades Doctrine propias salvo ADR |
| Solo usuarios autorizados curan | NFR-003 | `[PENDIENTE]` | TASK-003, TASK-004 | `[PENDIENTE]` (PEND-007: matriz rol×acción) |
| Gobierno del desarrollo trazable y verificable | — | ADR-0001 | TASK-001, TASK-008 | Esta matriz + hooks en `.claude/settings.json` validables (`jq`); lint = `make lint` (PSR-12), Stop hook diferido hasta que pase en limpio |
