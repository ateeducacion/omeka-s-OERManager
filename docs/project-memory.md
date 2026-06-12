# Memoria del proyecto — OERManager

> Estado vivo y contexto compartido para evitar decisiones implícitas en conflicto. Se actualiza al cerrar cada fase o decisión. Última actualización: 2026-06-12 (FASE 0 + bloque §B del propietario).

## Estado actual

- **FASE 0 (gobierno del proceso): completada.** Registros versionables, Skills stub, hooks y guardrails creados.
- **Bloque §B recibido (2026-06-12):** nombre definitivo **OERManager** (namespace `OERManager`, vistas `view/oer-manager/`); entorno Omeka-S sobre Docker (`docker compose up -d`/`down`, código en host por volumen, comandos en host sin `docker compose exec`); lint `make lint` (PHPCS **PSR-12**), fix `make fix`, test `make test`; **sin análisis estático** (no PHPStan/Psalm). Resuelve PEND-001, PEND-003 y PEND-004.
- **Hooks:** PostToolUse ejecuta `make lint` como aviso tras editar `.php`, y el **Stop hook está activo** desde 2026-06-12: no se cierra turno si `make lint` falla (verificado con una violación PSR-12 → exit 2). `composer install` autorizado por el propietario ese día (phpcs 3.13.5, phpcbf, extract-tagged-strings vía repo VCS de GitHub). Hook de `make test` preparado pero inactivo (PHPUnit sin instalar — versión depende de PEND-002 — y `test/phpunit.xml` se crea en fases posteriores). Ver TASK-008.
- **PEND-002 resuelto (2026-06-12):** Omeka-S **4.2** + PHP **8.4** (runtime); `omeka_version_constraint = ^4.2.0`; `composer.json` con `php >= 8.4`. Verificado contra la doc oficial de Omeka (PHP 8.4 soportado desde Omeka S 4.2; 8.1 está EOL). El host usa PHP 8.5 solo para lint.
- **FASE 1 (scaffolding del módulo): ejecutada el 2026-06-12.** Estructura completa (`Module.php`, `config/module.ini` + `module.config.php`, controlador stub, `ConfigForm` vacío, vista de aterrizaje, carpetas de extensión con `.gitkeep`). Verificado contra el contenedor real (`omeka-s-moduletemplate-omekas-1`, montado en `modules/OERManager`): PHP 8.4.15, Omeka 4.2.0, `php -l` limpio, `getConfig()` carga, `module.ini` parsea y la constraint `^4.2.0` satisface 4.2.0. **Instalación y activación verificadas por el propietario en el admin (2026-06-12) — TASK-002 cerrada.** Patrones de ruta/navegación copiados de módulos reales instalados (Log, CSVImport): ruta hija de `admin`, navegación `AdminModule`, controllers por FQCN.
- **Nota de entorno:** el healthcheck del contenedor MariaDB marca *unhealthy* aunque el sitio responde HTTP 200; revisar el healthcheck (fuera del alcance del módulo).
- No existe todavía código del módulo ni instalación de Omeka vinculada a este repo.

## Decisiones ya tomadas (no reabrir sin motivo)

Tomadas por el propietario y documentadas en `docs/referencia/contexto-modulo-rea.md` §2; pendientes de formalizar como ADR cuando se toquen:

1. **Proceso single-agent** con Claude Code; sin agentes LLM en el runtime del módulo.
2. **Extender el core, no parchearlo** (vía `attachListeners` y mecanismos nativos). → NFR-001, guardrail en `.claude/settings.json`.
3. **Datos como RDF nativo, sin tablas Doctrine propias** (única excepción evaluable: auditoría, PEND-006). → NFR-002.
4. **Vista maestra híbrida**: columnas del browse del core + capa JS jQuery propia sobre la REST API. Ni SPA ni tabla desde cero.

Formalizada en esta fase:

- **ADR-0001** — registros de gobierno en texto plano versionable ([decisions/0001-registros-en-texto-plano.md](decisions/0001-registros-en-texto-plano.md)).

## Abierto (no inventar; preguntar al propietario)

Lista completa con IDs en [requirements.md §1](requirements.md): PEND-005 (mapeo properties RDF → bloquea re-catalogador), PEND-006 (auditoría A/B/C/D), PEND-007 (RF/NFR detallados), PEND-008 (Skills preexistentes no localizadas), PEND-009 (nombre de paquete y licencia en `composer.json`). Resueltos el 2026-06-12: PEND-001, PEND-002, PEND-003, PEND-004.

**Nuevo desde la instalación de dependencias (2026-06-12):** PEND-009 — confirmar el nombre de paquete de `composer.json` (provisional `ate/oer-manager`, puesto por el agente) y la licencia del módulo (campo `license` omitido a propósito hasta que el propietario decida).

## Skills

| Skill | Estado | Cuándo usarla |
| --- | --- | --- |
| `omeka-module` | stub creado en FASE 0 (`.claude/skills/omeka-module/`) | Cualquier código del módulo: convenciones, eventos, ACL, columnas, REST API |
| `recatalogador` | stub creado en FASE 0 (`.claude/skills/recatalogador/`) | Todo lo que toque alineamiento curricular, tags o escritura RDF |
| `rea-validacion-legal` | **preexistente según el contexto, NO localizada en el repo ni en `~/.claude/skills/` (PEND-008)** | Licencias, copyright, validación REA |
| `spreadsheet-analyzer` | **preexistente según el contexto, NO localizada (PEND-008)** | QA de exports y datos tabulares |

## Glosario

- **REA** — Recurso Educativo Abierto (OER): material educativo con licencia abierta.
- **LRMI** — Learning Resource Metadata Initiative; vocabulario RDF para describir recursos educativos (`lrmi:LearningResource`).
- **Alineamiento curricular** — vínculo RDF entre un recurso y los items-término del currículo (etapa, materia, criterio/competencia). El currículo ya existe en Omeka como items enlazados; el módulo lo consume, no lo posee.
- **Curación** — acciones de gestión sobre el catálogo: visibilidad, re-catalogación curricular, re-catalogación por tags, comprobación de integridad.
- **Re-catalogador** — componente de **alto riesgo** que reescribe el alineamiento curricular/tags de recursos (individual y en lote). Exige preview, confirmación y reversibilidad (TASK-004).
- **Vista maestra** — tabla de gestión en el panel admin desde la que se cura el catálogo (TASK-003).
- **Item-término** — item de Omeka (`DefinedTerm`/`DefinedTermSet` u homólogos) que representa un nodo del currículo.
