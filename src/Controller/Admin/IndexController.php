<?php

namespace OERManager\Controller\Admin;

use Laminas\Mvc\Controller\AbstractActionController;
use Laminas\View\Model\ViewModel;

/**
 * Stub del controlador de la vista maestra (TASK-003).
 * FASE 1: solo renderiza la plantilla de aterrizaje del módulo.
 */
class IndexController extends AbstractActionController
{
    public function indexAction()
    {
        $view = new ViewModel();
        $view->setTemplate('oer-manager/admin/index/index');
        return $view;
    }
}
