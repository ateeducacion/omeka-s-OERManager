<?php

namespace OERManager\Test\Controller;

use OERManager\Controller\Admin\IndexController;
use OERManager\Service\Ai\AiCataloguer;
use OERManager\Service\Ai\ContextDistiller;
use OERManager\Service\Ai\PromptBuilder;
use OERManager\Service\Ai\ProposalStore;
use OERManager\Service\Ai\EvaluationScorer;
use OERManager\Service\Content\ContentExtractor;
use OERManager\Service\Content\MediaVisionExtractor;
use OERManager\Service\Curation\UndoRouter;
use OERManager\Service\IntegrityResult;
use OERManager\Service\Llm\LlmSettings;
use OERManager\Service\Workflow\WorkflowService;
use OERManager\Service\Workflow\WorkflowStatus;
use OERManager\Test\Service\Ai\FakeClassifier;
use OERManager\Test\Service\Ai\FakeLlmClient;
use Omeka\Api\Manager;
use Omeka\Api\Response;
use Omeka\Api\Representation\ItemRepresentation;
use Omeka\Api\Representation\ValueRepresentation;
use Omeka\Permissions\Exception\PermissionDeniedException;
use Omeka\Settings\Settings;
use PHPUnit\Framework\TestCase;

class IndexControllerTest extends TestCase
{
    private const CURRICULUM_EVENT = [
        'when' => 'today', 'contributor' => 'Curator', 'summary' => 'Changed',
        'payload' => ['v' => 1, 'op' => 'recatalog', 'undoOf' => null, 'terms' => ['lrmi:teaches' => []]],
    ];

    private IndexController $controller;
    private array $dependencies;
    private $params;
    private $request;
    private $api;
    private $item;
    private $messages;
    private string $dir;
    private string $workflowStatus = '';
    private bool $denyWrite = false;
    private bool $missingItem = false;
    private array $issues = [];

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/oer_controller_' . bin2hex(random_bytes(6));
        $this->api = $this->createMock(Manager::class);
        $this->item = $this->createMock(ItemRepresentation::class);
        $this->item->method('id')->willReturn(7);
        $this->item->method('displayTitle')->willReturn('Course');
        $this->item->method('value')->willReturnCallback(function ($term, $options = []) {
            if ($term === WorkflowStatus::STATUS_TERM && $this->workflowStatus !== '') {
                $value = $this->createMock(ValueRepresentation::class);
                $value->method('value')->willReturn($this->workflowStatus);
                return $value;
            }
            return $options['default'] ?? null;
        });
        $this->item->method('values')->willReturn([]);
        $this->api->method('read')->willReturnCallback(function ($resource) {
            if ($this->missingItem) {
                throw new \RuntimeException('not readable');
            }
            if ($resource === 'jobs') {
                return new Response(new class {
                    public function status()
                    {
                        return 'completed';
                    }
                });
            }
            return new Response($this->item);
        });
        $this->api->method('search')->willReturn(new Response([$this->item], 1));
        $this->api->method('update')->willReturnCallback(function () {
            if ($this->denyWrite) {
                throw new PermissionDeniedException('private');
            }
            return new Response($this->item);
        });
        $prompt = new PromptBuilder();
        $cataloguer = new AiCataloguer(
            new ContentExtractor(),
            new MediaVisionExtractor(new FakeLlmClient(), $prompt, false),
            new ContextDistiller(new FakeLlmClient(['Summary']), $prompt),
            new FakeClassifier([]),
            new FakeClassifier([])
        );
        $real = [
            'aiCataloguer' => $cataloguer,
            'settings' => new Settings(),
            'scorer' => new EvaluationScorer(),
            'proposalStore' => new ProposalStore($this->dir),
            'workflowService' => new WorkflowService($this->api),
            'computedFilter' => new \OERManager\Service\ComputedFilter(),
        ];
        $arguments = [];
        foreach ((new \ReflectionClass(IndexController::class))->getConstructor()->getParameters() as $param) {
            $name = $param->getName();
            $type = $param->getType()->getName();
            // UndoRouter is final: route over the same RecatalogService double
            // the tests stub, so recatalog-undo still reaches it.
            $arguments[] = $this->dependencies[$name] = $real[$name] ?? (UndoRouter::class === $type
                ? new UndoRouter($this->dependencies['recatalogService'], $this->dependencies['governanceService'])
                : $this->createMock($type));
        }
        $this->dependencies['integrityChecker']->method('check')
            ->willReturnCallback(fn () => new IntegrityResult($this->issues));
        $this->dependencies['masterViewQuery']->method('buildSearchParams')->willReturn([]);
        $this->dependencies['resourceTypeVocab']->method('values')->willReturn([]);
        $this->dependencies['mediaSource']->method('filesFor')->willReturn([]);
        $this->dependencies['mediaSource']->method('imagesFor')->willReturn([]);
        $this->controller = new IndexController(...$arguments);
        $this->params = new class {
            public array $post = ['id' => 7, 'csrf' => 'valid', 'reason' => 'Revise'];
            public array $query = [];
            public function fromPost($key = null, $default = null)
            {
                return $key === null ? $this->post : ($this->post[$key] ?? $default);
            }
            public function fromQuery($key = null, $default = null)
            {
                return $key === null ? $this->query : ($this->query[$key] ?? $default);
            }
        };
        $this->request = new class {
            public bool $post = true;
            public bool $ajax = true;
            public function isPost()
            {
                return $this->post;
            }
            public function isXmlHttpRequest()
            {
                return $this->ajax;
            }
        };
        $this->messages = new class {
            public array $messages = [];
            public function addError($message)
            {
                $this->messages[] = $message;
            }
            public function addSuccess($message)
            {
                $this->messages[] = $message;
            }
            public function addFormErrors($form)
            {
                $this->messages[] = 'invalid form';
            }
        };
        $route = new class {
            public function toRoute($route, $params = [])
            {
                return [$route, $params];
            }
            public function fromRoute($route, $params = [])
            {
                return $route . '/' . $params['action'];
            }
        };
        $this->controller->plugins = [
            'api' => $this->api, 'params' => $this->params, 'request' => $this->request,
            'response' => new \Laminas\Http\Response(), 'identity' => null,
            'redirect' => $route, 'url' => $route, 'messenger' => $this->messages,
            'browse' => new class {
                public function setDefaults($type)
                {
                }
            }, 'paginator' => null,
        ];
    }

    protected function tearDown(): void
    {
        foreach (glob($this->dir . '/*') ?: [] as $file) {
            unlink($file);
        }
        if (is_dir($this->dir)) {
            rmdir($this->dir);
        }
    }

    private function data(string $action): array
    {
        return $this->controller->{$action . 'Action'}()->getVariables();
    }

    public function testMutationsRejectGetAndInvalidCsrf(): void
    {
        foreach (
            ['setVisibility', 'recatalogPreview', 'recatalogApply', 'recatalogLastEvent',
            'recatalogUndo', 'aiPropose', 'propose', 'rejectProposal', 'publishProposal'] as $action
        ) {
            $this->request->post = false;
            $this->assertSame(['admin/oer-manager', []], $this->controller->{$action . 'Action'}());
        }
        foreach (['aiProposeStatus', 'aiProposeCancel'] as $action) {
            $this->assertSame('method', $this->data($action)['error']);
        }
        $this->request->post = true;
        $this->params->post['csrf'] = 'invalid';
        foreach (
            ['recatalogApply', 'recatalogUndo', 'aiPropose', 'aiProposeStatus', 'aiProposeCancel',
            'propose', 'rejectProposal', 'publishProposal'] as $action
        ) {
            $this->assertSame('csrf', $this->data($action)['error']);
        }
        $this->assertSame('Invalid CSRF token', $this->data('setVisibility')['error']);
    }

    public function testWorkflowActionsReturnAjaxAndHtmlOutcomes(): void
    {
        foreach ([true, false] as $ajax) {
            $this->request->ajax = $ajax;
            foreach (
                [
                'propose' => '',
                'rejectProposal' => WorkflowStatus::PROPOSED,
                'publishProposal' => WorkflowStatus::PROPOSED,
                ] as $action => $status
            ) {
                $this->workflowStatus = $status;
                $result = $this->controller->{$action . 'Action'}();
                if ($ajax) {
                    $this->assertTrue($result->getVariable('updated'));
                } else {
                    $this->assertSame('admin/id', $result[0]);
                }
                $this->denyWrite = true;
                $result = $this->controller->{$action . 'Action'}();
                if ($ajax) {
                    $this->assertSame('denied', $result->getVariable('error'));
                } else {
                    $this->assertSame('admin/id', $result[0]);
                }
                $this->denyWrite = false;
                $this->missingItem = true;
                $result = $this->controller->{$action . 'Action'}();
                $this->assertSame(
                    $ajax ? 'not_found' : 'admin/oer-manager',
                    $ajax ? $result->getVariable('error') : $result[0]
                );
                $this->missingItem = false;
                $this->params->post['csrf'] = 'bad';
                $result = $this->controller->{$action . 'Action'}();
                $this->assertSame($ajax ? 'csrf' : 'admin/id', $ajax ? $result->getVariable('error') : $result[0]);
                $this->params->post['csrf'] = 'valid';
            }
            $this->params->post['reason'] = '';
            $result = $this->controller->rejectProposalAction();
            $this->assertSame(
                $ajax ? 'reason_required' : 'admin/id',
                $ajax ? $result->getVariable('error') : $result[0]
            );
            $this->params->post['reason'] = 'Revise';
        }
    }

    public function testBrowseSearchAndComputedFilters(): void
    {
        $view = $this->controller->indexAction();
        $this->assertSame([$this->item], $view->getVariable('items'));
        $this->assertFalse($view->getVariable('aiEnabled'));
        $this->params->query = ['integrity' => 'ok', 'alignment' => 'partial'];
        $this->assertSame([], $this->controller->indexAction()->getVariable('items'));
        $this->params->query = ['integrity' => 'ok'];
        $this->assertSame([$this->item], $this->controller->indexAction()->getVariable('items'));
        $this->params->query = ['stage' => 1, 'subject' => 2];
        $this->assertSame(
            ['stage' => 'Course', 'subject' => 'Course'],
            $this->controller->searchAction()->getVariable('resourceFilterTitles')
        );
        $this->missingItem = true;
        $this->assertSame([], $this->controller->searchAction()->getVariable('resourceFilterTitles'));
    }

    public function testVisibilityAndTermSearch(): void
    {
        $session = new \Laminas\Session\Container('OERManager');
        $session->visibilityCsrfToken = 'visibility';
        $this->params->post = [
            'resource_ids' => [7, 7, '0'], 'oer_visibility_csrf' => 'visibility', 'is_public' => '1',
        ];
        $this->assertSame([7], $this->data('setVisibility')['updated']);
        $this->denyWrite = true;
        $this->assertSame([7], $this->data('setVisibility')['denied']);
        foreach (
            [
            'etapa' => 'searchEtapas',
            'dcterms:relation' => 'searchAxes',
            'schema:about' => 'searchDimension',
            ] as $dimension => $method
        ) {
            $this->dependencies['curriculumSearch']->method($method)->willReturn([['id' => 7]]);
            $this->params->query = ['dimension' => $dimension, 'q' => 'course'];
            $this->assertSame([['id' => 7]], $this->data('searchTerms')['results']);
        }
    }

    public function testRecatalogPayloadAndExceptionMapping(): void
    {
        $this->params->post['alignment'] = ['lrmi:teaches' => [1], 'unknown' => [2]];
        $this->params->post['justification'] = ['lrmi:teaches' => [1 => str_repeat('x', 220), -1 => 'discard']];
        $this->dependencies['recatalogService']->expects($this->once())
            ->method('preview')->with(7, ['lrmi:teaches' => [1]])->willReturn(['preview']);
        $this->assertSame(['preview'], $this->data('recatalogPreview')['diff']);
        $this->dependencies['recatalogService']->method('apply')->willReturn(['updated' => true]);
        $this->dependencies['recatalogService']->method('lastEvent')->willReturn(self::CURRICULUM_EVENT);
        $this->dependencies['recatalogService']->method('undo')->willReturn(['updated' => true]);
        $this->assertTrue($this->data('recatalogApply')['updated']);
        $this->assertTrue($this->data('recatalogUndo')['updated']);
    }

    public function testRecatalogErrorsAreSanitized(): void
    {
        $this->dependencies['recatalogService']->method('lastEvent')->willReturn(self::CURRICULUM_EVENT);
        foreach (['apply' => 'recatalogApply', 'undo' => 'recatalogUndo'] as $method => $action) {
            $errors = [
                new PermissionDeniedException(), new \RuntimeException('invalid targets'), new \Exception('secret'),
            ];
            $this->dependencies['recatalogService']->method($method)->willReturnCallback(function () use (&$errors) {
                throw array_shift($errors);
            });
            foreach (['denied', 'invalid targets', 'unexpected'] as $error) {
                $this->assertSame($error, $this->data($action)['error']);
            }
        }
    }

    public function testHistoryOmitsPrivateEventPayload(): void
    {
        $this->dependencies['recatalogService']->method('lastEvent')->willReturn([
            'when' => 'today', 'contributor' => 'Curator', 'summary' => 'Changed', 'payload' => 'private',
        ]);
        $this->assertSame(
            ['when' => 'today', 'contributor' => 'Curator', 'summary' => 'Changed'],
            $this->data('recatalogLastEvent')['event']
        );
        $this->assertNull($this->data('drawerHistory')['history']);
        $this->params->query = ['id' => 7];
        $this->dependencies['recatalogService']->method('history')->willReturn([['summary' => 'Changed']]);
        $this->assertSame([['summary' => 'Changed']], $this->data('drawerHistory')['history']);
        $this->missingItem = true;
        $this->assertNull($this->data('drawerHistory')['history']);
    }

    public function testAiGuardsDispatchPollingAndCancellation(): void
    {
        $this->assertSame('disabled', $this->data('aiPropose')['error']);
        $settings = $this->dependencies['settings'];
        $settings->set(LlmSettings::ENABLED, true);
        $this->assertSame('disabled', $this->data('aiPropose')['error']);
        $settings->set(LlmSettings::MODEL, 'model');
        $settings->set(LlmSettings::PROVIDER, LlmSettings::PROVIDER_OPENAI);
        $this->assertSame('disabled', $this->data('aiPropose')['error']);
        $settings->set(LlmSettings::BASE_URL, 'https://example.test');
        $this->params->post['id'] = 0;
        $this->assertSame('id', $this->data('aiPropose')['error']);
        $this->params->post['id'] = 7;
        $this->missingItem = true;
        $this->assertSame('not_found', $this->data('aiPropose')['error']);
        $this->missingItem = false;
        $this->dependencies['jobDispatcher']->method('dispatch')->willReturn(new \Omeka\Entity\Job());
        $this->assertSame(1, $this->data('aiPropose')['jobId']);
        foreach (['aiProposeStatus', 'aiProposeCancel'] as $action) {
            $this->assertSame('id', $this->data($action)['error']);
            $this->params->post['jobId'] = 7;
            $this->missingItem = true;
            $this->assertSame('not_found', $this->data($action)['error']);
            $this->missingItem = false;
            if ($action === 'aiProposeStatus') {
                $this->assertSame('in_progress', $this->data($action)['status']);
                $this->dependencies['proposalStore']->write(7, ['status' => 'in_progress']);
                $this->assertSame('job_died', $this->data($action)['code']);
                $this->dependencies['proposalStore']->write(7, ['status' => 'completed']);
                $this->assertSame('completed', $this->data($action)['status']);
            } else {
                $this->assertTrue($this->data($action)['stopped']);
            }
            unset($this->params->post['jobId']);
        }
    }

    public function testEvaluationRequiresConfiguredAiAndItemIds(): void
    {
        $this->assertSame('disabled', $this->data('aiEvaluate')['error']);
        $this->dependencies['settings']->set(LlmSettings::ENABLED, true);
        $this->dependencies['settings']->set(LlmSettings::MODEL, 'model');
        $this->assertSame('ids', $this->data('aiEvaluate')['error']);
        $this->params->query = ['ids' => '7,7,0'];
        $result = $this->data('aiEvaluate');
        $this->assertSame(1, $result['evaluated']);
        $this->assertCount(5, $result['summary']);
    }
    public function testConfigGetAndValidAndInvalidSubmissions(): void
    {
        $form = $this->getMockBuilder(\OERManager\Form\ConfigForm::class)->onlyMethods(['isValid'])->getMock();
        $form->init();
        $valid = true;
        $form->method('isValid')->willReturnCallback(static function () use (&$valid) {
            return $valid;
        });
        $this->dependencies['formElementManager']->method('get')->willReturn($form);
        $this->request->post = false;
        $this->assertSame($form, $this->controller->configAction()->getVariable('form'));
        $this->request->post = true;
        $this->params->post = [];
        $this->assertSame(['admin/oer-manager', ['action' => 'config']], $this->controller->configAction());
        $valid = false;
        $this->assertSame($form, $this->controller->configAction()->getVariable('form'));
        $this->assertContains('invalid form', $this->messages->messages);
    }

    public function testDrawerDetailsHandlesUnavailableAndReadableItems(): void
    {
        $this->assertNull($this->controller->drawerDetailsAction()->getVariable('panel'));
        $this->params->query = ['id' => 7];
        $this->workflowStatus = WorkflowStatus::PROPOSED;
        $this->dependencies['itemPanelData']->method('forItem')->willReturn([]);
        $this->dependencies['acl']->method('userIsAllowed')->willReturn(true);
        $view = $this->controller->drawerDetailsAction();
        $this->assertSame('ok', $view->getVariable('rail'));
        $this->assertTrue($view->getVariable('canPublish'));
        $this->assertTrue($view->getVariable('canReject'));
    }

    public function testPublicationRequiresIntegrityAndValidWorkflowTransition(): void
    {
        $this->issues = [[
            'severity' => 'warning', 'code' => 'missing', 'field' => 'title', 'message' => 'Title missing',
        ]];
        foreach ([true, false] as $ajax) {
            $this->request->ajax = $ajax;
            $result = $this->controller->publishProposalAction();
            if ($ajax) {
                $this->assertSame($this->issues, $result->getVariable('issues'));
            } else {
                $this->assertSame('admin/id', $result[0]);
                $this->assertStringContainsString('Title missing', end($this->messages->messages));
            }
        }
        $this->issues = [];
        foreach (
            [
            'propose' => WorkflowStatus::PROPOSED, 'rejectProposal' => '', 'publishProposal' => '',
            ] as $action => $status
        ) {
            $this->workflowStatus = $status;
            $this->assertSame('admin/id', $this->controller->{$action . 'Action'}()[0]);
        }
    }

    public function testLastEventFailureReturnsNoEventAndDispatchFailureIsSanitized(): void
    {
        $this->dependencies['recatalogService']->method('lastEvent')
            ->willThrowException(new \RuntimeException('private'));
        $this->assertNull($this->data('recatalogLastEvent')['event']);
        $this->dependencies['settings']->set(LlmSettings::ENABLED, true);
        $this->dependencies['settings']->set(LlmSettings::MODEL, 'model');
        $this->dependencies['jobDispatcher']->method('dispatch')->willThrowException(new \RuntimeException('private'));
        $this->assertSame('dispatch', $this->data('aiPropose')['error']);
    }
}
