<?php

namespace OERManager\Test;

use OERManager\Module;
use OERManager\Form\ConfigForm;
use OERManager\Service\IntegrityChecker;
use OERManager\Service\IntegrityResult;
use OERManager\Service\Workflow\WorkflowService;
use Omeka\Api\Manager;
use Omeka\Api\Response;
use Omeka\Api\Representation\ItemRepresentation;
use Omeka\Api\Representation\ResourceClassRepresentation;
use Laminas\EventManager\Event;
use PHPUnit\Framework\TestCase;

class ModuleRuntimeTest extends TestCase
{
    private function module(array $map): Module
    {
        $module = new Module();
        $services = $this->createMock(\Laminas\ServiceManager\ServiceLocatorInterface::class);
        $services->method('get')->willReturnCallback(static fn ($name) => $map[$name]);
        $module->setServiceLocator($services);
        return $module;
    }

    public function testLifecycleAclAndListenerContracts(): void
    {
        // TASK-067: the bootstrap adds the REA roles under IsolatedSites'
        // site_editor and grants the module only to them (matrix: ReaAclTest).
        $acl = \Omeka\Permissions\Acl::core();
        $acl->addRole('site_editor', 'editor');
        $module = $this->module(['Omeka\Acl' => $acl]);
        $module->init($this->createMock(\Laminas\ModuleManager\ModuleManagerInterface::class));
        $module->onBootstrap(new \Laminas\Mvc\MvcEvent());
        $index = \OERManager\Controller\Admin\IndexController::class;
        $this->assertTrue($acl->inheritsRole('rea_curador', 'site_editor', true));
        $this->assertTrue($acl->isAllowed('rea_curador', $index, 'recatalog-apply'));
        $this->assertTrue($acl->isAllowed('rea_proponente', $index, 'propose'));
        foreach (['editor', 'site_editor', 'site_admin', 'reviewer', 'author'] as $role) {
            $this->assertFalse($acl->isAllowed($role, $index, 'index'), $role);
            $this->assertFalse($acl->isAllowed($role, $index, 'propose'), $role);
        }
        $this->assertFalse($acl->isAllowed('rea_curador', $index, 'config'));
        $this->assertTrue($acl->isAllowed('global_admin', $index, 'config'));
        $events = $this->createMock(\Laminas\EventManager\SharedEventManagerInterface::class);
        $events->expects($this->exactly(5))->method('attach');
        $module->attachListeners($events);
        $module->install($module->getServiceLocator());
        $this->assertIsArray($module->getConfig());
    }

    /**
     * ADR-0023 §1.6: on uninstall the users of a REA role go back to its
     * parent, so nobody keeps a role the ACL will no longer know.
     */
    public function testUninstallMovesReaUsersBackToTheParentRole(): void
    {
        foreach ([true => ['site_editor', 'site_editor'], false => ['author', 'editor']] as $isolated => $targets) {
            $acl = \Omeka\Permissions\Acl::core();
            if ($isolated) {
                $acl->addRole('site_editor', 'editor');
            }
            $connection = new class {
                public array $statements = [];
                public function executeStatement($sql, array $params = [])
                {
                    $this->statements[] = [$sql, $params];
                    return 1;
                }
            };
            $module = $this->module(['Omeka\Acl' => $acl, 'Omeka\Connection' => $connection]);
            $module->uninstall($module->getServiceLocator());
            $this->assertSame([
                ['UPDATE user SET role = ? WHERE role = ?', [$targets[0], 'rea_proponente']],
                ['UPDATE user SET role = ? WHERE role = ?', [$targets[1], 'rea_curador']],
            ], $connection->statements);
        }
    }

    /** TASK-064 (ADR-0022): the upgrade removes the settings of the ADR-0009 graph, and only those. */
    public function testUpgradeDeletesTheLegacyCurriculumSettings(): void
    {
        $settings = new \Omeka\Settings\Settings();
        foreach (\OERManager\Service\ConfigPayload::LEGACY_SETTINGS as $legacy) {
            $settings->set($legacy, 'Curso');
        }
        $settings->set('oermanager_axis_termset_item_id', 9);
        $module = $this->module(['Omeka\Settings' => $settings]);
        $module->upgrade('0.9.0', '1.0.0', $module->getServiceLocator());
        $this->assertSame(['oermanager_axis_termset_item_id' => 9], $settings->values);
        $module->upgrade('1.0.0', '1.0.1', $module->getServiceLocator());
        $this->assertSame(['oermanager_axis_termset_item_id' => 9], $settings->values);
    }

    public function testSearchFilterChipsResolveTitlesAndKeepMissingIds(): void
    {
        $api = $this->createMock(Manager::class);
        $item = $this->createMock(ItemRepresentation::class);
        $item->method('displayTitle')->willReturn('Course');
        $api->method('read')->willReturnCallback(static function ($resource, $id) use ($item) {
            if ($id === 2) {
                throw new \RuntimeException();
            }
            return new Response($item);
        });
        $event = new Event();
        $event->setParam('filters', ['Existing' => ['keep']]);
        $event->setParam('query', ['title' => 'Title', 'visibility' => 'public', 'stage' => 1, 'subject' => 2,
            'missing' => ['licence', 'invalid', []], 'integrity' => 'warning']);
        $this->module(['Omeka\ApiManager' => $api])->addSearchFilters($event);
        $filters = $event->getParam('filters');
        $this->assertSame(['keep'], $filters['Existing']);
        $this->assertSame(['Course'], $filters['Etapa']);
        $this->assertSame(['2'], $filters['Materia']);
        $this->assertSame(['Sin licencia'], $filters['Gobernanza']);
        $this->assertSame(['Con incidencias'], $filters['Integridad']);
    }

    public function testConfigurationFieldsAreOptionalExceptCsrf(): void
    {
        $form = new ConfigForm();
        $form->init();
        $this->assertArrayHasKey('csrf', $form->getElements());
        $spec = $form->getInputFilterSpecification();
        $this->assertArrayNotHasKey('csrf', $spec);
        $this->assertGreaterThan(10, count($spec));
        foreach ($spec as $field) {
            $this->assertFalse($field['required']);
        }
    }

    public function testBrowseSettingsAreAddedToUserSettingsFieldset(): void
    {
        $fieldset = new class {
            public array $elements = [];
            public function add($element)
            {
                $this->elements[$element['name']] = $element;
            }
        };
        $form = new class ($fieldset) {
            public function __construct(private $fieldset)
            {
            }
            public function getOption($name)
            {
                return 7;
            }
            public function get($name)
            {
                TestCase::assertSame('user-settings', $name);
                return $this->fieldset;
            }
        };
        $this->module([])->addBrowseConfigElements((new Event())->setTarget($form));
        $this->assertCount(2, $fieldset->elements);
        $this->assertSame(7, $fieldset->elements['columns_admin_oer_items']['options']['columns_user_id']);
    }

    public function testSaveListenerOnlyLogsLearningResourceIssues(): void
    {
        $item = $this->createMock(ItemRepresentation::class);
        $item->method('resourceClass')->willReturn(new ResourceClassRepresentation());
        $item->method('id')->willReturn(7);
        $checker = $this->createMock(IntegrityChecker::class);
        $checker->method('check')->willReturn(new IntegrityResult([
            ['severity' => 'warning', 'code' => 'missing', 'message' => 'Missing title', 'field' => 'title'],
        ]));
        $logger = $this->createMock(\Laminas\Log\LoggerInterface::class);
        $logger->expects($this->once())->method('warn')->with($this->stringContains('item 7'));
        $module = $this->module([IntegrityChecker::class => $checker, 'Omeka\Logger' => $logger]);
        foreach ([null, new ItemRepresentation(), $item] as $content) {
            $module->handleItemPostSave((new Event())->setParam('response', new Response($content)));
        }
    }

    public function testWorkflowActionsRenderOnlyEditableLearningResources(): void
    {
        $api = $this->createMock(Manager::class);
        $acl = $this->createMock(\Omeka\Permissions\Acl::class);
        $acl->method('userIsAllowed')->willReturn(true);
        $module = $this->module(['Omeka\Acl' => $acl, WorkflowService::class => new WorkflowService($api, new \OERManager\Service\Curation\CurationWriter($api))]);
        $view = new \Laminas\View\Renderer\PhpRenderer();
        $event = (new Event())->setTarget($view);
        ob_start();
        $module->addWorkflowActions($event);
        $event->setParam('resource', new ItemRepresentation());
        $module->addWorkflowActions($event);
        $this->assertSame('', ob_get_clean());
        $item = $this->createMock(ItemRepresentation::class);
        $item->method('resourceClass')->willReturn(new ResourceClassRepresentation());
        $item->method('userIsAllowed')->willReturn(true);
        $event->setParam('resource', $item);
        ob_start();
        $module->addWorkflowActions($event);
        $this->assertSame('oer-manager/common/workflow-actions', ob_get_clean());
        $this->assertSame([], $view->stylesheets);
    }

    /**
     * RF-017: el autor de un REA ya propuesto no tiene ninguna acción, pero
     * tiene que ver la insignia; y el rechazado lleva la suya.
     */
    public function testWorkflowBadgeRendersEvenWithoutActions(): void
    {
        $acl = $this->createMock(\Omeka\Permissions\Acl::class);
        $acl->method('userIsAllowed')->willReturn(false);
        $module = $this->module([
            'Omeka\Acl' => $acl,
            WorkflowService::class => new WorkflowService($api = $this->createMock(Manager::class), new \OERManager\Service\Curation\CurationWriter($api)),
        ]);
        foreach (['Propuesto' => 'proposed', ' Rechazado ' => 'rejected'] as $raw => $badge) {
            $value = $this->createMock(\Omeka\Api\Representation\ValueRepresentation::class);
            $value->method('value')->willReturn($raw);
            $item = $this->createMock(ItemRepresentation::class);
            $item->method('resourceClass')->willReturn(new ResourceClassRepresentation());
            $item->method('userIsAllowed')->willReturn(false);
            $item->method('value')->willReturn($value);
            $view = new \Laminas\View\Renderer\PhpRenderer();
            ob_start();
            $module->addWorkflowActions((new Event())->setTarget($view)->setParam('resource', $item));
            $this->assertSame('oer-manager/common/workflow-badge[' . $badge . ']', ob_get_clean());
            $this->assertSame(['OERManager/css/oer-workflow-badge.css'], $view->stylesheets);
        }
    }
}
