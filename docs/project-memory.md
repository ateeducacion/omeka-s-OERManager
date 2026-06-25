# Memoria del proyecto — OERManager

> Estado vivo y contexto compartido para evitar decisiones implícitas en conflicto. Se actualiza al cerrar cada fase o decisión. Última actualización: 2026-06-22 (TASK-004 en curso — diseño en plan mode; RF-009…RF-012 y ADR-0007/0008 formalizados; PEND-007 rectificado a resuelto completo en open-questions.md).

## Estado actual

- **FASE 0 (gobierno del proceso): completada.** Registros versionables, Skills destiladas, hooks PSR-12 + stop-hook activos, guardrails en `.claude/settings.json`.
- **FASE 1 (scaffolding): completada (TASK-002, 2026-06-12).** Estructura instalable verificada en el contenedor real (PHP 8.4.15, Omeka 4.2.0). Hooks de lint/test activos; 8 tests en verde.
- **Vista maestra v1: completada (TASK-003, 2026-06-16).** `IndexController`, `MasterViewQuery`, `ColumnType\AlignmentStatus`, vista + JS/CSS. Verificado en el contenedor contra 20 items `lrmi:LearningResource`. Alcance restante de RF-002/RF-003 (edición inline, columna de integridad en UI, historial) queda para iteraciones siguientes.
- **Integridad RDF: completada (TASK-005, 2026-06-18).** `IntegrityChecker` + `IntegrityResult`; hooks en `api.create.post`/`api.update.post` de `ItemAdapter`; issues al logger de Omeka. Sin bloqueo de guardado (pendiente de ADR).
- **Re-catalogador 4a (TASK-004): hecha (2026-06-25), verificada en el contenedor.** Manual: autocomplete curricular + tags con widget **Chosen** nativo, **acotación contextual en cascada** por el grafo (RF-014), preview+confirmar, auditoría `@annotation`, config form ADR-0006, ACL NFR-003. Modelo del grafo formalizado en **ADR-0009** (dimensión por `dcterms:type`; Etapa←Curso←Asignatura←{Saber, Criterio}; ver [referencia/curriculo-modelo-rdf.md](referencia/curriculo-modelo-rdf.md)). **Bug crítico resuelto:** el `update` con `isPartial` borraba los values no incluidos (recorrido plano en `ValueHydrator`); se usa el patrón canónico `clear_property_values` + `collectionAction=append` (gotcha en la skill `recatalogador`). Endurecimientos de la revisión en contexto fresco aplicados: validación de tipo/pertenencia del destino, CSRF en la escritura, sin fuga de mensajes de error. Residuos: confirmar acceso por rol en el contenedor; reversibilidad real → TASK-007.
- **Pendiente a continuación:** **4b — clasificador curricular y por etiquetas con LLM** (TASK-010, ALTO RIESGO/alta importancia; spec con contexto y config de ambos árboles en [superpowers/specs/2026-06-25-clasificador-ia-4b.md](superpowers/specs/2026-06-25-clasificador-ia-4b.md); NFR-008 accuracy>tokens; requiere `composer require smalot/pdfparser`); TASK-006 (estadísticas); TASK-007 (auditoría + reversibilidad real, ya **desbloqueada**); **TASK-014 (CI/CD en GitHub Actions, NFR-009) — en curso:** creados en `feature/gh_actions` los workflows `ci.yml`/`release.yml`/`dependency-review.yml`/`pr-playground-preview.yml` + `blueprint.json` raíz, patrón `ateeducacion/omeka-s-IsolatedSites`. Verificado en host (lint/test/i18n/`composer audit`/`make package` en verde). Seguridad: `composer audit` en vez de `dependency-review-action` (repo privado, sin GHAS); release quita el prefijo `v`. Falta el run real en un PR (requiere push autorizado) para pasar a **hecha**.
- **Nota de entorno:** healthcheck MariaDB marca *unhealthy* aunque el sitio responde HTTP 200 (fuera del alcance del módulo).

## Decisiones ya tomadas (no reabrir sin motivo)

Tomadas por el propietario y documentadas en `docs/referencia/contexto-modulo-rea.md` §2; pendientes de formalizar como ADR cuando se toquen:

1. **Proceso single-agent** con Claude Code para el desarrollo. ~~Sin agentes LLM en el runtime del módulo.~~ **Revisado (2026-06-22, ADR-0007):** el módulo **sí** invoca un LLM en runtime para la catalogación IA-assistida (RF-009/RF-010), de forma acotada y **siempre bajo confirmación humana** (la IA propone, el curador confirma). La conexión es configurable por proveedor (ADR-0008).
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
- **ADR-0007 (2026-06-22)** — catalogación curricular IA-assistida: la IA propone, el curador confirma; resolución por etiqueta→búsqueda incremental contra los DefinedTermSet (sin volcar el árbol al LLM, NFR-004); escritura idéntica a la manual. Revisa la decisión 1 (LLM en runtime, acotado y bajo confirmación) ([decisions/0007-catalogacion-ia-assistida.md](decisions/0007-catalogacion-ia-assistida.md)). Formaliza RF-009.
- **ADR-0008 (2026-06-22)** — conexión LLM configurable por proveedor (local/Anthropic/OpenAI-compatible) por adaptadores; HTTP con `Laminas\Http\Client` (sin dependencia nueva); clave API en settings nativos, nunca en repo/código/logs ([decisions/0008-conexion-llm-configurable.md](decisions/0008-conexion-llm-configurable.md)). Formaliza RF-010.
- **ADR-0009 (2026-06-24)** — modelo del grafo curricular LOMLOE (verificado contra el contenedor): clases `schema:DefinedTermSet` (etapas) y `schema:DefinedTerm` (nodos); dimensión por `dcterms:type`; jerarquía Etapa←Curso←Asignatura←{Saber, Criterio} con aristas y enlaces denormalizados; mapeo REA→nodo (educationalLevel=Curso) y acotación contextual ([decisions/0009-grafo-curricular-lomloe.md](decisions/0009-grafo-curricular-lomloe.md)). Formaliza RF-014; detalle en [referencia/curriculo-modelo-rdf.md](referencia/curriculo-modelo-rdf.md).

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
