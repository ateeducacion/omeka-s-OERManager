<?php

namespace OERManager;

use Laminas\EventManager\SharedEventManagerInterface;
use Laminas\Mvc\Controller\AbstractController;
use Laminas\Mvc\MvcEvent;
use Laminas\ServiceManager\ServiceLocatorInterface;
use Laminas\View\Renderer\PhpRenderer;
use Omeka\Module\AbstractModule;

/**
 * Módulo OERManager: gestión de un catálogo de Recursos Educativos Abiertos
 * (items lrmi:LearningResource).
 */
class Module extends AbstractModule
{
    public function getConfig()
    {
        return include __DIR__ . '/config/module.config.php';
    }

    public function onBootstrap(MvcEvent $event): void
    {
        parent::onBootstrap($event);
        // Permite el acceso a IndexController a editor y roles superiores.
        // global_admin y site_admin ya tienen allow global por el AclFactory
        // de Omeka; aquí se añade editor (NFR-003, RF-003, ADR-0005).
        // La matriz completa (reviewer, author, etc.) llega en TASK-004/PEND-007.
        $acl = $this->getServiceLocator()->get('Omeka\Acl');
        $acl->allow(['editor'], [Controller\Admin\IndexController::class]);
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
        // Punto de extensión (NFR-001: extender el core, nunca parchearlo).
        // Los listeners de negocio llegan en fases posteriores:
        // re-catalogador (TASK-004), integridad (TASK-005).
    }

    public function getConfigForm(PhpRenderer $renderer)
    {
        $form = $this->getServiceLocator()
            ->get('FormElementManager')
            ->get(Form\ConfigForm::class);
        return $renderer->formCollection($form);
    }

    public function handleConfigForm(AbstractController $controller)
    {
        // FASE 1: el formulario no persiste nada todavía.
        return true;
    }
}
