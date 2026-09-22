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
        $acl = $this->createMock(\Omeka\Permissions\Acl::class);
        $calls = [];
        $acl->method('allow')->willReturnCallback(static function ($roles, $resources, $privileges) use (&$calls) {
            $calls[] = [$roles, $resources, $privileges];
        });
        $module = $this->module(['Omeka\Acl' => $acl]);
        $module->init($this->createMock(\Laminas\ModuleManager\ModuleManagerInterface::class));
        $module->onBootstrap(new \Laminas\Mvc\MvcEvent());
        $this->assertCount(4, $calls);
        $this->assertSame(['site_admin'], $calls[2][0]);
        $this->assertSame(['config'], $calls[2][2]);
        $this->assertContains('author', $calls[1][0]);
        $events = $this->createMock(\Laminas\EventManager\SharedEventManagerInterface::class);
        $events->expects($this->exactly(5))->method('attach');
        $module->attachListeners($events);
        $module->install($module->getServiceLocator());
        $module->uninstall($module->getServiceLocator());
        $module->upgrade('1', '2', $module->getServiceLocator());
        $this->assertIsArray($module->getConfig());
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
        $module = $this->module(['Omeka\Acl' => $acl, WorkflowService::class => new WorkflowService($api)]);
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
    }
}
