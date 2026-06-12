# Requisitos del módulo OERManager

> Fuente única de verdad de requisitos. IDs correlativos estables (`RF-NNN`, `NFR-NNN`, `PEND-NNN`): no se reutilizan ni se renumeran. Estados: `propuesto` / `aceptado` / `[PENDIENTE]`. Trazabilidad en [traceability.md](traceability.md).

## 1. Parámetros del entorno y decisiones bloqueantes

Los marcados `[PENDIENTE]` **no están decididos por el propietario**: no rellenar con conjeturas. Los marcados `Resuelto` (bloque §B del propietario, 2026-06-12) conservan su fila por trazabilidad.

| ID | Hueco | Valor actual | Bloquea |
| --- | --- | --- | --- |
| PEND-001 | Nombre CamelCase definitivo del módulo | **Resuelto (2026-06-12):** `OERManager`; namespace PHP `OERManager`; carpeta de vistas `view/oer-manager/`; raíz del proyecto `omeka-s-OERManager` | — (ya no bloquea) |
| PEND-002 | Versión exacta de Omeka-S y PHP del entorno + `omeka_version_constraint` | **Resuelto (2026-06-12):** Omeka-S **4.2**, PHP **8.4** (runtime/contenedor); `omeka_version_constraint = ^4.2.0`; `composer.json` declara `php >= 8.4`. El host usa PHP 8.5 solo para tooling de lint: no usar sintaxis 8.5 en el código del módulo | — (ya no bloquea; FASE 1 desbloqueada) |
| PEND-003 | Cómo se levanta Omeka en local | **Resuelto (2026-06-12):** Docker — `docker compose up -d` / `docker compose down`. El código vive en el host mapeado por volumen; lint/test se ejecutan **en el host, sin** `docker compose exec`. La configuración Docker **no forma parte del módulo** (no crearla ni editarla) | — (ya no bloquea) |
| PEND-004 | Comandos exactos de build/lint/test | **Resuelto (2026-06-12):** lint `make lint` (PHPCS **PSR-12**, no PSR2); fix `make fix` (phpcbf); test `make test` (PHPUnit con `test/phpunit.xml`, que aún no existe). **Sin análisis estático** (no introducir PHPStan/Psalm). Nota: Makefile del propietario en el repo (no regenerar); `composer.json` + `vendor/` instalados el 2026-06-12 con autorización del propietario; `make lint` pasa en limpio y el **Stop hook está activo**. `make test` diferido (PHPUnit sin instalar: su versión depende de PEND-002; `test/phpunit.xml` en fases posteriores) | — (solo queda el hook de test, ver TASK-008) |
| PEND-009 | Metadatos de `composer.json`: nombre de paquete (puesto provisionalmente `ate/oer-manager` por el agente) y licencia del módulo (campo `license` omitido a propósito) | `[PENDIENTE: confirmar vendor name y licencia]` | `make package` publicable y cabeceras de licencia del código |
| PEND-005 | Mapeo property RDF → tipo de alineamiento curricular y tags | `[PENDIENTE]` | TASK-003 (re-catalogador) y TASK-005 (integridad) |
| PEND-006 | Decisión de auditoría de curación (A: History Log / B: value annotations / C: log fichero / D: riesgo aceptado) | `[PENDIENTE]` | TASK-006 (auditoría) y diseño de reversibilidad del re-catalogador |
| PEND-007 | RF/NFR detallados (campos de gestión, cardinalidad de alineamiento, vocabulario de tags, acciones de curación y roles, reglas de integridad, columnas de la vista maestra, catálogo de estadísticas, matriz rol×acción, rendimiento, i18n, accesibilidad) | `[PENDIENTE]` | Aceptación de RF-001…RF-008 y NFR-001…NFR-006 |
| PEND-008 | Skills preexistentes `rea-validacion-legal` y `spreadsheet-analyzer`: no localizadas en el repo ni a nivel de usuario en FASE 0 | `[PENDIENTE: confirmar ubicación o restaurarlas]` | Validación legal de licencias y QA de exports |

## 2. Requisitos funcionales (RF)

| ID | Descripción | Prioridad | Estado | Criterio de aceptación |
| --- | --- | --- | --- | --- |
| RF-001 | El módulo gestiona items de clase `lrmi:LearningResource`: la vista maestra y todas las operaciones parten de ese filtro | Alta | propuesto | Listado en panel admin muestra solo items `lrmi:LearningResource`; consulta API por `resource_class` |
| RF-002 | Vista maestra en panel admin: tabla extendiendo el sistema de columnas/browse del core (≥4.0) con columnas propias y panel de detalle configurable | Alta | propuesto | Columnas exactas, filtros y panel: `[PENDIENTE]` (PEND-007) |
| RF-003 | Curación de visibilidad: cambiar público/privado usando el campo nativo del recurso (no flag propio), individual y por lotes | Alta | propuesto | Acciones, roles y flujo de confirmación: `[PENDIENTE]` (PEND-007) |
| RF-004 | Re-catalogación curricular: escribir valores RDF (resource values) apuntando a items-término del currículo existente, con previsualización, confirmación y reversibilidad | Alta | propuesto | Properties exactas: `[PENDIENTE]` (PEND-005); reglas de negocio (cardinalidad, obligatoriedad, roles): `[PENDIENTE]` (PEND-007) |
| RF-005 | Re-catalogación por etiquetas (tags) como valores RDF según vocabulario elegido | Media | propuesto | Vocabulario controlado vs. libre y property: `[PENDIENTE]` (PEND-005, PEND-007) |
| RF-006 | Comprobación de integridad: servicio que valida valores RDF (existencia, destino vivo, completitud) | Alta | propuesto | Reglas que definen «íntegro»: `[PENDIENTE]` (PEND-007) |
| RF-007 | Estadísticas visuales del catálogo (cursos/etapas, materias, proyectos, licencias) con exportación CSV/PDF | Media | propuesto | Catálogo exacto de gráficos, filtros y plantillas de export: `[PENDIENTE]` (PEND-007) |
| RF-008 | Auditoría de las acciones de curación (quién, qué, cuándo) | Media | `[PENDIENTE]` | Depende de PEND-006; si opción D, registrar riesgo aceptado en ADR |

## 3. Requisitos no funcionales (NFR)

| ID | Descripción | Prioridad | Estado | Criterio de aceptación |
| --- | --- | --- | --- | --- |
| NFR-001 | Extender el core, nunca parchearlo: integración solo vía `attachListeners`, `module.config.php` y mecanismos nativos | Alta | aceptado | `git diff` del core de Omeka vacío; guardrail PreToolUse activo en `.claude/settings.json` |
| NFR-002 | Sin tablas Doctrine propias (decisión del propietario); única excepción evaluable: auditoría (PEND-006) | Alta | aceptado | No existe `data/` con entidades ni migraciones propias, salvo ADR que documente la excepción |
| NFR-003 | Autorización por ACL nativa: privilegios propios vía `acl_resources`, `userIsAllowed()` en cada acción de curación | Alta | propuesto | Matriz rol×acción: `[PENDIENTE]` (PEND-007) |
| NFR-004 | Rendimiento: la selección curricular del re-catalogador no carga el árbol completo (lazy-load/búsqueda incremental) | Alta | propuesto | Objetivos numéricos (nº recursos, nº items-término, tiempos): `[PENDIENTE]` (PEND-007) |
| NFR-005 | i18n: cadenas traducibles vía Laminas; idiomas al menos es/en | Media | propuesto | Lista definitiva de idiomas: `[PENDIENTE]` (PEND-007) |
| NFR-006 | Accesibilidad: mantener el nivel del admin de Omeka 4.x | Media | propuesto | Nivel objetivo (p. ej. WCAG): `[PENDIENTE]` (PEND-007) |
| NFR-007 | Compatibilidad: mínimo Omeka-S 4.2 (`omeka_version_constraint = ^4.2.0`) y PHP 8.4 (uso interno; revisar si se publica al ecosistema) | Alta | aceptado | `config/module.ini` declara `^4.2.0`; `composer.json` declara `php >= 8.4`; el módulo instala y corre en Omeka-S 4.2 con PHP 8.4 |
