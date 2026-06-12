# Backlog del módulo OERManager

> IDs `TASK-NNN` correlativos estables; no se reutilizan ni renumeran. Estados: `pendiente` / `bloqueada` / `en curso` / `hecha`. Enlaces a [requirements.md](requirements.md) y [decisions/](decisions/).

| ID | Descripción | Estado | RF/NFR/ADR enlazados | Bloqueada por |
| --- | --- | --- | --- | --- |
| TASK-001 | Resolver con el propietario los pendientes bloqueantes y registrar las decisiones (ADRs nuevos donde aplique). Resueltos el 2026-06-12: PEND-001, PEND-002 (Omeka-S 4.2 + PHP 8.4), PEND-003, PEND-004; siguen abiertos PEND-005, PEND-006, PEND-007, PEND-008, PEND-009 | en curso | PEND-001…PEND-009, ADR-0001 | — (la resuelve el propietario) |
| TASK-002 | Scaffolding del módulo (FASE 1): estructura nativa Omeka-S (`Module.php`, `config/`, `src/`, `view/oer-manager/`, `asset/`), `module.ini` con `omeka_version_constraint = ^4.2.0`. Hecho el 2026-06-12 y verificado en el contenedor real (PHP 8.4.15, Omeka 4.2.0): lint OK, `getConfig()` OK, ini/constraint OK; instalación/activación en el admin verificada por el propietario | hecha | RF-001, NFR-001, NFR-002, NFR-007 | — |
| TASK-003 | Vista maestra: column types propios sobre el browse del admin + panel de detalle configurable (JS jQuery + REST API) | bloqueada | RF-001, RF-002, RF-003, NFR-003 | TASK-002, PEND-007 (columnas/panel) |
| TASK-004 | Re-catalogador curricular y por tags — **ALTO RIESGO**: diseño explícito previo (plan mode), preview + confirmación + reversibilidad, revisor en contexto fresco | bloqueada | RF-004, RF-005, NFR-003, NFR-004 | TASK-002, PEND-005, PEND-007 (reglas de negocio) |
| TASK-005 | Comprobación de integridad: servicio de validación de valores RDF + enganche en eventos | bloqueada | RF-006 | TASK-002, PEND-005, PEND-007 (reglas de integridad) |
| TASK-006 | Estadísticas: agregadores en `Stats/`, gráficos JS, export CSV/PDF | bloqueada | RF-007 | TASK-002, PEND-007 (catálogo de estadísticas) |
| TASK-007 | Auditoría de curación según la opción decidida en PEND-006 (o ADR de riesgo aceptado si opción D) | bloqueada | RF-008 | PEND-006 |
| TASK-008 | Completar el wiring de hooks en `.claude/settings.json`. Hecho el 2026-06-12: PostToolUse → `make lint` (aviso, solo `.php`) y **Stop hook activo** (`make lint` pasó en limpio tras `composer install` autorizado; probado con violación PSR-12 → exit 2). Resta: añadir `make test` al Stop hook cuando existan PHPUnit en `require-dev` (con PHP 8.4 ya decidido, puede elegirse versión al crear los tests) y `test/phpunit.xml` | en curso | NFR-001, ADR-0001 | `test/phpunit.xml` (fases posteriores) |
