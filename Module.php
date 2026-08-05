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
     * ACL (NFR-003). Se concede **por privilegio**, nunca por controlador.
     *
     * Hasta TASK-029 esto era un `allow(['editor','site_admin'], [Controller])`
     * sin lista de privilegios, o sea el controlador entero: cualquier acción
     * nueva quedaba alcanzable por `editor` —y por `site_editor`, que el módulo
     * IsolatedSites añade heredando de `editor`— con solo escribirla. Con la
     * configuración colgando de este mismo controlador eso habría abierto la
     * clave API del LLM y los vocabularios del catálogo a un editor.
     *
     * La lista va dentro del método a propósito, por el mismo motivo que
     * `governanceIdSettings()` lo era: Laminas instancia este Module antes de
     * registrar el autoloading PSR-4 del módulo. Aquí solo hay literales, pero
     * el criterio se mantiene para no invitar a meter una referencia de clase.
     *
     * Los privilegios son los nombres de acción tal cual llegan en la ruta —con
     * guiones—, que es lo que compara el core al despachar
     * (`Mvc\MvcListeners::authorizeUserAgainstController`).
     */
    public function onBootstrap(MvcEvent $event): void
    {
        parent::onBootstrap($event);
        /** @var \Omeka\Permissions\Acl $acl */
        $acl = $this->getServiceLocator()->get('Omeka\Acl');

        // Curación: vista maestra, re-catalogador, propuesta IA y visibilidad.
        $acl->allow(
            ['editor', 'site_admin'],
            [Controller\Admin\IndexController::class],
            [
                'index',
                'search',
                'search-terms',
                'set-visibility',
                'recatalog-preview',
                'recatalog-apply',
                'recatalog-last-event',
                'recatalog-undo',
                'ai-propose',
                'ai-propose-status',
                'ai-propose-cancel',
                'ai-evaluate',
            ]
        );

        // Configuración: solo Supervisor (site_admin) y superior, decisión del
        // propietario (2026-07-28). Gobierna la clave API del LLM y los
        // vocabularios de todo el catálogo, así que no es tarea de curación.
        // `global_admin` no se lista porque el core ya le concede todo
        // (`Service\AclFactory::addRulesForGlobalAdmin`, `$acl->allow('global_admin')`).
        $acl->allow(
            ['site_admin'],
            [Controller\Admin\IndexController::class],
            ['config']
        );
    }

    public function install(ServiceLocatorInterface $serviceLocator)
    {
        // Sin tablas Doctrine propias (NFR-002): no hay nada que crear.
        // Los datos del módulo viven como valores RDF y settings nativos.
    }

    public function uninstall(ServiceLocatorInterface $serviceLocator)
    {
        // Sin tablas ni settings persistidos todavía: no hay nada que limpiar.
    }

    public function upgrade($oldVersion, $newVersion, ServiceLocatorInterface $serviceLocator)
    {
        // Sin migraciones de esquema (NFR-002).
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
    ];

    /**
     * Etiquetas de los filtros «sin X» de gobernanza (`missing[]`), mismo orden
     * que el formulario avanzado (search.phtml). No viven en SEARCH_FILTER_LABELS
     * porque ese mapa alimenta un bucle que hace `(string) $query[$key]`, y
     * `missing` es un array: entraría como "Array to string conversion".
     */
    private const MISSING_FILTER_LABELS = [
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
    private const INTEGRITY_FILTER_LABELS = [
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
                $filters['Gobernanza'][] = self::MISSING_FILTER_LABELS[$missingKey]; // @translate
            }
        }

        // `integrity` es escalar pero, igual que `missing`, no viaja como
        // property de la API (ver INTEGRITY_FILTER_LABELS): rama propia.
        $integrityValue = (string) ($query['integrity'] ?? '');
        if (isset(self::INTEGRITY_FILTER_LABELS[$integrityValue])) {
            $filters['Integridad'][] = self::INTEGRITY_FILTER_LABELS[$integrityValue]; // @translate
        }

        $event->setParam('filters', $filters);
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
