<?php

namespace OERManager;

use Laminas\EventManager\Event;
use Laminas\EventManager\SharedEventManagerInterface;
use Laminas\Mvc\Controller\AbstractController;
use Laminas\Mvc\MvcEvent;
use Laminas\ServiceManager\ServiceLocatorInterface;
use Laminas\View\Renderer\PhpRenderer;
use OERManager\Service\CurriculumSearch;
use OERManager\Service\Llm\LlmSettings;
use Omeka\Api\Representation\ItemRepresentation;
use Omeka\Module\AbstractModule;

/**
 * Módulo OERManager: gestión de un catálogo de Recursos Educativos Abiertos
 * (items lrmi:LearningResource). Vista maestra (TASK-003), integridad
 * (TASK-005) y re-catalogador curricular/tags (TASK-004).
 */
class Module extends AbstractModule
{
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
        // Capa de contexto del LLM (ADR-0011): extracción/visión.
        $data[LlmSettings::EXTRACTION_MODEL] = $settings->get(LlmSettings::EXTRACTION_MODEL);
        $data[LlmSettings::VISION_ENABLED] = (bool) $settings->get(LlmSettings::VISION_ENABLED);
        $data[LlmSettings::VISION_MAX_IMAGES] = $settings->get(
            LlmSettings::VISION_MAX_IMAGES,
            LlmSettings::DEFAULT_VISION_MAX_IMAGES
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

        // Clave API write-only: solo se sobrescribe si llega un valor no vacío.
        $apiKey = (string) ($params[LlmSettings::API_KEY] ?? '');
        if ('' !== trim($apiKey)) {
            $settings->set(LlmSettings::API_KEY, $apiKey);
        }
        return true;
    }
}
