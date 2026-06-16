# Memoria del proyecto — OERManager

> Estado vivo y contexto compartido para evitar decisiones implícitas en conflicto. Se actualiza al cerrar cada fase o decisión. Última actualización: 2026-06-16 (PEND-007 resuelto: punto 1 con ADR-0005, ya implementado en TASK-003; resto con ADR-0006 y `requirements.md`; **TASK-001 cerrada, no quedan PEND abiertos**; TASK-004/005/006 desbloqueadas).

## Estado actual

- **FASE 0 (gobierno del proceso): completada.** Registros versionables, Skills stub, hooks y guardrails creados.
- **Bloque §B recibido (2026-06-12):** nombre definitivo **OERManager** (namespace `OERManager`, vistas `view/oer-manager/`); entorno Omeka-S sobre Docker (`docker compose up -d`/`down`, código en host por volumen, comandos en host sin `docker compose exec`); lint `make lint` (PHPCS **PSR-12**), fix `make fix`, test `make test`; **sin análisis estático** (no PHPStan/Psalm). Resuelve PEND-001, PEND-003 y PEND-004.
- **Hooks:** PostToolUse ejecuta `make lint` como aviso tras editar `.php`, y el **Stop hook está activo** desde 2026-06-12: no se cierra turno si `make lint` falla (verificado con una violación PSR-12 → exit 2). `composer install` autorizado por el propietario ese día (phpcs 3.13.5, phpcbf, extract-tagged-strings vía repo VCS de GitHub). Hook de `make test` preparado pero inactivo (PHPUnit sin instalar — versión depende de PEND-002 — y `test/phpunit.xml` se crea en fases posteriores). Ver TASK-008.
- **PEND-002 resuelto (2026-06-12):** Omeka-S **4.2** + PHP **8.4** (runtime); `omeka_version_constraint = ^4.2.0`; `composer.json` con `php >= 8.4`. Verificado contra la doc oficial de Omeka (PHP 8.4 soportado desde Omeka S 4.2; 8.1 está EOL). El host usa PHP 8.5 solo para lint.
- **FASE 1 (scaffolding del módulo): ejecutada el 2026-06-12.** Estructura completa (`Module.php`, `config/module.ini` + `module.config.php`, controlador stub, `ConfigForm` vacío, vista de aterrizaje, carpetas de extensión con `.gitkeep`). Verificado contra el contenedor real (`omeka-s-moduletemplate-omekas-1`, montado en `modules/OERManager`): PHP 8.4.15, Omeka 4.2.0, `php -l` limpio, `getConfig()` carga, `module.ini` parsea y la constraint `^4.2.0` satisface 4.2.0. **Instalación y activación verificadas por el propietario en el admin (2026-06-12) — TASK-002 cerrada.** Patrones de ruta/navegación copiados de módulos reales instalados (Log, CSVImport): ruta hija de `admin`, navegación `AdminModule`, controllers por FQCN.
- **Nota de entorno:** el healthcheck del contenedor MariaDB marca *unhealthy* aunque el sitio responde HTTP 200; revisar el healthcheck (fuera del alcance del módulo).
- **Hallazgos de la instalación real (2026-06-12, relevantes para decisiones):** el módulo **Log** instalado es el de **Daniel Berthereau (Daniel-KM)** → la opción A/C de auditoría (PEND-006) era viable sin dependencia nueva, aunque PEND-006 se resolvió finalmente con **auditoría RDF nativa** (value annotations `dcterms`), no con el módulo Log (ADR-0002). El módulo **LearningObjectAdapter** es de la propia **ATE** (mismo ecosistema del propietario). Consulta directa a la BD del contenedor bloqueada por el clasificador de auto mode (acceso a credenciales del MariaDB compartido): para PEND-005 hay que confirmar vocabularios/properties por otra vía (panel admin o autorización explícita).
- **Preguntas de decisión abiertas redactadas en [docs/open-questions.md](open-questions.md)**; todas resueltas (PEND-001…009). **TASK-001 cerrada (2026-06-16)** — siguiente trabajo: TASK-003 (vista maestra), TASK-004 (re-catalogador, alto riesgo), TASK-005 (integridad) o TASK-006 (estadísticas), todas desbloqueadas y dependientes solo de TASK-002 (ya hecha).
- No existe todavía código del módulo ni instalación de Omeka vinculada a este repo.

## Decisiones ya tomadas (no reabrir sin motivo)

Tomadas por el propietario y documentadas en `docs/referencia/contexto-modulo-rea.md` §2; pendientes de formalizar como ADR cuando se toquen:

1. **Proceso single-agent** con Claude Code; sin agentes LLM en el runtime del módulo.
2. **Extender el core, no parchearlo** (vía `attachListeners` y mecanismos nativos). → NFR-001, guardrail en `.claude/settings.json`.
3. **Datos como RDF nativo, sin tablas Doctrine propias** (única excepción evaluable: auditoría, PEND-006). → NFR-002.
4. **Vista maestra híbrida**: columnas del browse del core + capa JS jQuery propia sobre la REST API. Ni SPA ni tabla desde cero.

Formalizadas:

- **ADR-0001** — registros de gobierno en texto plano versionable ([decisions/0001-registros-en-texto-plano.md](decisions/0001-registros-en-texto-plano.md)).
- **ADR-0002 (2026-06-15)** — estrategia RDF: vocabularios permitidos (solo `dcterms`/`lrmi`/`schema`) y auditoría de curación RDF nativa vía value annotations `dcterms`, sin módulo Log ni tablas; visibilidad fuera del alcance ([decisions/0002-estrategia-rdf-vocabularios-y-auditoria.md](decisions/0002-estrategia-rdf-vocabularios-y-auditoria.md)). Resuelve PEND-006; fija el vocabulario de PEND-005.
- **ADR-0003 (2026-06-15)** — empaquetado y licencia: paquete `ate/oer-manager`, licencia `GPL-3.0-or-later` ([decisions/0003-empaquetado-y-licencia.md](decisions/0003-empaquetado-y-licencia.md)). Resuelve PEND-009.
- **ADR-0004 (2026-06-15)** — mapeo RDF (confirmado contra la instalación real): etapa `lrmi:educationalLevel`, materia `schema:about`, saberes `lrmi:teaches`, criterios `lrmi:assesses`, eje temático/tags `dcterms:relation` (controlado por `schema:DefinedTermSet`), proyecto `schema:isPartOf` (acción de gestor), licencia `dcterms:rights` (CustomVocab) ([decisions/0004-mapeo-rdf-alineamiento-tags.md](decisions/0004-mapeo-rdf-alineamiento-tags.md)). Resuelve PEND-005.
- **ADR-0005 (2026-06-16)** — vista maestra v1 aprobada tal cual (columnas, filtros, drawer fijo, curación de visibilidad individual/lote) ([decisions/0005-vista-maestra-v1-aprobacion.md](decisions/0005-vista-maestra-v1-aprobacion.md)). Resuelve PEND-007 punto 1 para el alcance v1; implementada en TASK-003.
- **ADR-0006 (2026-06-16)** — raíz de los árboles RDF: ejes temáticos = un único `schema:DefinedTermSet` identificado por ID de item fijado en la config del módulo (`40260` en la instalación de referencia); alineamiento curricular = varios `schema:DefinedTermSet`, uno por marco/etapa, identificados por `lrmi:educationalFramework` (`LOMLOE` en la instalación de referencia), también configurable ([decisions/0006-raiz-arboles-rdf-config.md](decisions/0006-raiz-arboles-rdf-config.md)). Resuelve la parte de localización de vocabularios de PEND-007.

## Abierto (no inventar; preguntar al propietario)

No quedan PEND abiertos. Resueltos el 2026-06-12: PEND-001…PEND-004. Resueltos el 2026-06-15: **PEND-005** (mapeo RDF, ADR-0004), **PEND-006** (auditoría RDF nativa, ADR-0002), **PEND-008** (skills descartadas), **PEND-009** (paquete + licencia, ADR-0003). Resuelto el 2026-06-16: **PEND-007** (RF/NFR detallados — punto 1, columnas/panel de la vista maestra v1, ya implementado en TASK-003 vía ADR-0005; resto — cardinalidad y roles del re-catalogador, regla de integridad, catálogo de estadísticas, rendimiento, i18n, accesibilidad, localización de vocabularios — ver `requirements.md` y ADR-0006).

## Skills

| Skill | Estado | Cuándo usarla |
| --- | --- | --- |
| `omeka-module` | **destilada (2026-06-12)** desde el Omeka 4.2 real del contenedor; verificada, no inventada | Cualquier código del módulo: convenciones, eventos, ACL, columnas, REST API |
| `recatalogador` | stub de FASE 0 + invariantes de escritura RDF y auditoría (ADR-0002) destilados; reglas de negocio (cardinalidad, roles, raíces de árboles) ya fijadas en `requirements.md` y ADR-0006 — **pendiente de refinar la skill con esta información antes de TASK-004** | Todo lo que toque alineamiento curricular, tags o escritura RDF |

`rea-validacion-legal` y `spreadsheet-analyzer` se **descartaron** el 2026-06-15 (PEND-008): no forman parte del módulo.

## Limitación conocida del arnés de tests (TASK-008)

`test/phpunit.xml` arranca con `vendor/autoload.php` del propio módulo (sin el core de Omeka, ver TASK-008): cualquier clase que implemente una interfaz del core (p. ej. `Omeka\ColumnType\ColumnTypeInterface`) o tipe-hinte una clase del core en un parámetro que se invoque en el test (p. ej. `Omeka\Api\Manager`, `ItemRepresentation`) provoca un fatal de autoload en el host. Por eso `ColumnType\AlignmentStatus` y `Service\MasterViewQuery` (TASK-003) se verificaron manualmente en el contenedor real (igual que ya se hacía con el JS) en vez de con PHPUnit; `test/ModuleConfigTest.php` solo cubre el array de config porque es lo único cargable sin el core. Si se quiere cobertura automatizada de lógica que toca el core, hace falta un arnés de integración que corra dentro del contenedor (no existe todavía; afectará igual a TASK-004/005/006).

## Glosario

- **REA** — Recurso Educativo Abierto (OER): material educativo con licencia abierta.
- **LRMI** — Learning Resource Metadata Initiative; vocabulario RDF para describir recursos educativos (`lrmi:LearningResource`).
- **Alineamiento curricular** — vínculo RDF entre un recurso y los items-término del currículo (etapa, materia, criterio/competencia). El currículo ya existe en Omeka como items enlazados; el módulo lo consume, no lo posee.
- **Curación** — acciones de gestión sobre el catálogo: visibilidad, re-catalogación curricular, re-catalogación por tags, comprobación de integridad.
- **Re-catalogador** — componente de **alto riesgo** que reescribe el alineamiento curricular/tags de recursos (individual y en lote). Exige preview, confirmación y reversibilidad (TASK-004).
- **Vista maestra** — tabla de gestión en el panel admin desde la que se cura el catálogo (TASK-003).
- **Item-término** — item de Omeka (`DefinedTerm`/`DefinedTermSet` u homólogos) que representa un nodo del currículo.
