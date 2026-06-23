<?php

namespace OERManager;

use Laminas\EventManager\Event;
use Laminas\EventManager\SharedEventManagerInterface;
use Laminas\Mvc\Controller\AbstractController;
use Laminas\Mvc\MvcEvent;
use Laminas\ServiceManager\ServiceLocatorInterface;
use Laminas\View\Renderer\PhpRenderer;
use OERManager\Service\CurriculumSearch;
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
     * rol editor o superior. El proyecto (schema:isPartOf) no se toca aquí: es
     * acción de gestor aparte, reservada a admin (ADR-0004), y vive fuera de
     * este controlador. El global_admin ya tiene todos los privilegios.
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
        return true;
    }
}
