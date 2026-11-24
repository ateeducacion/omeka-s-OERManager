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
use OERManager\Service\Workflow\StatusGuard as StatusGuardAlias;
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
        $events->expects($this->exactly(6))->method("attach");
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

    /** @param bool $requiredFields whether the REA template has required properties */
    private function chips(array $query, bool $requiredFields = true): array
    {
        $resolver = $this->createMock(\OERManager\Service\IdConstraintResolver::class);
        $resolver->method('hasRequiredFields')->willReturn($requiredFields);
        $translator = new class {
            public function translate($text)
            {
                return $text;
            }
        };
        $event = new Event();
        $event->setParam('filters', []);
        $event->setParam('query', $query);
        $this->module([
            'Omeka\ApiManager' => $this->createMock(Manager::class),
            'MvcTranslator' => $translator,
            \OERManager\Service\IdConstraintResolver::class => $resolver,
        ])->addSearchFilters($event);
        return $event->getParam('filters');
    }

    /** TASK-070: the queue, required-field and age filters have readable chips. */
    public function testQueueRequiredAndAgeChips(): void
    {
        $filters = $this->chips(['status' => 'queue', 'missing' => ['required'], 'age' => '30']);

        $this->assertSame(['En cola'], $filters['Estado']);
        $this->assertSame(['Sin campo obligatorio'], $filters['Gobernanza']);
        $this->assertSame(['Creado hace más de 30 días'], $filters['Antigüedad']);
    }

    /** TASK-070 review: without a readable REA template the filter does nothing, so it gets no chip. */
    public function testRequiredChipNeedsATemplate(): void
    {
        $filters = $this->chips(['missing' => ['licence', 'required']], false);

        $this->assertSame(['Sin licencia'], $filters['Gobernanza']);
    }

    /** TASK-070 review: the age chip comes from MasterViewQuery::AGE_DAYS; other values filter nothing. */
    public function testAgeChipFollowsAgeDays(): void
    {
        foreach (\OERManager\Service\MasterViewQuery::AGE_DAYS as $days) {
            $this->assertSame(
                [sprintf('Creado hace más de %d días', $days)],
                $this->chips(['age' => (string) $days])['Antigüedad']
            );
        }
        foreach (['45', 'abc', '7x'] as $invalid) {
            $this->assertArrayNotHasKey('Antigüedad', $this->chips(['age' => $invalid]), $invalid);
        }
    }

    /** TASK-070: an array where a single value is expected (`age[]=7`) makes no chip and no warning. */
    public function testArrayValuedFiltersMakeNoChip(): void
    {
        $event = new Event();
        $event->setParam('filters', []);
        $event->setParam('query', ['age' => ['7'], 'status' => ['queue']]);
        $this->module(['Omeka\ApiManager' => $this->createMock(Manager::class)])->addSearchFilters($event);

        $this->assertSame([], $event->getParam('filters'));
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
        $module = $this->module([
            'Omeka\Acl' => $acl,
            WorkflowService::class => new WorkflowService($api, new \OERManager\Service\Curation\CurationWriter($api)),
            // TASK-039: a vocabulary for reject, none for discard.
            \OERManager\Service\Workflow\ReasonVocabularies::class => new \OERManager\Service\Workflow\ReasonVocabularies(
                new \OERManager\Service\Governance\VocabEntries(41, static fn (): array => [['value' => 'Otro motivo']]),
                new \OERManager\Service\Governance\VocabEntries(null, static fn (): array => [])
            ),
        ]);
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
        $args = end($view->partials)[1];
        $this->assertSame(['Otro motivo'], $args['rejectReasons']);
        $this->assertNull($args['discardReasons']);
        // Redesign of 2026-11-18: the button zone brings its layout and its
        // small script; no badge here (a private draft has no status).
        $this->assertSame(['OERManager/css/oer-workflow-actions.css'], $view->stylesheets);
        $this->assertSame(['OERManager/js/workflow-actions.js'], $view->scripts);
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

    /** A property or class entity with its term. */
    private function member(string $term): object
    {
        return new class ($term) {
            public function __construct(private string $t)
            {
            }
            public function getVocabulary()
            {
                return new class (explode(':', $this->t)[0]) {
                    public function __construct(private string $p)
                    {
                    }
                    public function getPrefix()
                    {
                        return $this->p;
                    }
                };
            }
            public function getLocalName()
            {
                return explode(':', $this->t)[1];
            }
        };
    }

    /** A hydrated Value entity: [term, text, public?, annotation values]. */
    private function valueEntity(string $term, string $text, bool $public = false, array $annotation = []): object
    {
        $annotationEntity = [] === $annotation ? null : new class ($annotation) {
            public function __construct(private array $values)
            {
            }
            public function getValues()
            {
                return $this->values;
            }
        };
        return new class ($this->member($term), $text, $public, $annotationEntity) {
            public function __construct(private $p, private string $v, private bool $pub, private $ann)
            {
            }
            public function getProperty()
            {
                return $this->p;
            }
            public function getType()
            {
                return 'literal';
            }
            public function getValue()
            {
                return $this->v;
            }
            public function getUri()
            {
                return null;
            }
            public function getLang()
            {
                return null;
            }
            public function getValueResource()
            {
                return null;
            }
            public function isPublic()
            {
                return $this->pub;
            }
            public function getValueAnnotation()
            {
                return $this->ann;
            }
        };
    }

    private function entity(?int $id, ?string $class, array $values): object
    {
        return new class ($id, null === $class ? null : $this->member($class), $values) {
            public function __construct(private ?int $id, private $class, private array $values)
            {
            }
            public function getId()
            {
                return $this->id;
            }
            public function getResourceClass()
            {
                return $this->class;
            }
            public function getValues()
            {
                return $this->values;
            }
        };
    }

    /** A database row of the guard's first query. */
    private static function row(string $class, ?string $term, ?string $text, bool $public = false, ?int $ann = null): array
    {
        return ['class' => $class, 'term' => $term, 'type' => null === $term ? null : 'literal', 'value' => $text,
            'uri' => null, 'lang' => null, 'value_resource_id' => null, 'is_public' => $public ? '1' : '0',
            'value_annotation_id' => $ann];
    }

    /**
     * Security reviews of TASK-067: a hand-made change of curation:status or
     * curation:note on a REA — text, visibility, annotation, class smuggling —
     * is a validation error, unless WorkflowService writes it or the user is
     * global_admin. The database rows are the «before».
     */
    public function testStatusGuardListenerRejectsHandMadeWorkflowValues(): void
    {
        $run = function (object $entity, array $dbRows, ?string $role, ?StatusGuardAlias $guard = null, array $annRows = []): array {
            $connection = new class ($dbRows, $annRows) {
                public array $calls = [];
                public function __construct(private array $rows, private array $annRows)
                {
                }
                public function fetchAllAssociative($sql, array $params = [])
                {
                    $this->calls[] = $params;
                    return 1 === count($this->calls) ? $this->rows : $this->annRows;
                }
            };
            $identity = null === $role ? null : new class ($role) {
                public function __construct(private string $role)
                {
                }
                public function getRole()
                {
                    return $this->role;
                }
            };
            $auth = new class ($identity) {
                public function __construct(private $identity)
                {
                }
                public function getIdentity()
                {
                    return $this->identity;
                }
            };
            $errors = new class {
                public array $errors = [];
                public function addError($key, $message)
                {
                    $this->errors[$key][] = $message;
                }
            };
            $module = $this->module([
                'Omeka\Connection' => $connection,
                'Omeka\AuthenticationService' => $auth,
                StatusGuardAlias::class => $guard ?? new StatusGuardAlias(),
            ]);
            $module->guardWorkflowValues(
                (new Event())->setParam('entity', $entity)->setParam('errorStore', $errors)
            );
            return $errors->errors;
        };
        $lr = 'lrmi:LearningResource';
        $status = fn (string $text, bool $public = false, array $ann = []) => $this->valueEntity('curation:status', $text, $public, $ann);
        $proposed = [self::row($lr, 'status', 'Propuesto')];

        // A proposer writes «Publicado» by hand: rejected.
        $this->assertArrayHasKey('curation:status', $run($this->entity(5, $lr, [$status('Publicado')]), $proposed, 'rea_proponente'));
        // The native form resubmits what it shows: unchanged passes.
        $this->assertSame([], $run($this->entity(5, $lr, [$status('Propuesto')]), $proposed, 'rea_proponente'));
        // Making the status public again is a change.
        $this->assertNotSame([], $run($this->entity(5, $lr, [$status('Propuesto', true)]), $proposed, 'rea_proponente'));
        // Forging who published: the annotation is compared too.
        $stamped = [self::row($lr, 'status', 'Publicado', false, 40)];
        $annRows = [['resource_id' => 40, 'term' => 'dcterms:contributor', 'type' => 'literal', 'value' => 'Cu',
            'uri' => null, 'lang' => null, 'value_resource_id' => null, 'is_public' => '0']];
        $same = [$status('Publicado', false, [$this->valueEntity('dcterms:contributor', 'Cu')])];
        $forged = [$status('Publicado', false, [$this->valueEntity('dcterms:contributor', 'Otro')])];
        $this->assertSame([], $run($this->entity(5, $lr, $same), $stamped, 'rea_proponente', null, $annRows));
        $this->assertNotSame([], $run($this->entity(5, $lr, $forged), $stamped, 'rea_proponente', null, $annRows));
        // global_admin (initial import) passes.
        $this->assertSame([], $run($this->entity(5, $lr, [$status('Publicado')]), $proposed, 'global_admin'));
        // A REA created with a status by hand, or an item turned into a REA with one: rejected.
        $this->assertNotSame([], $run($this->entity(null, $lr, [$status('Publicado')]), [], 'rea_curador'));
        $this->assertNotSame([], $run(
            $this->entity(6, $lr, [$status('Publicado')]),
            [self::row('dctype:Text', 'status', 'Publicado')],
            'rea_curador'
        ));
        // A note by hand on a REA leaving the class is still a change to a REA.
        $this->assertNotSame([], $run(
            $this->entity(5, 'dctype:Text', [$status('Propuesto'), $this->valueEntity('curation:note', 'x')]),
            $proposed,
            'rea_curador'
        ));
        // Items that are not and were not REA are not guarded.
        $this->assertSame([], $run(
            $this->entity(7, 'dctype:Text', [$status('Draft')]),
            [self::row('dctype:Text', null, null)],
            'editor'
        ));
        // WorkflowService's own write passes.
        $guard = new StatusGuardAlias();
        $errors = $guard->run(fn () => $run($this->entity(5, $lr, [$status('Publicado')]), $proposed, 'rea_curador', $guard));
        $this->assertSame([], $errors);
    }

    /**
     * «Cola (N)» (ADR-0023 §3): set on admin, non-AJAX pages for users who
     * hold the master view; nobody else pays the count.
     */
    public function testQueueCounterLabelsTheNavigationForCuratorsOnly(): void
    {
        $page = new class {
            public ?string $label = null;
            public function setLabel($label)
            {
                $this->label = $label;
            }
        };
        $nav = new class ($page) {
            public array $asked = [];
            public function __construct(private $page)
            {
            }
            public function findOneBy($property, $value)
            {
                $this->asked[] = [$property, $value];
                return $this->page;
            }
        };
        $counter = $this->createMock(\OERManager\Service\Workflow\QueueCounter::class);
        $counter->method('count')->willReturn(7);
        $translator = new class {
            public function translate($text)
            {
                return $text;
            }
        };
        $event = static fn (bool $admin, bool $ajax) => new class ($admin, $ajax) extends \Laminas\Mvc\MvcEvent {
            public function __construct(private bool $admin, private bool $ajax)
            {
            }
            public function getRouteMatch()
            {
                $admin = $this->admin;
                return new class ($admin) {
                    public function __construct(private bool $admin)
                    {
                    }
                    public function getParam($name)
                    {
                        return '__ADMIN__' === $name ? $this->admin : null;
                    }
                };
            }
            public function getRequest()
            {
                $ajax = $this->ajax;
                return new class ($ajax) {
                    public function __construct(private bool $ajax)
                    {
                    }
                    public function isXmlHttpRequest()
                    {
                        return $this->ajax;
                    }
                };
            }
        };
        foreach ([[true, false, 'rea_curador', 'Cola (7)'], [false, false, 'rea_curador', null],
            [true, true, 'rea_curador', null], [true, false, 'rea_proponente', null]] as [$admin, $ajax, $role, $label]) {
            $page->label = null;
            $acl = \Omeka\Permissions\Acl::core();
            $acl->addRole('site_editor', 'editor');
            \OERManager\Permissions\ReaAcl::register($acl);
            $acl->identityRole = $role;
            $module = $this->module([
                'Omeka\Acl' => $acl,
                'Laminas\Navigation\AdminModule' => $nav,
                \OERManager\Service\Workflow\QueueCounter::class => $counter,
                'MvcTranslator' => $translator,
            ]);
            $module->labelQueue($event($admin, $ajax));
            $this->assertSame($label, $page->label, $role . ($admin ? ' admin' : '') . ($ajax ? ' ajax' : ''));
        }
        $this->assertSame(['id', 'oer-queue'], $nav->asked[0]);
    }
}
