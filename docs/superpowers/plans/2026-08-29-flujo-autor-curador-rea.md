# Flujo autor→curador de REAs — Implementation Plan (RF-016)

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking. **Cargar la skill `recatalogador` antes de tocar código de este plan**: escribe valores RDF sobre items, aunque no sea alineamiento curricular.

**Goal:** Un autor (`author`) propone un REA como candidato; un curador (`reviewer`, añadido junto a `editor`/`site_admin`) lo revisa, lo cataloga si procede con las herramientas ya existentes, y lo publica — gateado por `IntegrityChecker`.

**Architecture:** Estado de flujo en el literal `curation:status` (+ `curation:note` para el motivo de rechazo), sin `CustomVocab`. Una clase pura (`WorkflowStatus`) para la máquina de estados, una clase que toca el core (`WorkflowService`) para leer/escribir, tres acciones nuevas en `IndexController` reusando su `Csrf` ya existente, un botón inyectado en la página nativa del item (`view.show.page_actions`, sin tocar el core), y un filtro nuevo en la vista maestra ya existente.

**Tech Stack:** PHP 8.4 / Omeka-S 4.2 (roles nativos `author`/`reviewer`, vocabulario `Curation` ya instalado), PHPUnit, JS vanilla existente del módulo.

**Spec:** `docs/superpowers/specs/2026-08-28-flujo-autor-curador-rea-design.md`
**ADR:** `docs/decisions/0018-flujo-autor-curador-rea.md`

## Global Constraints

- PHP 8.4, sin sintaxis de 8.5; PSR-12 (`make lint` en verde antes de cada commit).
- Sin tablas Doctrine propias (NFR-002) — todo vive como valores RDF.
- **Roles nativos, no propios:** autor = `author`, curador = `reviewer`, añadido **junto a** `editor`/`site_admin` (no los sustituye).
- **`curation:status`/`curation:note` son literales simples** (`type=literal`), **no** `CustomVocab` — el valor lo compara la máquina de estados por igualdad exacta (ADR-0018 §2).
- Valores: `WorkflowStatus::PROPOSED = 'Propuesto'`, `::REJECTED = 'Rechazado'`. Ausencia de valor = borrador (no se escribe nada al crear un item normal).
- Publicado **no** es un 4º valor: al publicar, `is_public=true` y `curation:status` se **elimina** (payload vacío para esa property).
- Gate de publicación: `IntegrityChecker::check($item, true)->isOk()` — `checkLinks=true` (acción de un solo item, mismo criterio que el drawer, no el browse). Debe ser `true`; ni `error` ni `warning` bastan.
- "Proponer" no crea un privilegio ACL propio para decidir *quién*: se apoya en el permiso nativo de edición del item (`$item->userIsAllowed('update')`). Los privilegios ACL nuevos son solo para las acciones del controlador.
- Resolver properties **por término**, nunca por `property_id` hardcodeado (patrón ya establecido, `RecatalogService::propertyId()`).
- Fuera de alcance: notificaciones, límite de reintentos, propuesta de alineamiento por el autor, botón de "publicar de todos modos", tocar PEND-012.

---

### Task 1: `Workflow\WorkflowStatus` (puro)

**Files:**
- Create: `src/Service/Workflow/WorkflowStatus.php`
- Test: `test/Service/Workflow/WorkflowStatusTest.php`

**Interfaces:**
- Produces: `WorkflowStatus::STATUS_TERM`/`::NOTE_TERM` (string, términos RDF), `::PROPOSED`/`::REJECTED` (string, valores), `WorkflowStatus::canPropose(?string $status): bool`, `::canReject(?string $status): bool`, `::canPublish(?string $status): bool` — usados por `WorkflowService` (Task 2) y por `IndexController` (Task 3) para decidir qué acción es válida antes de escribir.

- [ ] **Step 1: Write the failing test**

```php
<?php

declare(strict_types=1);

namespace OERManager\Test\Service\Workflow;

use OERManager\Service\Workflow\WorkflowStatus;
use PHPUnit\Framework\TestCase;

/**
 * Máquina de estados del flujo autor→curador (RF-016, ADR-0018). Puro: solo
 * compara el literal de `curation:status`, nunca toca el core.
 */
final class WorkflowStatusTest extends TestCase
{
    public function testConstantesDeTermino(): void
    {
        $this->assertSame('curation:status', WorkflowStatus::STATUS_TERM);
        $this->assertSame('curation:note', WorkflowStatus::NOTE_TERM);
        $this->assertSame('Propuesto', WorkflowStatus::PROPOSED);
        $this->assertSame('Rechazado', WorkflowStatus::REJECTED);
    }

    public function testProponerDesdeBorradorOAusente(): void
    {
        $this->assertTrue(WorkflowStatus::canPropose(null));
    }

    public function testProponerDesdeRechazado(): void
    {
        $this->assertTrue(WorkflowStatus::canPropose(WorkflowStatus::REJECTED));
    }

    public function testNoSePuedeProponerDosVeces(): void
    {
        $this->assertFalse(WorkflowStatus::canPropose(WorkflowStatus::PROPOSED));
    }

    public function testNoSePuedeProponerUnValorDesconocido(): void
    {
        $this->assertFalse(WorkflowStatus::canPropose('cualquier-otra-cosa'));
    }

    public function testSoloSePuedeRechazarUnPropuesto(): void
    {
        $this->assertTrue(WorkflowStatus::canReject(WorkflowStatus::PROPOSED));
        $this->assertFalse(WorkflowStatus::canReject(null));
        $this->assertFalse(WorkflowStatus::canReject(WorkflowStatus::REJECTED));
    }

    public function testSoloSePuedePublicarUnPropuesto(): void
    {
        $this->assertTrue(WorkflowStatus::canPublish(WorkflowStatus::PROPOSED));
        $this->assertFalse(WorkflowStatus::canPublish(null));
        $this->assertFalse(WorkflowStatus::canPublish(WorkflowStatus::REJECTED));
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `vendor/bin/phpunit -c test/phpunit.xml --filter WorkflowStatusTest`
Expected: FAIL — `Class "OERManager\Service\Workflow\WorkflowStatus" not found`

- [ ] **Step 3: Write minimal implementation**

```php
<?php

declare(strict_types=1);

namespace OERManager\Service\Workflow;

/**
 * Máquina de estados del flujo autor→curador de REAs (RF-016, ADR-0018).
 * Puro: compara el literal de `curation:status` por igualdad exacta, nunca
 * toca el core. `Borrador` es la AUSENCIA de valor (null) — no hay una
 * constante para él, y "publicado" no es un 4º valor: al publicar,
 * `curation:status` se elimina (ver WorkflowService::publish()).
 */
final class WorkflowStatus
{
    public const STATUS_TERM = 'curation:status';
    public const NOTE_TERM = 'curation:note';

    public const PROPOSED = 'Propuesto';
    public const REJECTED = 'Rechazado';

    /** Borrador (ausente) o ya rechazado: el autor puede (re)proponer. */
    public static function canPropose(?string $status): bool
    {
        return null === $status || self::REJECTED === $status;
    }

    /** Solo un propuesto puede rechazarse. */
    public static function canReject(?string $status): bool
    {
        return self::PROPOSED === $status;
    }

    /** Solo un propuesto puede publicarse por este flujo. */
    public static function canPublish(?string $status): bool
    {
        return self::PROPOSED === $status;
    }
}
```

- [ ] **Step 4: Run test to verify it passes**

Run: `vendor/bin/phpunit -c test/phpunit.xml --filter WorkflowStatusTest`
Expected: PASS — 7 tests

- [ ] **Step 5: Commit**

```bash
git add src/Service/Workflow/WorkflowStatus.php test/Service/Workflow/WorkflowStatusTest.php
git commit -m "feat(workflow): máquina de estados pura del flujo autor→curador (RF-016)"
```

---

### Task 2: `Workflow\WorkflowService` (toca el core — sin test de host)

**Files:**
- Create: `src/Service/Workflow/WorkflowService.php`
- Modify: `config/module.config.php` (factory del servicio)

**Interfaces:**
- Consumes: `WorkflowStatus::*` (Task 1); `Omeka\Api\Manager` (nativo).
- Produces: `WorkflowService::statusOf(ItemRepresentation $item): ?string`, `::propose(ItemRepresentation $item): array{updated:bool, status?:string, error?:string}`, `::reject(ItemRepresentation $item, string $reason): array{...}`, `::publish(ItemRepresentation $item): array{...}` — usados por `IndexController` (Task 3) y por el listener de botón (Task 5).

**Nota de testing:** toca `ItemRepresentation`/`Omeka\Api\Manager`, así que no hay test de host posible (misma limitación que `RecatalogService`/`DimensionFacts`, ya documentada en `project-memory.md`). Se verifica en el arnés de contenedor (Task 8).

- [ ] **Step 1: Escribir la clase directamente (sin test de host)**

```php
<?php

declare(strict_types=1);

namespace OERManager\Service\Workflow;

use Omeka\Api\Manager as ApiManager;
use Omeka\Api\Representation\ItemRepresentation;

/**
 * Lee y escribe el estado del flujo autor→curador (RF-016, ADR-0018) sobre un
 * item. Resuelve properties por término, nunca por id hardcodeado — mismo
 * patrón que `RecatalogService::propertyId()`.
 */
final class WorkflowService
{
    private ApiManager $api;

    /** @var array<string, int|null> */
    private array $propertyIds = [];

    public function __construct(ApiManager $api)
    {
        $this->api = $api;
    }

    public function statusOf(ItemRepresentation $item): ?string
    {
        $value = $item->value(WorkflowStatus::STATUS_TERM);
        if (null === $value) {
            return null;
        }
        $status = trim((string) $value->value());
        return '' === $status ? null : $status;
    }

    /**
     * @return array{updated:bool, status?:string, error?:string}
     */
    public function propose(ItemRepresentation $item): array
    {
        if (!WorkflowStatus::canPropose($this->statusOf($item))) {
            return ['updated' => false, 'error' => 'invalid_transition'];
        }
        // '' (no null): SIEMPRE limpia curation:note, para que el motivo de
        // un rechazo previo no sobreviva a una nueva propuesta (spec §4).
        return $this->writeStatus((int) $item->id(), WorkflowStatus::PROPOSED, '');
    }

    /** @return array{updated:bool, status?:string, error?:string} */
    public function reject(ItemRepresentation $item, string $reason): array
    {
        if (!WorkflowStatus::canReject($this->statusOf($item))) {
            return ['updated' => false, 'error' => 'invalid_transition'];
        }
        return $this->writeStatus((int) $item->id(), WorkflowStatus::REJECTED, trim($reason));
    }

    /**
     * ⚠️ TRAMPA CRÍTICA (skill `recatalogador`, incidente 2026-06-25):
     * `$api->update(..., ['isPartial' => true])` con **valores** de propiedades
     * NO es por-propiedad — `ValueHydrator` recorre la colección PLANA de
     * TODOS los valores del item y borra los no reutilizados. Pasar solo
     * `curation:status`/`curation:note` en `$data` sin más borraría título,
     * descripción, alineamiento curricular y todo lo demás. Patrón correcto
     * (el mismo que ya usa `RecatalogService`): limpiar SOLO las properties
     * que se tocan vía `clear_property_values` y anexar con
     * `'collectionAction' => 'append'`, para que Omeka no reutilice/borre el
     * resto de la colección.
     *
     * @return array{updated:bool, status?:string, error?:string}
     */
    public function publish(ItemRepresentation $item): array
    {
        if (!WorkflowStatus::canPublish($this->statusOf($item))) {
            return ['updated' => false, 'error' => 'invalid_transition'];
        }
        $itemId = (int) $item->id();
        $statusPropertyId = $this->propertyId(WorkflowStatus::STATUS_TERM);
        if (null === $statusPropertyId) {
            // Sin la property no hay dónde limpiar el estado: no dejar el
            // item a medias (mismo criterio que RecatalogService::eventValue()).
            return ['updated' => false, 'error' => 'missing_property'];
        }
        $clear = [$statusPropertyId];
        $data = [
            'o:is_public' => true,
            WorkflowStatus::STATUS_TERM => [],
        ];
        $notePropertyId = $this->propertyId(WorkflowStatus::NOTE_TERM);
        if (null !== $notePropertyId) {
            $clear[] = $notePropertyId;
            $data[WorkflowStatus::NOTE_TERM] = [];
        }
        $data['clear_property_values'] = $clear;
        $this->api->update('items', $itemId, $data, [], ['isPartial' => true, 'collectionAction' => 'append']);
        return ['updated' => true];
    }

    /**
     * Mismo patrón `clear_property_values` + `collectionAction=append` que
     * `publish()` — ver el aviso de arriba. Sin esto, cada propose/reject
     * borraría el resto de las properties del item.
     *
     * @param string|null $note null = no tocar `curation:note` (no usado hoy:
     *   propose() SIEMPRE pasa '' para limpiar un motivo de un rechazo
     *   previo); '' = limpiarla.
     */
    private function writeStatus(int $itemId, string $status, ?string $note): array
    {
        $statusPropertyId = $this->propertyId(WorkflowStatus::STATUS_TERM);
        if (null === $statusPropertyId) {
            return ['updated' => false, 'error' => 'missing_property'];
        }
        $clear = [$statusPropertyId];
        $data = [
            WorkflowStatus::STATUS_TERM => [$this->literal($statusPropertyId, $status)],
        ];
        if (null !== $note) {
            $notePropertyId = $this->propertyId(WorkflowStatus::NOTE_TERM);
            if (null !== $notePropertyId) {
                $clear[] = $notePropertyId;
                $data[WorkflowStatus::NOTE_TERM] = '' === $note ? [] : [$this->literal($notePropertyId, $note)];
            }
        }
        $data['clear_property_values'] = $clear;
        $this->api->update('items', $itemId, $data, [], ['isPartial' => true, 'collectionAction' => 'append']);
        return ['updated' => true, 'status' => $status];
    }

    /** @return array{type:string, property_id:int, '@value':string} */
    private function literal(int $propertyId, string $value): array
    {
        return [
            'type' => 'literal',
            'property_id' => $propertyId,
            '@value' => $value,
        ];
    }

    private function propertyId(string $term): ?int
    {
        if (array_key_exists($term, $this->propertyIds)) {
            return $this->propertyIds[$term];
        }
        $content = $this->api->search('properties', ['term' => $term])->getContent();
        return $this->propertyIds[$term] = $content ? $content[0]->id() : null;
    }
}
```

- [ ] **Step 2: Registrar el servicio en `config/module.config.php`**

Dentro de `'service_manager' => ['factories' => [` (junto a `Service\RecatalogService::class`):

```php
Service\Workflow\WorkflowService::class => function ($container) {
    return new Service\Workflow\WorkflowService($container->get('Omeka\ApiManager'));
},
```

- [ ] **Step 3: `make lint`**

Run: `make lint`
Expected: sin violaciones PSR-12

- [ ] **Step 4: Commit**

```bash
git add src/Service/Workflow/WorkflowService.php config/module.config.php
git commit -m "feat(workflow): WorkflowService — lee/escribe curation:status y curation:note (RF-016)"
```

---

### Task 3: Acciones nuevas en `IndexController`

**Files:**
- Modify: `src/Controller/Admin/IndexController.php`
- Modify: `config/module.config.php` (factory de `IndexController`)

**Interfaces:**
- Consumes: `WorkflowService::propose()`/`::reject()`/`::publish()` (Task 2); `IntegrityChecker::check(ItemRepresentation $item, bool $checkLinks): IntegrityResult` (existente); `IntegrityResult::isOk(): bool`/`::getIssues(): array` (existente); `$this->csrfValidator()` (existente, `IndexController::CSRF_NAME`/`CSRF_SALT`).
- Produces: acciones `proposeAction`, `rejectProposalAction`, `publishProposalAction` en la ruta ya existente `/oer-manager/:action` — sin ruta nueva. Usadas por el partial del Task 5 (URLs `admin/oer-manager` con `action=propose|reject-proposal|publish-proposal`).

- [ ] **Step 1: Añadir la dependencia al constructor**

En la lista de `use`, añade:

```php
use OERManager\Service\Workflow\WorkflowService;
```

En las propiedades privadas (junto a `private ItemPanelData $itemPanelData;`):

```php
    private WorkflowService $workflowService;
```

En la firma del constructor, añade el parámetro **al final** de la lista existente (después de `ItemPanelData $itemPanelData`):

```php
        ItemPanelData $itemPanelData,
        WorkflowService $workflowService
    ) {
```

Y en el cuerpo del constructor, junto a `$this->itemPanelData = $itemPanelData;`:

```php
        $this->workflowService = $workflowService;
```

- [ ] **Step 2: Añadir las tres acciones**

Añade al final de la clase, antes de la última llave de cierre:

```php
    /**
     * Un autor propone un REA (o lo re-propone tras un rechazo) para revisión
     * (RF-016). No hay privilegio ACL propio para decidir quién: se apoya en
     * el permiso nativo de edición del item — `$api->update()` deniega por sí
     * mismo a quien no pueda editarlo (autor sobre lo suyo, curador+ sobre
     * cualquiera), igual que ya hace `setVisibilityAction`.
     */
    public function proposeAction()
    {
        if (!$this->getRequest()->isPost()) {
            return $this->redirect()->toRoute('admin/oer-manager');
        }
        if (!$this->csrfValidator()->isValid((string) $this->params()->fromPost('csrf'))) {
            return new JsonModel(['updated' => false, 'error' => 'csrf']);
        }
        $id = (int) $this->params()->fromPost('id');
        try {
            $item = $this->api()->read('items', $id)->getContent();
        } catch (\Exception $e) {
            return new JsonModel(['updated' => false, 'error' => 'not_found']);
        }
        try {
            $result = $this->workflowService->propose($item);
        } catch (PermissionDeniedException $e) {
            return new JsonModel(['updated' => false, 'error' => 'denied']);
        }
        return new JsonModel($result);
    }

    /**
     * Un curador rechaza un REA propuesto, con motivo (RF-016). Acción de
     * curación: ACL restringida a `editor`/`reviewer`/`site_admin`
     * (`Module::onBootstrap`), a diferencia de `proposeAction`.
     */
    public function rejectProposalAction()
    {
        if (!$this->getRequest()->isPost()) {
            return $this->redirect()->toRoute('admin/oer-manager');
        }
        if (!$this->csrfValidator()->isValid((string) $this->params()->fromPost('csrf'))) {
            return new JsonModel(['updated' => false, 'error' => 'csrf']);
        }
        $id = (int) $this->params()->fromPost('id');
        $reason = (string) $this->params()->fromPost('reason', '');
        try {
            $item = $this->api()->read('items', $id)->getContent();
        } catch (\Exception $e) {
            return new JsonModel(['updated' => false, 'error' => 'not_found']);
        }
        try {
            $result = $this->workflowService->reject($item, $reason);
        } catch (PermissionDeniedException $e) {
            return new JsonModel(['updated' => false, 'error' => 'denied']);
        }
        return new JsonModel($result);
    }

    /**
     * Un curador publica un REA propuesto (RF-016). Gate: IntegrityChecker
     * debe dar `ok` estricto (ni error ni warning, ADR-0018 §4) — igual
     * criterio que el drawer, `checkLinks=true`, porque es una acción de un
     * solo item, no un browse. Acción de curación: ACL restringida igual que
     * `rejectProposalAction`.
     */
    public function publishProposalAction()
    {
        if (!$this->getRequest()->isPost()) {
            return $this->redirect()->toRoute('admin/oer-manager');
        }
        if (!$this->csrfValidator()->isValid((string) $this->params()->fromPost('csrf'))) {
            return new JsonModel(['updated' => false, 'error' => 'csrf']);
        }
        $id = (int) $this->params()->fromPost('id');
        try {
            $item = $this->api()->read('items', $id)->getContent();
        } catch (\Exception $e) {
            return new JsonModel(['updated' => false, 'error' => 'not_found']);
        }
        $integrity = $this->integrityChecker->check($item, true);
        if (!$integrity->isOk()) {
            return new JsonModel([
                'updated' => false,
                'error' => 'integrity',
                'issues' => $integrity->getIssues(),
            ]);
        }
        try {
            $result = $this->workflowService->publish($item);
        } catch (PermissionDeniedException $e) {
            return new JsonModel(['updated' => false, 'error' => 'denied']);
        }
        return new JsonModel($result);
    }
```

- [ ] **Step 3: Actualizar la factory en `config/module.config.php`**

En la factory de `Controller\Admin\IndexController::class`, añade el argumento nuevo **al final** de la lista de `$container->get(...)` existente:

```php
                    $container->get(Service\ItemPanelData::class),
                    $container->get(Service\Workflow\WorkflowService::class)
                );
```

- [ ] **Step 4: `make lint`**

Run: `make lint`
Expected: sin violaciones PSR-12

- [ ] **Step 5: Commit**

```bash
git add src/Controller/Admin/IndexController.php config/module.config.php
git commit -m "feat(workflow): proponer/rechazar/publicar en IndexController (RF-016)"
```

---

### Task 4: ACL — `reviewer` añadido, privilegios nuevos

**Files:**
- Modify: `Module.php`

**Interfaces:**
- Consumes: `Controller\Admin\IndexController::class`, `Controller\Admin\StatsController::class` (existentes).
- Produces: ACL actualizado — no expone funciones nuevas, solo cambia qué roles pueden llegar a acciones ya existentes/nuevas.

- [ ] **Step 1: Añadir `reviewer` a los bloques de curación y estadísticas ya existentes**

En `onBootstrap()`, cambia el primer `$acl->allow(...)` (curación) y el de estadísticas para incluir `'reviewer'` junto a `'editor'`/`'site_admin'`. **No toques** el bloque de `config` (sigue exclusivo de `site_admin`+):

```php
        // Curación: vista maestra, re-catalogador, propuesta IA y visibilidad.
        // RF-016/ADR-0018 (2026-08-29): `reviewer` se añade junto a los roles
        // que ya curaban — es el rol nativo que hace de curador de REA en el
        // flujo autor→curador. No sustituye a editor/site_admin.
        $acl->allow(
            ['editor', 'site_admin', 'reviewer'],
            [Controller\Admin\IndexController::class],
            [
                'index',
                'search',
                'search-terms',
                'set-visibility',
                'recatalog-preview',
                'recatalog-apply',
                'recatalog-last-event',
                'drawer-details',
                'drawer-history',
                'recatalog-undo',
                'ai-propose',
                'ai-propose-status',
                'ai-propose-cancel',
                'ai-evaluate',
                'reject-proposal',
                'publish-proposal',
            ]
        );
```

```php
        // Estadísticas: mismo nivel que la curación (spec TASK-006 §2.1).
        // RF-016: `reviewer` se añade por el mismo motivo que arriba.
        $acl->allow(
            ['editor', 'site_admin', 'reviewer'],
            [Controller\Admin\StatsController::class],
            ['index', 'export']
        );
```

- [ ] **Step 2: Añadir el bloque nuevo de `propose`, abierto también a `author`**

Justo después del bloque de curación de arriba, un bloque **nuevo**, distinto de los demás: `proponer` no es una acción exclusiva de curador, así que se concede también a `author` (el ACL del controlador es solo la puerta de entrada; quién puede tocar CADA item ya lo decide el permiso nativo de edición dentro de `WorkflowService`, vía `$api->update()`):

```php
        // Proponer (RF-016): abierto también a `author`, a diferencia del
        // resto de acciones de curación. El controlador solo es la puerta de
        // entrada — quién puede tocar CADA item lo decide el permiso nativo
        // de edición dentro de WorkflowService::propose() (OwnsEntityAssertion
        // para author, view-all para el resto), no este ACL.
        $acl->allow(
            ['author', 'editor', 'site_admin', 'reviewer'],
            [Controller\Admin\IndexController::class],
            ['propose']
        );
```

- [ ] **Step 3: `make lint`**

Run: `make lint`
Expected: sin violaciones PSR-12

- [ ] **Step 4: Commit**

```bash
git add Module.php
git commit -m "feat(workflow): ACL — reviewer como curador, propose abierto a author (RF-016)"
```

---

### Task 5: Botón «Proponer»/«Rechazar»/«Publicar propuesta» en la página nativa del item

**Files:**
- Modify: `Module.php`
- Create: `view/oer-manager/common/workflow-actions.phtml`

**Interfaces:**
- Consumes: `WorkflowService::statusOf()` (Task 2), `WorkflowStatus::canPropose()`/`::canReject()`/`::canPublish()` (Task 1), rutas `admin/oer-manager` con `action=propose|reject-proposal|publish-proposal` (Task 3).
- Produces: nada que otro Task consuma — es la hoja del árbol, el punto de entrada visual del flujo.

**Verificado contra el core real (2026-08-28):** `view/omeka/admin/item/show.phtml` dispara `$this->trigger('view.show.page_actions', ['resource' => $item])` dentro de `#page-actions`, identificador `'Omeka\Controller\Admin\Item'` (`View\Helper\Trigger::__invoke()`, `$ids = [$routeMatch->getParam('controller')]`). El helper NO captura el valor de retorno (`$filter=false` por defecto) — el listener debe **hacer `echo`** directamente, no devolver una cadena.

- [ ] **Step 1: Añadir el listener en `attachListeners()`**

Dentro de `attachListeners()`, junto a los `$sharedEventManager->attach(...)` ya existentes:

```php
        // Botón de propuesta/rechazo/publicación en la página nativa del item
        // (RF-016, ADR-0018). Hook verificado contra el core real: dispara
        // dentro de #page-actions, junto al botón "Edit item" nativo.
        $sharedEventManager->attach(
            'Omeka\Controller\Admin\Item',
            'view.show.page_actions',
            [$this, 'addWorkflowActions']
        );
```

- [ ] **Step 2: Añadir el método del listener**

Añade tras `addSearchFilters()` (o cualquier otro método público existente):

```php
    /**
     * Botón de propuesta/rechazo/publicación (RF-016). Solo se pinta para
     * items `lrmi:LearningResource` — el flujo no aplica a nada más.
     * `view.show.page_actions` no captura el retorno del listener (ver la
     * nota de Task 5 del plan): hay que hacer `echo` directamente.
     */
    public function addWorkflowActions(Event $event): void
    {
        $item = $event->getParam('resource');
        if (!$item instanceof ItemRepresentation) {
            return;
        }
        $resourceClass = $item->resourceClass();
        if (!$resourceClass || Service\MasterViewQuery::LEARNING_RESOURCE_CLASS_TERM !== $resourceClass->term()) {
            return;
        }

        $services = $this->getServiceLocator();
        /** @var Service\Workflow\WorkflowService $workflowService */
        $workflowService = $services->get(Service\Workflow\WorkflowService::class);
        $status = $workflowService->statusOf($item);

        /** @var \Omeka\Permissions\Acl $acl */
        $acl = $services->get('Omeka\Acl');
        $isCurator = $acl->userIsAllowed('Omeka\Entity\Resource', 'view-all');
        $canEditItem = $item->userIsAllowed('update');

        $canPropose = $canEditItem && !$isCurator && Service\Workflow\WorkflowStatus::canPropose($status);
        $canReject = $isCurator && Service\Workflow\WorkflowStatus::canReject($status);
        $canPublish = $isCurator && Service\Workflow\WorkflowStatus::canPublish($status);

        if (!$canPropose && !$canReject && !$canPublish) {
            return;
        }

        $csrf = new \Laminas\Validator\Csrf([
            'name' => Controller\Admin\IndexController::CSRF_NAME,
            'salt' => Controller\Admin\IndexController::CSRF_SALT,
            'timeout' => 3600,
        ]);

        /** @var \Laminas\View\Renderer\PhpRenderer $view */
        $view = $event->getTarget();
        echo $view->partial('oer-manager/common/workflow-actions', [
            'itemId' => (int) $item->id(),
            'csrf' => $csrf->getHash(),
            'canPropose' => $canPropose,
            'canReject' => $canReject,
            'canPublish' => $canPublish,
            'proposeUrl' => $view->url('admin/oer-manager', ['action' => 'propose']),
            'rejectUrl' => $view->url('admin/oer-manager', ['action' => 'reject-proposal']),
            'publishUrl' => $view->url('admin/oer-manager', ['action' => 'publish-proposal']),
        ]);
    }
```

`$isCurator` distingue quién ve qué **sin comparar nombres de rol**: se apoya en el mismo privilegio nativo `view-all` que ya gobierna qué ve cada rol en todo Omeka (ADR-0018 §Contexto) — un `author` no lo tiene, `reviewer`/`editor`/`site_admin` sí.

- [ ] **Step 3: Crear el partial**

```php
<?php
/**
 * @var \Laminas\View\Renderer\PhpRenderer $this
 * @var int $itemId
 * @var string $csrf
 * @var bool $canPropose
 * @var bool $canReject
 * @var bool $canPublish
 * @var string $proposeUrl
 * @var string $rejectUrl
 * @var string $publishUrl
 */
$translate = $this->plugin('translate');
$escape = $this->plugin('escapeHtml');
?>
<?php if ($canPropose): ?>
<form method="post" action="<?php echo $escape($proposeUrl); ?>" class="oer-workflow-form">
    <input type="hidden" name="csrf" value="<?php echo $escape($csrf); ?>">
    <input type="hidden" name="id" value="<?php echo $itemId; ?>">
    <button type="submit" class="button"><?php echo $translate('Proponer para revisión'); ?></button>
</form>
<?php endif; ?>
<?php if ($canReject): ?>
<form method="post" action="<?php echo $escape($rejectUrl); ?>" class="oer-workflow-form oer-workflow-reject">
    <input type="hidden" name="csrf" value="<?php echo $escape($csrf); ?>">
    <input type="hidden" name="id" value="<?php echo $itemId; ?>">
    <label for="oer-workflow-reject-reason-<?php echo $itemId; ?>">
        <?php echo $translate('Motivo del rechazo'); ?>
    </label>
    <textarea id="oer-workflow-reject-reason-<?php echo $itemId; ?>" name="reason" rows="2"></textarea>
    <button type="submit" class="button"><?php echo $translate('Rechazar propuesta'); ?></button>
</form>
<?php endif; ?>
<?php if ($canPublish): ?>
<form method="post" action="<?php echo $escape($publishUrl); ?>" class="oer-workflow-form">
    <input type="hidden" name="csrf" value="<?php echo $escape($csrf); ?>">
    <input type="hidden" name="id" value="<?php echo $itemId; ?>">
    <button type="submit" class="button"><?php echo $translate('Publicar propuesta'); ?></button>
</form>
<?php endif; ?>
```

- [ ] **Step 4: `make lint`**

Run: `make lint`
Expected: sin violaciones PSR-12

- [ ] **Step 5: Commit**

```bash
git add Module.php view/oer-manager/common/workflow-actions.phtml
git commit -m "feat(workflow): botones de propuesta/rechazo/publicación en el item nativo (RF-016)"
```

---

### Task 6: Filtro «Propuestos» en la vista maestra

**Files:**
- Modify: `src/Service/MasterViewQuery.php`
- Modify: `Module.php` (chip de filtro activo)
- Modify: `view/oer-manager/admin/index/search.phtml`

**Interfaces:**
- Consumes: `WorkflowStatus::STATUS_TERM`/`::PROPOSED` (Task 1).
- Produces: query param `proposed=1` reconocido por `MasterViewQuery::buildSearchParams()`; sin cambios de firma.

- [ ] **Step 1: Añadir el filtro en `MasterViewQuery::buildSearchParams()`**

Añade el `use` al principio del fichero:

```php
use OERManager\Service\Workflow\WorkflowStatus;
```

Y, junto al bloque de `licence` (mismo patrón `eq`, sin coste computado — es un filtro de query nativo):

```php
        if (!empty($query['proposed'])) {
            $params['property'][] = [
                'property' => WorkflowStatus::STATUS_TERM,
                'type' => 'eq',
                'text' => WorkflowStatus::PROPOSED,
            ];
        }
```

- [ ] **Step 2: Añadir el chip de filtro activo en `Module.php`**

En `SEARCH_FILTER_LABELS`, añade una entrada nueva:

```php
    public const SEARCH_FILTER_LABELS = [
        'title' => 'Título', // @translate
        'visibility' => 'Visibilidad', // @translate
        'alignment' => 'Anclaje', // @translate
        'stage' => 'Etapa', // @translate
        'subject' => 'Materia', // @translate
        'project' => 'Proyecto', // @translate
        'axis' => 'Eje temático', // @translate
        'resource_type' => 'Tipo de recurso', // @translate
        'licence' => 'Licencia', // @translate
        'proposed' => 'Propuesta', // @translate
    ];
```

`addSearchFilters()` ya recorre `SEARCH_FILTER_LABELS` genéricamente (`(string) ($query[$key] ?? '')`), así que un valor `'1'` en `proposed` ya produce un chip "Propuesta: 1" sin más cambios — para que diga algo legible, añade `'proposed' => ['1' => 'Sí']` (`// @translate` en el valor) a `FILTER_VALUE_LABELS`:

```php
    private const FILTER_VALUE_LABELS = [
        'visibility' => ['public' => 'Público', 'private' => 'Privado'], // @translate
        'alignment' => [
            'complete' => 'Completo', // @translate
            'partial' => 'Parcial', // @translate
            'none' => 'Sin alinear', // @translate
        ],
        'proposed' => ['1' => 'Sí'], // @translate
    ];
```

- [ ] **Step 3: Añadir el checkbox en `search.phtml`**

Justo antes del `<div class="field">` de "Gobernanza" (o justo después — el orden no importa funcionalmente), añade:

```php
    <div class="field">
        <div class="field-meta">
            <span class="label"><?php echo $translate('Flujo de propuesta'); ?></span>
        </div>
        <div class="inputs">
            <label class="oer-checkbox">
                <input type="checkbox" name="proposed" value="1"
                    <?php echo !empty($query['proposed']) ? 'checked' : ''; ?>>
                <?php echo $translate('Solo propuestos'); ?>
            </label>
        </div>
    </div>
```

- [ ] **Step 4: `make lint`**

Run: `make lint`
Expected: sin violaciones PSR-12

- [ ] **Step 5: Commit**

```bash
git add src/Service/MasterViewQuery.php Module.php view/oer-manager/admin/index/search.phtml
git commit -m "feat(workflow): filtro «Propuestos» en la vista maestra (RF-016)"
```

---

### Task 7: Verificación en contenedor

**Files:**
- Create: `test/container/workflow-check.php`

**Interfaces:**
- Consumes: `Service\Workflow\WorkflowService`, `Service\Workflow\WorkflowStatus` reales del contenedor.
- Produces: nada nuevo — arnés de solo lectura donde sea posible; las escrituras que haga se revierten al final del propio arnés (mismo espíritu que `test/container/undo-harness.php`, que se autorrestaura).

- [ ] **Step 1: Escribir el arnés**

```php
<?php

/**
 * Arnés de verificación de RF-016 (flujo autor→curador). `WorkflowService`
 * depende del core (`Omeka\Api\Manager`/`ItemRepresentation`): no se puede
 * instanciar en un test de host (ver «Limitación conocida del arnés»,
 * project-memory.md). `WorkflowStatus` ya tiene TDD real en host — este
 * arnés cubre solo la escritura/lectura real contra el catálogo.
 *
 * ESCRIBE Y DESHACE: usa un item real del catálogo, hace el ciclo completo
 * propose→reject→propose→publish, y al final restaura el item a su estado
 * original (curation:status borrado, is_public a su valor previo) para no
 * dejar residuo. Si el arnés falla a mitad, puede dejar el item marcado —
 * revisar manualmente el item usado si el exit code es distinto de 0.
 *
 * Uso, desde el contenedor:
 *   php /var/www/html/modules/OERManager/test/container/workflow-check.php <item_id>
 *
 * El item debe ser un REA real (`lrmi:LearningResource`) sobre el que se
 * pueda escribir. Si no se pasa id, prueba con el primer REA que encuentre.
 */

require '/var/www/html/bootstrap.php';

use OERManager\Service\MasterViewQuery;
use OERManager\Service\Workflow\WorkflowService;
use OERManager\Service\Workflow\WorkflowStatus;

$application = Omeka\Mvc\Application::init(require '/var/www/html/application/config/application.config.php');
$services = $application->getServiceManager();
$api = $services->get('Omeka\ApiManager');
/** @var WorkflowService $workflow */
$workflow = $services->get(WorkflowService::class);

$itemId = isset($argv[1]) ? (int) $argv[1] : null;
if (null === $itemId) {
    $classes = $api->search('resource_classes', ['term' => MasterViewQuery::LEARNING_RESOURCE_CLASS_TERM])->getContent();
    if (!$classes) {
        fwrite(STDERR, "No existe la clase lrmi:LearningResource en esta instalación.\n");
        exit(1);
    }
    $items = $api->search('items', ['resource_class_id' => $classes[0]->id(), 'per_page' => 1])->getContent();
    if (!$items) {
        fwrite(STDERR, "No hay ningún REA en el catálogo para probar.\n");
        exit(1);
    }
    $itemId = (int) $items[0]->id();
}

$passed = 0;
$failed = 0;

function check(string $label, bool $condition, string $detail = ''): void
{
    global $passed, $failed;
    if ($condition) {
        $passed++;
        echo "  OK   $label\n";
        return;
    }
    $failed++;
    echo "  FAIL $label" . ('' !== $detail ? " — $detail" : '') . "\n";
}

echo "Item de prueba: #$itemId\n";
$originalItem = $api->read('items', $itemId)->getContent();
$originalIsPublic = $originalItem->isPublic();
$originalStatus = $workflow->statusOf($originalItem);
check('El item de prueba empieza sin estado de flujo (o ya se limpia después)', true);

// 1. Proponer.
$item = $api->read('items', $itemId)->getContent();
$result = $workflow->propose($item);
check('propose() desde el estado inicial da updated=true', true === ($result['updated'] ?? false), json_encode($result));
$item = $api->read('items', $itemId)->getContent();
check('El item queda en Propuesto', WorkflowStatus::PROPOSED === $workflow->statusOf($item));

// 2. Rechazar con motivo.
$result = $workflow->reject($item, 'Falta la licencia');
check('reject() desde Propuesto da updated=true', true === ($result['updated'] ?? false), json_encode($result));
$item = $api->read('items', $itemId)->getContent();
check('El item queda en Rechazado', WorkflowStatus::REJECTED === $workflow->statusOf($item));
$noteValue = $item->value(WorkflowStatus::NOTE_TERM);
check('El motivo del rechazo se guardó', null !== $noteValue && 'Falta la licencia' === (string) $noteValue->value());

// 3. Re-proponer: el motivo debe desaparecer.
$result = $workflow->propose($item);
check('propose() desde Rechazado da updated=true', true === ($result['updated'] ?? false), json_encode($result));
$item = $api->read('items', $itemId)->getContent();
check('El item vuelve a Propuesto', WorkflowStatus::PROPOSED === $workflow->statusOf($item));
check('El motivo del rechazo anterior se limpió', null === $item->value(WorkflowStatus::NOTE_TERM));

// 4. Publicar (sin pasar por IntegrityChecker aquí — eso lo prueba el
// controlador; este arnés solo prueba la escritura de WorkflowService).
$result = $workflow->publish($item);
check('publish() desde Propuesto da updated=true', true === ($result['updated'] ?? false), json_encode($result));
$item = $api->read('items', $itemId)->getContent();
check('El item queda público', $item->isPublic());
check('curation:status se elimina al publicar', null === $workflow->statusOf($item));

// 5. Transiciones inválidas.
$result = $workflow->reject($item, 'no debería aplicar');
check('reject() sobre un item ya publicado (sin estado) da updated=false', false === ($result['updated'] ?? true));
$result = $workflow->publish($item);
check('publish() sobre un item ya publicado (sin estado) da updated=false', false === ($result['updated'] ?? true));

// Restaurar el item a su estado original.
//
// ⚠️ MISMA TRAMPA que WorkflowService (skill `recatalogador`, incidente
// 2026-06-25): un update con valores sin `clear_property_values` +
// `collectionAction=append` borraría TODO lo demás del item (título,
// descripción, alineamiento...), no solo el estado. Este arnés existe para
// verificar de forma segura — restaurar de forma insegura sería peor que no
// restaurar.
$statusPropertyId = $api->search('properties', ['term' => WorkflowStatus::STATUS_TERM])->getContent()[0]->id();
$api->update('items', $itemId, [
    'o:is_public' => $originalIsPublic,
    WorkflowStatus::STATUS_TERM => $originalStatus ? [[
        'type' => 'literal',
        'property_id' => $statusPropertyId,
        '@value' => $originalStatus,
    ]] : [],
    'clear_property_values' => [$statusPropertyId],
], [], ['isPartial' => true, 'collectionAction' => 'append']);
echo "Item #$itemId restaurado a su estado original (is_public=" . ($originalIsPublic ? '1' : '0') . ").\n";

echo "\n$passed OK, $failed FAIL\n";
exit($failed > 0 ? 1 : 0);
```

- [ ] **Step 2: Ejecutar en el contenedor real**

Run: `docker exec omeka-s-moduletemplate-omekas-1 php /var/www/html/modules/OERManager/test/container/workflow-check.php`
Expected: todas las líneas `OK`, `0 FAIL`, y la línea final de restauración confirmando que el item queda como estaba.

Si el catálogo real no tiene ningún REA sobre el que el usuario del contenedor pueda escribir, o algo no encaja con lo que el arnés espera, investigar antes de forzar el resultado — no ajustar el arnés a ciegas para que pase.

- [ ] **Step 3: Verificar en el admin real (navegador) — residual si no hay sesión disponible**

Manual: crear un usuario `author`, comprobar que solo ve sus propios items en el browse nativo; crear un item REA con la plantilla (si PEND-012 ya está resuelto) y proponerlo; entrar como `reviewer`, comprobar que aparece en el filtro «Propuestos» de la vista maestra y que el botón de propuesta correcto aparece en la página nativa del item; rechazar con motivo, comprobar que el autor lo ve; re-proponer; publicar y comprobar que pasa a público y pierde el estado. Documentar como residuo hacia TASK-030 si esta sesión no tiene navegador/credenciales disponibles, mismo patrón que TASK-006/TASK-032/033/034.

- [ ] **Step 4: Commit**

```bash
git add test/container/workflow-check.php
git commit -m "test(workflow): arnés de verificación en contenedor (RF-016)"
```

---

### Task 8: Cierre de gobierno — backlog, trazabilidad, memoria

**Files:**
- Modify: `docs/backlog.md`
- Modify: `docs/traceability.md`
- Modify: `docs/requirements.md`

**Interfaces:** ninguna — solo documentación.

- [ ] **Step 1: Dar de alta TASK-037 en `docs/backlog.md`**

Añadir una fila nueva `TASK-037` (siguiente id libre tras TASK-036) con el resumen de lo entregado: `WorkflowStatus`/`WorkflowService`, ACL con `reviewer`+`author`, botones en la página nativa del item, filtro «Propuestos», resultado del arnés de contenedor, y el residuo de verificación en navegador real si aplica (mismo patrón que TASK-006).

- [ ] **Step 2: Actualizar `docs/traceability.md`**

En la fila de RF-016 (añadida al brainstormear este flujo), sustituir `TASK: — (spec aprobada...)` por `TASK-037` y actualizar el criterio de aceptación con lo verificado en el arnés de contenedor.

- [ ] **Step 3: Actualizar el estado de RF-016 en `docs/requirements.md`**

La fila de RF-016 ya dice `aceptado` (era una decisión, no una propuesta) — añadir al final de su criterio de aceptación una nota breve: `**Implementado (TASK-037):** ver docs/backlog.md`.

- [ ] **Step 4: `make lint` y `make test`/`make test-js` completos**

Run: `make lint && make test && make test-js`
Expected: todo en verde

- [ ] **Step 5: Commit**

```bash
git add docs/backlog.md docs/traceability.md docs/requirements.md
git commit -m "docs: cierre de TASK-037 (flujo autor→curador) en backlog/traceability/requirements"
```

---

## Self-Review

**1. Cobertura del spec:** §2 (actores/roles) → Task 4. §3 (estados) → Task 1, 2. §4 (flujo autor: proponer) → Task 1, 2, 3, 5. §5 (flujo curador: filtro, rechazar, publicar) → Task 3, 5, 6. §6 (ACL) → Task 4. §7 (fuera de alcance) → sin tareas, correcto — no se ha inventado notificaciones, límite de reintentos, propuesta curricular del autor, ni botón de anulación. §8 (testing) → Task 1 (puro), 2/3/5 (sin test de host, documentado), 7 (contenedor).

**2. Placeholders:** ninguno — cada paso trae código completo, incluida la corrección señalada explícitamente en el Task 2 (el `null`→`''` de `propose()`), que no es un placeholder sino una nota de implementación con el código exacto de la corrección.

**3. Consistencia de tipos:** `WorkflowStatus::STATUS_TERM`/`::NOTE_TERM`/`::PROPOSED`/`::REJECTED` se usan idénticos en `WorkflowService` (Task 2), `MasterViewQuery` (Task 6) y el arnés de contenedor (Task 7). La firma `WorkflowService::propose/reject/publish(ItemRepresentation $item, ...): array{updated:bool,...}` es la misma que consumen `IndexController` (Task 3) y el listener de botones (Task 5, vía `statusOf()`). Las URLs de acción (`propose`, `reject-proposal`, `publish-proposal`) coinciden exactamente entre el ACL (Task 4), los nombres de método del controlador (Task 3, `proposeAction`/`rejectProposalAction`/`publishProposalAction` — Laminas mapea `reject-proposal` → `rejectProposalAction` por convención kebab→camelCase, mismo patrón que `recatalog-apply`→`recatalogApplyAction` ya usado en este controlador) y las URLs generadas en el partial (Task 5).
