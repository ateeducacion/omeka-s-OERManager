<?php

namespace OERManager;

use Laminas\EventManager\Event;
use Laminas\EventManager\SharedEventManagerInterface;
use Laminas\ModuleManager\Feature\InitProviderInterface;
use Laminas\ModuleManager\ModuleManagerInterface;
use Laminas\Mvc\MvcEvent;
use Laminas\ServiceManager\ServiceLocatorInterface;
use Omeka\Api\Representation\ItemRepresentation;
use Omeka\Module\AbstractModule;

/**
 * Módulo OERManager: gestión de un catálogo de Recursos Educativos Abiertos
 * (items lrmi:LearningResource). Vista maestra (TASK-003), integridad
 * (TASK-005) y re-catalogador curricular/tags (TASK-004).
 */
class Module extends AbstractModule implements InitProviderInterface
{
    /**
     * Registra el autoloader de las dependencias propias del módulo
     * (smalot/pdfparser, TASK-010).
     *
     * Omeka NO autocarga el `vendor/` de un módulo y el core no trae pdfparser:
     * sin esto la clase no existe en runtime, `ContentExtractor::parsePdf()`
     * captura el Error y marca TODO PDF como `pdf_unreadable` en silencio
     * (defecto hallado en la verificación en contenedor de TASK-019/022,
     * 2026-07-22). Los tests del host no lo detectaban porque `test/phpunit.xml`
     * bootstrapea `vendor/autoload.php` directamente.
     *
     * `Omeka\Module\AbstractModule` solo implementa `ConfigProviderInterface`, y
     * el `InitTrigger` de Laminas solo invoca `init()` sobre un
     * `InitProviderInterface`: por eso la interfaz se declara explícitamente.
     *
     * El guard mantiene el módulo cargable si se distribuye sin `vendor/`
     * (`composer.json` §archive.exclude): la extracción de PDF se degrada, pero
     * el módulo no revienta.
     */
    public function init(ModuleManagerInterface $manager): void
    {
        if (file_exists(__DIR__ . '/vendor/autoload.php')) {
            require_once __DIR__ . '/vendor/autoload.php';
        }
    }

    public function getConfig()
    {
        return include __DIR__ . '/config/module.config.php';
    }

    /**
     * ACL (NFR-003, TASK-067/ADR-0023 §1). Se concede **por privilegio**,
     * nunca por controlador, y solo a los roles `rea_proponente` y
     * `rea_curador` (más `global_admin`, al que el core ya concede todo). La
     * matriz entera vive en `Permissions\ReaAcl` y la fija `ReaAclTest`.
     *
     * Hasta TASK-067 la curación se concedía a `editor`/`site_admin`/`reviewer`
     * y proponer también a `author`; por herencia, un `site_editor` de
     * IsolatedSites recibía todo, incluida la propuesta IA de pago. En la
     * mediateca compartida eso son ~1 200 editores de centro.
     *
     * Orden de carga: los roles REA heredan de `site_editor`, que IsolatedSites
     * añade en su propio onBootstrap(). El core ordena los módulos por id con
     * `ksort()` (`Module\Manager::sortModules()`), así que IsolatedSites arranca
     * antes; sin IsolatedSites se usan los padres de respaldo (ReaAcl).
     */
    public function onBootstrap(MvcEvent $event): void
    {
        parent::onBootstrap($event);
        Permissions\ReaAcl::register($this->getServiceLocator()->get('Omeka\Acl'));
    }

    public function install(ServiceLocatorInterface $serviceLocator)
    {
        // Sin tablas Doctrine propias (NFR-002): no hay nada que crear.
        // Los datos del módulo viven como valores RDF y settings nativos.
    }

    /**
     * Sin tablas propias (NFR-002). Los usuarios con un rol REA vuelven al rol
     * padre (ADR-0023 §1.6): sin el módulo la ACL ya no conoce `rea_*` y
     * Laminas lanzaría una excepción en cada petición suya. Los valores
     * `curation:status`/`curation:note` se quedan: son RDF nativo.
     */
    public function uninstall(ServiceLocatorInterface $serviceLocator)
    {
        $acl = $serviceLocator->get('Omeka\Acl');
        $connection = $serviceLocator->get('Omeka\Connection');
        $targets = Permissions\ReaAcl::uninstallTargets($acl->hasRole(Permissions\ReaAcl::SITE_EDITOR));
        foreach ($targets as $role => $target) {
            $connection->executeStatement('UPDATE user SET role = ? WHERE role = ?', [$target, $role]);
        }
    }

    public function upgrade($oldVersion, $newVersion, ServiceLocatorInterface $serviceLocator)
    {
        // Sin migraciones de esquema (NFR-002). TASK-064 (ADR-0022): fuera los
        // ajustes del grafo de ADR-0009; la clase cur: fija la dimensión. Borrar
        // un ajuste que no existe no hace nada, así que se repite sin daño.
        $settings = $serviceLocator->get('Omeka\Settings');
        foreach (Service\ConfigPayload::LEGACY_SETTINGS as $legacy) {
            $settings->delete($legacy);
        }
    }

    public function attachListeners(SharedEventManagerInterface $sharedEventManager)
    {
        // Integridad RDF (TASK-005, RF-006): valida y registra issues tras guardar un item.
        $sharedEventManager->attach(
            'Omeka\Api\Adapter\ItemAdapter',
            'api.create.post',
            [$this, 'handleItemPostSave']
        );
        $sharedEventManager->attach(
            'Omeka\Api\Adapter\ItemAdapter',
            'api.update.post',
            [$this, 'handleItemPostSave']
        );
        // Columnas y orden por defecto de la vista maestra, configurables por
        // cada curador desde su perfil (TASK-028). Se usa la clave propia
        // `oer_items`: compartir la de `items` haría que configurar esta tabla
        // cambiase el browse nativo del admin, y al revés.
        $sharedEventManager->attach(
            \Omeka\Form\UserForm::class,
            'form.add_elements',
            [$this, 'addBrowseConfigElements']
        );
        // Chips de filtros activos (TASK-028, D-5): el helper nativo solo conoce
        // los parámetros del core, así que el módulo añade los suyos por el
        // evento que expone. El identificador es el `controller` del routeMatch
        // (cfr. View\Helper\Trigger), es decir NUESTRO controlador.
        $sharedEventManager->attach(
            Controller\Admin\IndexController::class,
            'view.search.filters',
            [$this, 'addSearchFilters']
        );
        // Botón de propuesta/rechazo/publicación en la página nativa del item
        // (RF-016, ADR-0018). Hook verificado contra el core real: dispara
        // dentro de #page-actions, junto al botón "Edit item" nativo.
        $sharedEventManager->attach(
            'Omeka\Controller\Admin\Item',
            'view.show.page_actions',
            [$this, 'addWorkflowActions']
        );
    }

    /**
     * Etiqueta de cada filtro propio de la vista maestra. La usa el listener de
     * chips y el partial que los pinta, para saber qué parámetro quita cada uno.
     */
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

    /** Filtros cuyo valor es el id de un item-término: se muestra su título. */
    private const RESOURCE_FILTERS = ['stage', 'subject', 'project', 'axis'];

    /** Valores codificados que no se pueden enseñar en crudo. */
    private const FILTER_VALUE_LABELS = [
        'visibility' => ['public' => 'Público', 'private' => 'Privado'], // @translate
        'alignment' => [
            'complete' => 'Completo', // @translate
            'partial' => 'Parcial', // @translate
            'none' => 'Sin alinear', // @translate
        ],
        'proposed' => ['1' => 'Sí'], // @translate
    ];

    /**
     * Etiquetas de los filtros «sin X» de gobernanza (`missing[]`), mismo orden
     * que el formulario avanzado (search.phtml). No viven en SEARCH_FILTER_LABELS
     * porque ese mapa alimenta un bucle que hace `(string) $query[$key]`, y
     * `missing` es un array: entraría como "Array to string conversion".
     */
    public const MISSING_GROUP_LABEL = 'Gobernanza'; // @translate

    public const MISSING_FILTER_LABELS = [
        'licence' => 'Sin licencia', // @translate
        'description' => 'Sin descripción', // @translate
        'title' => 'Sin título', // @translate
        'resource_type' => 'Sin tipo de recurso', // @translate
    ];

    /**
     * Etiquetas del filtro computado `integrity` (ComputedPredicates::INTEGRITY).
     * Fuera de SEARCH_FILTER_LABELS porque no viaja como property de la API: es
     * un predicado evaluado en el controlador sobre IntegrityChecker::check().
     */
    public const INTEGRITY_GROUP_LABEL = 'Integridad'; // @translate

    public const INTEGRITY_FILTER_LABELS = [
        'ok' => 'Ficha completa', // @translate
        'warning' => 'Con incidencias', // @translate
    ];

    /**
     * Añade los filtros del módulo a los chips de búsqueda activa (D-5).
     *
     * Los filtros curriculares llevan el id del item-término, que al curador no
     * le dice nada: se resuelve su título por API. Si el término ya no existe se
     * cae al id, que al menos identifica lo que se está filtrando.
     */
    public function addSearchFilters(Event $event): void
    {
        $filters = $event->getParam('filters');
        $query = $event->getParam('query', []);
        $api = $this->getServiceLocator()->get('Omeka\ApiManager');

        foreach (self::SEARCH_FILTER_LABELS as $key => $label) {
            $value = (string) ($query[$key] ?? '');
            if ('' === $value) {
                continue;
            }
            if (isset(self::FILTER_VALUE_LABELS[$key][$value])) {
                $value = self::FILTER_VALUE_LABELS[$key][$value];
            } elseif (in_array($key, self::RESOURCE_FILTERS, true)) {
                try {
                    $value = (string) $api->read('items', (int) $value)->getContent()->displayTitle();
                } catch (\Exception $e) {
                    // El término ya no existe: se deja el id.
                }
            }
            $filters[$label][] = $value;
        }

        // `missing[]` es un array: rama propia, un chip (valor) por cada clave
        // marcada, agrupado bajo la misma etiqueta que la sección "Gobernanza"
        // del formulario avanzado.
        foreach ((array) ($query['missing'] ?? []) as $missingKey) {
            if (is_string($missingKey) && isset(self::MISSING_FILTER_LABELS[$missingKey])) {
                $filters[self::MISSING_GROUP_LABEL][] = self::MISSING_FILTER_LABELS[$missingKey];
            }
        }

        // `integrity` es escalar pero, igual que `missing`, no viaja como
        // property de la API (ver INTEGRITY_FILTER_LABELS): rama propia.
        $integrityValue = (string) ($query['integrity'] ?? '');
        if (isset(self::INTEGRITY_FILTER_LABELS[$integrityValue])) {
            $filters[self::INTEGRITY_GROUP_LABEL][] = self::INTEGRITY_FILTER_LABELS[$integrityValue];
        }

        $event->setParam('filters', $filters);
    }

    /**
     * Insignia de estado (RF-017) y botón de propuesta/rechazo/publicación
     * (RF-016). Solo se pinta para items `lrmi:LearningResource` — el flujo
     * no aplica a nada más.
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
        $index = Controller\Admin\IndexController::class;
        $canEditItem = $item->userIsAllowed('update');

        // TASK-067: cada botón exige el privilegio de SU acción. Antes «es
        // curador» era `view-all`, que `site_editor` hereda de `editor`: un
        // proponente habría visto los botones del curador. La UI no puede
        // ofrecer lo que el ACL deniega, ni ocultar lo que concede.
        $canPropose = $canEditItem && $acl->userIsAllowed($index, 'propose')
            && Service\Workflow\WorkflowStatus::canPropose($status);
        $canReject = $acl->userIsAllowed($index, 'reject-proposal')
            && Service\Workflow\WorkflowStatus::canReject($status);
        $canPublish = $acl->userIsAllowed($index, 'publish-proposal')
            && Service\Workflow\WorkflowStatus::canPublish($status);

        /** @var \Laminas\View\Renderer\PhpRenderer $view */
        $view = $event->getTarget();

        // Insignia Propuesto/Rechazado (RF-017, TASK-044). Va antes del
        // corte por permisos: el autor de un REA ya propuesto no tiene
        // ninguna acción, y es justo quien tiene que ver que espera
        // validación. La vista maestra está cerrada a `author`; esta página
        // es la suya.
        $badge = Service\Workflow\WorkflowStatus::badge($status);
        if (null !== $badge) {
            $view->headLink()->appendStylesheet($view->assetUrl('css/oer-workflow-badge.css', 'OERManager'));
            echo $view->partial('oer-manager/common/workflow-badge', ['badge' => $badge]);
        }

        if (!$canPropose && !$canReject && !$canPublish) {
            return;
        }

        $csrf = new \Laminas\Validator\Csrf([
            'name' => Controller\Admin\IndexController::CSRF_NAME,
            'salt' => Controller\Admin\IndexController::CSRF_SALT,
            'timeout' => 3600,
        ]);

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

    /**
     * Añade al perfil del usuario los dos campos que gobiernan la vista maestra.
     *
     * Van al fieldset `user-settings`, no a la raíz del formulario: el
     * UserController solo persiste como user settings lo que llega bajo esa
     * clave, así que colgarlos de la raíz los pintaría sin llegar a guardarlos.
     * Los nombres son los que Omeka\Stdlib\Browse compone al leer los settings
     * (`columns_admin_oer_items`, `browse_defaults_admin_oer_items`), y los
     * grupos son los que el propio UserForm ya declara.
     */
    public function addBrowseConfigElements(Event $event): void
    {
        /** @var \Omeka\Form\UserForm $form */
        $form = $event->getTarget();
        $userId = $form->getOption('user_id');
        $settingsFieldset = $form->get('user-settings');

        $settingsFieldset->add([
            'name' => 'columns_admin_oer_items',
            'type' => \Omeka\Form\Element\Columns::class,
            'options' => [
                'element_group' => 'columns',
                'label' => 'Columnas de la vista maestra de REA', // @translate
                'columns_context' => 'admin',
                'columns_resource_type' => 'oer_items',
                'columns_user_id' => $userId,
            ],
        ]);
        $settingsFieldset->add([
            'name' => 'browse_defaults_admin_oer_items',
            'type' => \Omeka\Form\Element\BrowseDefaults::class,
            'options' => [
                'element_group' => 'browse_defaults',
                'label' => 'Orden por defecto de la vista maestra de REA', // @translate
                'browse_defaults_context' => 'admin',
                'browse_defaults_resource_type' => 'oer_items',
                'browse_defaults_user_id' => $userId,
            ],
        ]);
    }

    /**
     * Comprueba la integridad RDF de un item lrmi:LearningResource tras guardarlo
     * y vuelca las incidencias en el logger de Omeka (RF-006, TASK-005).
     * No bloquea el guardado: la acción sobre errores se decide en un ADR futuro.
     */
    public function handleItemPostSave(Event $event): void
    {
        $item = $event->getParam('response')->getContent();
        if (!$item instanceof ItemRepresentation) {
            return;
        }
        $class = $item->resourceClass();
        if (!$class || $class->term() !== 'lrmi:LearningResource') {
            return;
        }

        $services = $this->getServiceLocator();
        $checker = $services->get(Service\IntegrityChecker::class);
        $result = $checker->check($item);

        if (!$result->isOk()) {
            $logger = $services->get('Omeka\Logger');
            foreach ($result->getIssues() as $issue) {
                $logger->warn(sprintf(
                    'OERManager integrity [%s] item %d – [%s] %s',
                    $issue['severity'],
                    $item->id(),
                    $issue['code'],
                    $issue['message']
                ));
            }
        }
    }
}
