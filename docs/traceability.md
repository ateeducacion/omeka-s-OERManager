# Matriz de trazabilidad — OERManager

> Cruza necesidad ↔ RF/NFR ↔ ADR ↔ TASK ↔ criterio de aceptación. Eslabón roto = defecto; eslabón aún sin decidir = `[PENDIENTE]` explícito. Fuentes: [requirements.md](requirements.md), [backlog.md](backlog.md), [decisions/](decisions/).

| Necesidad (origen: docs/referencia/contexto-modulo-rea.md) | RF/NFR | ADR | TASK | Criterio de aceptación |
| --- | --- | --- | --- | --- |
| Gestionar solo el catálogo de REA (`lrmi:LearningResource`) | RF-001 | `[PENDIENTE]` | TASK-002, TASK-003 | Listado admin filtra por `resource_class` |
| Vista maestra de gestión en panel admin | RF-002 | `[PENDIENTE: ADR vista maestra híbrida, decisión previa del propietario aún sin ADR]` | TASK-003 | `[PENDIENTE]` (PEND-007: columnas, filtros, panel) |
| Curar visibilidad público/privado | RF-003 | `[PENDIENTE]` | TASK-003 | `[PENDIENTE]` (PEND-007: roles y confirmación) |
| Re-catalogación curricular fiable y reversible | RF-004, NFR-004 | ADR-0002 (vocabularios) + ADR-0004 (mapeo: educationalLevel/about/teaches/assesses) | TASK-004 | Properties fijadas; reglas de negocio `[PENDIENTE]` (PEND-007) |
| Re-catalogación por etiquetas | RF-005 | ADR-0004 (`dcterms:relation`, controlado por `schema:DefinedTermSet`) | TASK-004 | `[PENDIENTE]` (PEND-007 / config) |
| Garantizar integridad del catálogo | RF-006 | ADR-0004 (campos a validar) | TASK-005 | Campos fijados; definición de «completo» `[PENDIENTE]` (PEND-007) |
| Estadísticas visuales + export | RF-007 | ADR-0004 (dimensiones) | TASK-006 | `[PENDIENTE]` (PEND-007: catálogo de gráficos) |
| Saber quién curó qué y cuándo | RF-008 | ADR-0002 | TASK-007 | Value annotations `dcterms` sobre el valor curado (quién/cuándo/qué); properties exactas `[PENDIENTE]` (PEND-005) |
| No romper el core de Omeka | NFR-001 | ADR-0001 (guardrails como parte del gobierno) | TASK-008 | `git diff` del core vacío; hook PreToolUse bloquea escrituras fuera del repo |
| Portabilidad de datos (sin tablas propias) | NFR-002 | ADR-0002 (auditoría RDF nativa, sin excepción de tablas) | TASK-007 | Sin entidades Doctrine propias; la auditoría usa value annotations `dcterms`, no tablas |
| Solo usuarios autorizados curan | NFR-003 | `[PENDIENTE]` | TASK-003, TASK-004 | `[PENDIENTE]` (PEND-007: matriz rol×acción) |
| Gobierno del desarrollo trazable y verificable | — | ADR-0001 | TASK-001, TASK-008 | Esta matriz + hooks en `.claude/settings.json` validables (`jq`); lint = `make lint` (PSR-12), Stop hook diferido hasta que pase en limpio |
| Paquete publicable con licencia definida | NFR-007 | ADR-0003 | TASK-001 | `composer.json`/`config/module.ini` declaran `license = GPL-3.0-or-later`; paquete `ate/oer-manager` |
