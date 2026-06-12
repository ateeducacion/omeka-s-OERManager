---
name: omeka-module
description: USAR SIEMPRE que se escriba, revise o planifique código del módulo Omeka-S (Module.php, module.config.php, controladores, column types, servicios, formularios, vistas .phtml, JS de admin, ACL, eventos, REST API). Si la tarea toca PHP o plantillas de este repo, esta skill aplica — no la omitas por parecer un cambio pequeño.
---

# Convenciones de módulo Omeka-S

## Propósito

Concentrar las convenciones verificadas de desarrollo de módulos Omeka-S (estructura, eventos, ACL, sistema de columnas del browse, REST API) para que el código del módulo sea nativo y no improvise APIs.

## Cuándo dispararse

- Crear o editar cualquier fichero PHP, `.phtml` o JS del módulo.
- Diseñar rutas admin, navegación, `acl_resources`, column types o servicios.
- Dudas sobre «cómo se hace X en Omeka-S» durante la implementación.

## Reglas ya fijadas (no esperar al destilado)

- Extender el core vía `attachListeners`; **nunca** parchear ficheros del core.
- **Sin tablas Doctrine propias** (NFR-002); datos como valores RDF + settings nativos.
- Carpeta/namespace CamelCase coincidentes; `view/` en hyphen-case.
- Visibilidad: campo nativo público/privado del recurso.

## Contenido

Entorno fijado (2026-06-12): **Omeka-S 4.2, PHP 8.4**, `omeka_version_constraint = ^4.2.0`. No usar sintaxis de PHP 8.5.

`[PENDIENTE: destilar de la documentación oficial de Omeka-S 4.2 — anatomía del módulo, getConfig()/install/upgrade, eventos del servidor, column_types/ColumnTypeManager (core ≥4.0), patrón batch_update, acl_resources y userIsAllowed(), REST API /api, getConfigForm()/handleConfigForm(). No inventar nombres de APIs ni de properties hasta entonces.]`
