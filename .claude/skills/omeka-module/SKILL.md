---
name: omeka-module
description: USAR SIEMPRE que se escriba, revise o planifique código del módulo Omeka-S (Module.php, module.config.php, controladores, column types, servicios, formularios, vistas .phtml, JS de admin, ACL, eventos, REST API). Si la tarea toca PHP o plantillas de este repo, esta skill aplica — no la omitas por parecer un cambio pequeño.
---

# Convenciones de módulo Omeka-S 4.2

Entorno: **Omeka-S 4.2, PHP 8.4**, `omeka_version_constraint = ^4.2.0`. No usar sintaxis de PHP 8.5. Todo lo de abajo está verificado contra el core 4.2 y módulos instalados del contenedor (`application/src/...`, `modules/Log`, `Access`, `Guest`), no inventado.

## Reglas no negociables

- **Extender el core, NUNCA parchearlo**: integración solo vía `attachListeners`, `module.config.php`, `onBootstrap` y servicios. Un hook PreToolUse bloquea escrituras fuera del repo.
- **Sin tablas Doctrine propias** (NFR-002): datos como valores RDF + settings nativos. Única excepción evaluable: auditoría (PEND-006), exigiría ADR.
- Carpeta/namespace **CamelCase** coincidentes (`OERManager`); `view/oer-manager/` en hyphen-case.
- Visibilidad: campo nativo público/privado del recurso, no flag propio.
- Cadenas de UI traducibles: `$this->translate('...')` en vistas, `// @translate` junto a literales en config/PHP.

## Anatomía del módulo

```
OERManager/
  Module.php            extends Omeka\Module\AbstractModule
  config/
    module.ini          [info]: name, version (obligatorios); configurable,
                        omeka_version_constraint, author, description (opcionales)
    module.config.php   devuelve array: controllers, router, navigation,
                        view_manager, form_elements, column_types, translator
  src/                  PSR-4 OERManager\ → src/ (Controller/Admin, ColumnType,
                        Service, Stats, Form, View/Helper)
  view/oer-manager/     plantillas .phtml (hyphen-case)
  asset/{js,css}/
  language/             *.mo (gettext)
```

## Module.php — firmas reales de AbstractModule

```php
public function getConfig()                       // return include __DIR__.'/config/module.config.php';
public function onBootstrap(MvcEvent $e)          // llamar parent::onBootstrap($e); aquí se registran reglas ACL
public function install(ServiceLocatorInterface $services)
public function uninstall(ServiceLocatorInterface $services)
public function upgrade($oldVersion, $newVersion, ServiceLocatorInterface $services)
public function attachListeners(SharedEventManagerInterface $sharedEventManager)
public function getConfigForm(PhpRenderer $renderer)        // si configurable=true
public function handleConfigForm(AbstractController $controller)  // return bool
```

`getServiceLocator()` da el contenedor de servicios dentro de Module.

## Eventos del servidor (los más usados, verificados)

Se enganchan en `attachListeners()` con `$sharedEventManager->attach($identifier, $eventName, $callback)`. Identificadores típicos: `'Omeka\Api\Adapter\ItemAdapter'` (eventos api.*), `'Omeka\Controller\Admin\Item'` (eventos view.*).

- **API (servidor):** `api.create.pre/post`, `api.update.pre/post`, `api.delete.post`, `api.hydrate.pre/post`, `api.search.query`, `api.batch_update.pre/post`. Para integridad y curación, `api.hydrate.post` y `api.batch_update.*` son la vía.
- **Vista admin:** `view.show.after`, `view.show.sidebar`, `view.details`, `view.browse.after`, `view.layout`.
- **Representación JSON:** `rep.resource.json` (añadir datos al JSON de la REST API), `rep.resource.display_values`.

## Rutas admin + navegación (patrón verificado en Log)

Ruta como hija del router `admin`; navegación en grupo `AdminModule` (o `AdminGlobal`). El `resource` de la navegación = FQCN del controlador → es la clave ACL que decide si el usuario ve la entrada.

```php
'router' => ['routes' => ['admin' => ['child_routes' => [
    'oer-manager' => [
        'type' => \Laminas\Router\Http\Literal::class,
        'options' => ['route' => '/oer-manager', 'defaults' => [
            '__NAMESPACE__' => 'OERManager\Controller\Admin',
            'controller' => Controller\Admin\IndexController::class,
            'action' => 'index',
        ]],
        'may_terminate' => true,
        // 'child_routes' => [ ... '/:action', '/:id[/:action]' ... ] para acciones/ID
    ],
]]]],
'navigation' => ['AdminModule' => [[
    'label' => 'OER Manager', // @translate
    'route' => 'admin/oer-manager',
    'resource' => Controller\Admin\IndexController::class,
]]],
```

## ACL (Omeka\Permissions\Acl) — patrón verificado en Guest

No se conceden privilegios desde el array de config; se registran **programáticamente** en `onBootstrap` (o método propio llamado desde ahí):

```php
public function onBootstrap(MvcEvent $event): void
{
    parent::onBootstrap($event);
    /** @var \Omeka\Permissions\Acl $acl */
    $acl = $this->getServiceLocator()->get('Omeka\Acl');
    $acl->allow(/* roles */ null, /* resources */ [Controller\Admin\FooController::class], /* privileges */ ['index']);
}
```

`null` en roles = todos; pasar un array de roles para restringir. En controladores/servicios, comprobar con `$this->acl->userIsAllowed($resource, $privilege)`. **FASE 1 no concede privilegios**: la matriz rol×acción es PEND-007.

## Column types para el browse (core ≥4.0) — verificado en Access

Registro en config:

```php
'column_types' => ['invokables' => [
    'oerVisibility' => ColumnType\Visibility::class,
]],
```

La clase implementa `Omeka\ColumnType\ColumnTypeInterface`:

```php
public function getLabel(): string;
public function getResourceTypes(): array;        // p.ej. ['items']
public function getMaxColumns(): ?int;
public function renderDataForm(PhpRenderer $view, array $data): string;
public function getSortBy(array $data): ?string;
public function renderHeader(PhpRenderer $view, array $data): string;
public function renderContent(PhpRenderer $view, AbstractEntityRepresentation $resource, array $data): ?string;
```

## REST API / PHP API interna

`$api = $services->get('Omeka\ApiManager');` luego:
- `$api->search('items', ['resource_class_id' => N, ...])` → filtrar el catálogo a `lrmi:LearningResource`.
- `$api->read('items', $id)`, `$api->create(...)`, `$api->update('items', $id, $data, [], ['isPartial' => true])`.
- **`$api->batchUpdate(...)`** para curación en lote (re-catalogador, TASK-004).
- REST externa en `/api`. Los valores RDF se escriben como resource values; el destino de alineamiento es un item-término (resource value, no literal) — ver skill `recatalogador` y PEND-005.

## Pendientes que afectan al código

- PEND-005: mapeo exacto property RDF → alineamiento/tags (bloquea re-catalogador e integridad).
- PEND-006: auditoría (A/B/C/D).
- PEND-007: columnas de la vista maestra, reglas del re-catalogador, matriz rol×acción.
