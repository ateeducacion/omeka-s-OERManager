<?php

declare(strict_types=1);

namespace OERManager\Controller\Admin;

use Laminas\Mvc\Controller\AbstractActionController;

/**
 * TASK-077: the guided wizard to add a REA, reached from «Mis propuestas»
 * (spec `2026-11-26-task-077-rea-add-wizard-design.md`).
 */
class ReaWizardController extends AbstractActionController
{
    public const ROUTE = 'admin/oer-manager-wizard';

    /** Every action of this controller; ReaAcl grants them to both REA roles. */
    public const PRIVILEGES = ['index', 'upload', 'duplicates', 'save'];

    public function indexAction()
    {
    }

    public function uploadAction()
    {
    }

    public function duplicatesAction()
    {
    }

    public function saveAction()
    {
    }
}
