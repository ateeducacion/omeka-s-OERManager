# Matriz de trazabilidad — OERManager

> Cruza necesidad ↔ RF/NFR ↔ ADR ↔ TASK ↔ criterio de aceptación. Eslabón roto = defecto; eslabón aún sin decidir = `[PENDIENTE]` explícito. Fuentes: [requirements.md](requirements.md), [backlog.md](backlog.md), [decisions/](decisions/).

| Necesidad (origen: docs/referencia/contexto-modulo-rea.md) | RF/NFR | ADR | TASK | Criterio de aceptación |
| --- | --- | --- | --- | --- |
| Gestionar solo el catálogo de REA (`lrmi:LearningResource`) | RF-001 | `[PENDIENTE]` | TASK-002, TASK-003 | Listado admin filtra por `resource_class` |
| Vista maestra de gestión en panel admin | RF-002 | ADR-0005 (columnas, filtros, panel v1; v1 implementada en TASK-003) | TASK-003 | v1: tabla `admin/oer-manager` con columnas/filtros del spec aprobado (hecha); objetivo completo (edición inline, columna de integridad, historial) fijado en requirements.md |
| Curar visibilidad público/privado | RF-003 | ADR-0005 (flujo v1; v1 implementada en TASK-003) | TASK-003 | v1: toggle individual + lote con confirmación; ACL nativa de edición (hecha); matriz rol×acción propia (`editor`+) fijada en requirements.md como mejora futura |
| Re-catalogación curricular fiable y reversible | RF-004, NFR-004 | ADR-0002 (vocabularios) + ADR-0004 (mapeo: educationalLevel/about/teaches/assesses) + ADR-0006 (raíz de los DefinedTermSet curriculares) | TASK-004 | Properties, cardinalidad (múltiple) y roles (editor+; isPartOf solo admin) fijados en requirements.md |
| Re-catalogación por etiquetas | RF-005 | ADR-0004 (`dcterms:relation`, controlado por `schema:DefinedTermSet`) + ADR-0006 (raíz configurable por ID de item) | TASK-004 | Rol `editor` o superior; raíz del DefinedTermSet fijada en config del módulo (ADR-0006) |
| Garantizar integridad del catálogo | RF-006 | ADR-0004 (campos a validar) | TASK-005 | **Hecho (TASK-005):** `IntegrityChecker::check()` valida dead-links de alineamiento (error) y completitud mínima/por plantilla (warning); hook en `api.create.post`/`api.update.post` de ItemAdapter |
| Estadísticas visuales + export | RF-007 | ADR-0004 (dimensiones) | TASK-006 | Conteo simple, cruce de 2 dimensiones y % de completitud; export CSV de datos agregados |
| Saber quién curó qué y cuándo | RF-008 | ADR-0002 | TASK-007 | Value annotations `dcterms` sobre el valor curado (quién/cuándo/qué); properties exactas `[PENDIENTE]` (PEND-005) |
| No romper el core de Omeka | NFR-001 | ADR-0001 (guardrails como parte del gobierno) | TASK-008 | `git diff` del core vacío; hook PreToolUse bloquea escrituras fuera del repo |
| Portabilidad de datos (sin tablas propias) | NFR-002 | ADR-0002 (auditoría RDF nativa, sin excepción de tablas) | TASK-007 | Sin entidades Doctrine propias; la auditoría usa value annotations `dcterms`, no tablas |
| Solo usuarios autorizados curan | NFR-003 | `[PENDIENTE]` | TASK-003, TASK-004 | Matriz rol×acción fijada en requirements.md: `editor`+ para visibilidad/alineamiento/tags, `global_admin`/`site_admin` para proyecto |
| Gobierno del desarrollo trazable y verificable | — | ADR-0001 | TASK-001, TASK-008 | Esta matriz + hooks en `.claude/settings.json` validables (`jq`); lint = `make lint` (PSR-12), Stop hook diferido hasta que pase en limpio |
| Paquete publicable con licencia definida | NFR-007 | ADR-0003 | TASK-001 | `composer.json`/`config/module.ini` declaran `license = GPL-3.0-or-later`; paquete `ate/oer-manager` |
