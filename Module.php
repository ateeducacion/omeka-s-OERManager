<?php

namespace OERManager;

use Laminas\EventManager\Event;
use Laminas\EventManager\SharedEventManagerInterface;
use Laminas\ModuleManager\Feature\InitProviderInterface;
use Laminas\ModuleManager\ModuleManagerInterface;
use Laminas\Mvc\Controller\AbstractController;
use Laminas\Mvc\MvcEvent;
use Laminas\ServiceManager\ServiceLocatorInterface;
use Laminas\View\Renderer\PhpRenderer;
use OERManager\Service\CurriculumSearch;
use OERManager\Service\GovernanceSettings;
use OERManager\Service\Llm\LlmSettings;
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
     * Settings de gobernanza que identifican un artefacto por id (ADR-0013):
     * se cargan y se guardan igual, con el mismo parser, así que van en bucle.
     * El titular de derechos va aparte porque es texto, no id.
     *
     * Es un MÉTODO y no una constante de clase a propósito: Laminas instancia
     * este Module (ModuleResolverListener) ANTES de registrar el autoloading
     * PSR-4 del módulo, así que una constante que referencie
     * `GovernanceSettings::` se evalúa cuando esa clase todavía no existe y el
     * arranque muere con «Class not found» (verificado en el contenedor,
     * 2026-07-28). Dentro de un método se resuelve al invocarlo, ya tarde.
     *
     * @return string[]
     */
    private function governanceIdSettings(): array
    {
        return [
            GovernanceSettings::LICENCE_VOCAB_ID,
            GovernanceSettings::RESOURCE_TYPE_VOCAB_ID,
            GovernanceSettings::REA_TEMPLATE_ID,
        ];
    }

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
     * ACL de curación (NFR-003): el re-catalogador y la vista maestra requieren
     * rol editor o superior. global_admin y site_admin suelen tener allow global
     * por el AclFactory de Omeka; se listan editor y site_admin explícitamente
     * para garantizar el acceso (idempotente si ya estaban). El proyecto
     * (schema:isPartOf) no se toca aquí: acción de gestor aparte (ADR-0004).
     */
    public function onBootstrap(MvcEvent $event): void
    {
        parent::onBootstrap($event);
        /** @var \Omeka\Permissions\Acl $acl */
        $acl = $this->getServiceLocator()->get('Omeka\Acl');
        $acl->allow(
            ['editor', 'site_admin'],
            [Controller\Admin\IndexController::class]
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
        'alignment' => 'Alineamiento', // @translate
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

    public function getConfigForm(PhpRenderer $renderer)
    {
        $services = $this->getServiceLocator();
        $settings = $services->get('Omeka\Settings');
        $form = $services->get('FormElementManager')->get(Form\ConfigForm::class);
        $data = [
            CurriculumSearch::AXIS_SETTING => $settings->get(CurriculumSearch::AXIS_SETTING),
            CurriculumSearch::FRAMEWORK_SETTING => $settings->get(CurriculumSearch::FRAMEWORK_SETTING),
        ];
        foreach (CurriculumSearch::TYPE_SETTINGS as $setting) {
            $data[$setting] = $settings->get($setting);
        }
        // Gobernanza del catálogo (ADR-0013): vocabularios, plantilla y titular.
        foreach ($this->governanceIdSettings() as $setting) {
            $data[$setting] = $settings->get($setting);
        }
        $data[GovernanceSettings::DEFAULT_RIGHTS_HOLDER] = $settings->get(
            GovernanceSettings::DEFAULT_RIGHTS_HOLDER
        );
        // Conexión LLM (TASK-010). La clave API NO se devuelve en claro (write-only):
        // el campo se deja en blanco; solo se actualiza si el admin introduce un valor.
        $data[LlmSettings::ENABLED] = (bool) $settings->get(LlmSettings::ENABLED);
        $data[LlmSettings::PROVIDER] = $settings->get(LlmSettings::PROVIDER, LlmSettings::PROVIDER_ANTHROPIC);
        $data[LlmSettings::BASE_URL] = $settings->get(LlmSettings::BASE_URL);
        $data[LlmSettings::MODEL] = $settings->get(LlmSettings::MODEL);
        $data[LlmSettings::CONTENT_TOKEN_CAP] = $settings->get(
            LlmSettings::CONTENT_TOKEN_CAP,
            LlmSettings::DEFAULT_CONTENT_TOKEN_CAP
        );
        // Perfil de inferencia compartido (paridad entre proveedores).
        $data[LlmSettings::TEMPERATURE] = $settings->get(LlmSettings::TEMPERATURE);
        $data[LlmSettings::MAX_TOKENS] = $settings->get(LlmSettings::MAX_TOKENS, LlmSettings::DEFAULT_MAX_TOKENS);
        // Capa de contexto del LLM (ADR-0011): extracción/visión.
        $data[LlmSettings::EXTRACTION_MODEL] = $settings->get(LlmSettings::EXTRACTION_MODEL);
        $data[LlmSettings::VISION_ENABLED] = (bool) $settings->get(LlmSettings::VISION_ENABLED);
        $data[LlmSettings::VISION_MAX_IMAGES] = $settings->get(
            LlmSettings::VISION_MAX_IMAGES,
            LlmSettings::DEFAULT_VISION_MAX_IMAGES
        );
        $data[LlmSettings::VISION_MAX_PDF_BYTES] = $settings->get(
            LlmSettings::VISION_MAX_PDF_BYTES,
            LlmSettings::DEFAULT_VISION_MAX_PDF_BYTES
        );
        $form->setData($data);
        return $renderer->formCollection($form);
    }

    public function handleConfigForm(AbstractController $controller)
    {
        $settings = $this->getServiceLocator()->get('Omeka\Settings');
        $params = $controller->getRequest()->getPost();

        $axisId = (int) $params[CurriculumSearch::AXIS_SETTING];
        $settings->set(CurriculumSearch::AXIS_SETTING, $axisId > 0 ? $axisId : null);
        $settings->set(
            CurriculumSearch::FRAMEWORK_SETTING,
            trim((string) $params[CurriculumSearch::FRAMEWORK_SETTING])
        );
        foreach (CurriculumSearch::TYPE_SETTINGS as $setting) {
            $settings->set($setting, trim((string) ($params[$setting] ?? '')));
        }

        // Gobernanza del catálogo (ADR-0013). Los artefactos se identifican por id;
        // un valor vacío o no numérico se guarda como null = «sin configurar», que
        // hace degradar el campo a texto libre en vez de romper.
        foreach ($this->governanceIdSettings() as $setting) {
            $settings->set($setting, GovernanceSettings::parseId($params[$setting] ?? null));
        }
        $settings->set(
            GovernanceSettings::DEFAULT_RIGHTS_HOLDER,
            GovernanceSettings::parseRightsHolder($params[GovernanceSettings::DEFAULT_RIGHTS_HOLDER] ?? null)
        );

        // Conexión LLM (TASK-010, ADR-0008).
        $settings->set(LlmSettings::ENABLED, !empty($params[LlmSettings::ENABLED]));
        $provider = (string) ($params[LlmSettings::PROVIDER] ?? LlmSettings::PROVIDER_ANTHROPIC);
        $allowed = [LlmSettings::PROVIDER_ANTHROPIC, LlmSettings::PROVIDER_OPENAI];
        $settings->set(
            LlmSettings::PROVIDER,
            in_array($provider, $allowed, true) ? $provider : LlmSettings::PROVIDER_ANTHROPIC
        );
        $settings->set(LlmSettings::BASE_URL, trim((string) ($params[LlmSettings::BASE_URL] ?? '')));
        $settings->set(LlmSettings::MODEL, trim((string) ($params[LlmSettings::MODEL] ?? '')));
        $cap = (int) ($params[LlmSettings::CONTENT_TOKEN_CAP] ?? 0);
        $settings->set(LlmSettings::CONTENT_TOKEN_CAP, $cap > 0 ? $cap : LlmSettings::DEFAULT_CONTENT_TOKEN_CAP);

        // Perfil de inferencia compartido: temperatura vacía/no válida = no enviar.
        $temperature = LlmSettings::parseTemperature($params[LlmSettings::TEMPERATURE] ?? null);
        $settings->set(LlmSettings::TEMPERATURE, null === $temperature ? '' : (string) $temperature);
        $settings->set(LlmSettings::MAX_TOKENS, LlmSettings::parseMaxTokens($params[LlmSettings::MAX_TOKENS] ?? null));

        // Capa de contexto del LLM (ADR-0011): modelo de extracción + visión. La
        // visión arranca apagada (egress de binarios a un tercero); el modelo de
        // extracción vacío cae al del clasificador (lo resuelve la factoría).
        $settings->set(LlmSettings::EXTRACTION_MODEL, trim((string) ($params[LlmSettings::EXTRACTION_MODEL] ?? '')));
        $settings->set(LlmSettings::VISION_ENABLED, !empty($params[LlmSettings::VISION_ENABLED]));
        $maxImages = (int) ($params[LlmSettings::VISION_MAX_IMAGES] ?? 0);
        $settings->set(
            LlmSettings::VISION_MAX_IMAGES,
            $maxImages > 0 ? $maxImages : LlmSettings::DEFAULT_VISION_MAX_IMAGES
        );
        // Tope de envío de PDF a visión, separado del de parseo (TASK-025).
        $settings->set(
            LlmSettings::VISION_MAX_PDF_BYTES,
            LlmSettings::parseVisionMaxPdfBytes($params[LlmSettings::VISION_MAX_PDF_BYTES] ?? null)
        );

        // Clave API write-only: solo se sobrescribe si llega un valor no vacío.
        $apiKey = (string) ($params[LlmSettings::API_KEY] ?? '');
        if ('' !== trim($apiKey)) {
            $settings->set(LlmSettings::API_KEY, $apiKey);
        }
        return true;
    }
}
