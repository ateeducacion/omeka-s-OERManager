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
use OERManager\Service\Curation\CurationWriter;
use OERManager\Service\Curation\UndoRouter;
use OERManager\Service\Curriculum\CurriculumTerms;
use OERManager\Service\Governance\VocabEntries;
use OERManager\Service\IntegrityResult;
use OERManager\Service\Llm\LlmSettings;
use OERManager\Service\Workflow\ReasonVocabularies;
use OERManager\Service\Workflow\RecatalogRule;
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
    private ?array $jobInfo = ['class' => \OERManager\Job\AiProposeJob::class, 'ownerId' => 3, 'status' => 'completed'];
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
    private int $updates = 0;
    private bool $canUpdate = true;
    private bool $isPublic = false;
    /** @var list<array<string,string>> */
    private array $rejectTerms = [];
    /** @var list<array<string,string>> */
    private array $discardTerms = [];
    /** @var array<string,mixed> */
    private array $lastUpdate = [];
    private ?object $owner = null;
    /** @var list<array<string,mixed>> */
    private array $searches = [];
    private bool $applyWrites = false;

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
        $this->item->method('userIsAllowed')->willReturnCallback(fn ($privilege) => 'update' === $privilege
            && $this->canUpdate);
        $this->item->method('isPublic')->willReturnCallback(fn () => $this->isPublic);
        $this->item->method('owner')->willReturnCallback(fn () => $this->owner);
        $this->api->method('read')->willReturnCallback(function ($resource) {
            if ($this->missingItem) {
                throw new \RuntimeException('not readable');
            }
            if ($resource === 'jobs') {
                // Omeka 4.2 denies editor and reviewer any read of jobs.
                throw new PermissionDeniedException('jobs');
            }
            return new Response($this->item);
        });
        $this->api->method('search')->willReturnCallback(function ($resource, $query = []) {
            $this->searches[] = $query;
            return new Response([$this->item], 1);
        });
        $this->api->method('update')->willReturnCallback(function ($resource = null, $id = null, $data = []) {
            if ($this->denyWrite) {
                throw new PermissionDeniedException('private');
            }
            $this->updates++;
            $this->lastUpdate = $data;
            // Opt-in: the written state is what a re-read returns (TASK-070 `leftQueue`).
            if ($this->applyWrites && array_key_exists(WorkflowStatus::STATUS_TERM, $data)) {
                $this->workflowStatus = (string) ($data[WorkflowStatus::STATUS_TERM][0]['@value'] ?? '');
            }
            if ($this->applyWrites && array_key_exists('o:is_public', $data)) {
                $this->isPublic = (bool) $data['o:is_public'];
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
            'workflowService' => new WorkflowService($this->api, new CurationWriter($this->api)),
            'computedFilter' => new \OERManager\Service\ComputedFilter(),
            'reasonVocabularies' => new ReasonVocabularies(
                new VocabEntries(41, fn (int $id): array => $this->rejectTerms),
                new VocabEntries(42, fn (int $id): array => $this->discardTerms)
            ),
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
        $this->dependencies['jobLookup']->method('find')->willReturnCallback(fn () => $this->jobInfo);
        $this->dependencies['integrityChecker']->method('check')
            ->willReturnCallback(fn () => new IntegrityResult($this->issues));
        $this->dependencies['masterViewQuery']->method('buildSearchParams')->willReturn([]);
        $this->dependencies['masterViewQuery']->method('learningResourceItemSets')->willReturn([3 => 'Primaria']);
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
            public function addWarning($message)
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
            'recatalogUndo', 'aiPropose', 'propose', 'rejectProposal', 'publishProposal',
            'governanceApply', 'discard', 'unpublish', 'reopen'] as $action
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
            'propose', 'rejectProposal', 'publishProposal', 'governanceApply',
            'discard', 'unpublish', 'reopen'] as $action
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
                'discard' => WorkflowStatus::REJECTED,
                'unpublish' => WorkflowStatus::PUBLISHED,
                'reopen' => WorkflowStatus::DISCARDED,
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
                // Not to the master view: a proposer has no privilege there.
                $this->assertSame(
                    $ajax ? 'not_found' : 'admin',
                    $ajax ? $result->getVariable('error') : $result[0]
                );
                $this->missingItem = false;
                $this->params->post['csrf'] = 'bad';
                $result = $this->controller->{$action . 'Action'}();
                $this->assertSame($ajax ? 'csrf' : 'admin/id', $ajax ? $result->getVariable('error') : $result[0]);
                $this->params->post['csrf'] = 'valid';
            }
            $this->params->post['reason'] = '';
            foreach (['rejectProposal', 'discard', 'unpublish'] as $action) {
                $result = $this->controller->{$action . 'Action'}();
                $this->assertSame(
                    $ajax ? 'reason_required' : 'admin/id',
                    $ajax ? $result->getVariable('error') : $result[0],
                    $action
                );
            }
            $this->params->post['reason'] = 'Revise';
        }
    }

    /** TASK-039: reject and discard take their reason from their own vocabulary. */
    public function testRejectAndDiscardTakeAReasonFromTheirVocabulary(): void
    {
        $this->rejectTerms = [['value' => 'Licencia ausente o incompatible']];
        $this->discardTerms = [['value' => 'Duplica un REA existente']];
        $this->request->ajax = true;

        $this->workflowStatus = WorkflowStatus::PROPOSED;
        $this->params->post['reason'] = 'texto libre';
        $this->assertSame('reason_invalid', $this->data('rejectProposal')['error']);
        $this->params->post['reason'] = '';
        $this->assertSame('reason_required', $this->data('rejectProposal')['error']);
        $this->assertSame(0, $this->updates, 'nothing is written for an invalid reason');

        $this->params->post['reason'] = ' Licencia ausente o incompatible ';
        $this->params->post['detail'] = str_repeat('d', 250);
        $this->assertTrue($this->data('rejectProposal')['updated']);
        $note = $this->lastUpdate[WorkflowStatus::NOTE_TERM];
        $this->assertSame(['customvocab:41', 'literal'], array_column($note, 'type'));
        $this->assertSame(['Licencia ausente o incompatible', str_repeat('d', 200)], array_column($note, '@value'));

        $this->workflowStatus = WorkflowStatus::REJECTED;
        $this->params->post['detail'] = '';
        $this->assertSame('reason_invalid', $this->data('discard')['error'], 'each action has its own list');
        $this->params->post['reason'] = 'Duplica un REA existente';
        $this->assertTrue($this->data('discard')['updated']);
        $this->assertSame(['customvocab:42'], array_column($this->lastUpdate[WorkflowStatus::NOTE_TERM], 'type'));

        // «Despublicar» stays free text.
        $this->workflowStatus = WorkflowStatus::PUBLISHED;
        $this->params->post['reason'] = 'Licencia dudosa';
        $this->assertTrue($this->data('unpublish')['updated']);
        $this->assertSame(['literal'], array_column($this->lastUpdate[WorkflowStatus::NOTE_TERM], 'type'));
    }

    public function testTheDrawerOffersTheTermsOfEachVocabulary(): void
    {
        $this->params->query = ['id' => 7];
        $this->workflowStatus = WorkflowStatus::PROPOSED;
        $this->dependencies['itemPanelData']->method('forItem')->willReturn([]);
        $this->dependencies['acl']->method('userIsAllowed')->willReturn(true);
        $this->rejectTerms = [['value' => 'Otro motivo']];
        $view = $this->controller->drawerDetailsAction();
        $this->assertSame(['Otro motivo'], $view->getVariable('rejectReasons'));
        $this->assertNull($view->getVariable('discardReasons'), 'no terms: free text');
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
        $this->assertSame([3 => 'Primaria'], $this->controller->searchAction()->getVariable('itemSets'));
        $this->missingItem = true;
        $this->assertSame([], $this->controller->searchAction()->getVariable('resourceFilterTitles'));
    }

    public function testIndexExposesTheMatchingTotalAndTheBatchPermission(): void
    {
        $this->dependencies['acl']->method('userIsAllowed')->willReturn(true);

        $view = $this->controller->indexAction();

        $this->assertSame(1, $view->getVariable('totalResults'));
        $this->assertTrue($view->getVariable('canBatchGovernance'));
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

    /**
     * TASK-065 B8 addendum: «Explorar cursos» and «Ver materias» list the
     * children of ONE parent with no text. Bounded (NFR-004): a browse without
     * a parent is refused, except for the stages, which are a handful.
     */
    public function testSearchTermsBrowseListsTheChildrenOfAParentOnly(): void
    {
        $search = $this->dependencies['curriculumSearch'];
        $calls = [];
        $search->method('searchDimension')->willReturnCallback(
            function (string $dimension, string $text, array $context, int $limit) use (&$calls) {
                $calls[] = [$dimension, $text, $context['etapa'], $context['level'], $limit];
                return [['id' => 9]];
            }
        );
        $search->method('searchEtapas')->willReturnCallback(function (string $text, int $limit) use (&$calls) {
            $calls[] = ['etapa', $text, $limit];
            return [['id' => 1]];
        });

        $this->params->query = ['dimension' => 'schema:about', 'q' => 'ignored', 'browse' => '1', 'level' => '40'];
        $this->assertSame([['id' => 9]], $this->data('searchTerms')['results']);
        $this->params->query = ['dimension' => 'lrmi:educationalLevel', 'browse' => '1', 'etapa' => ['3']];
        $this->assertSame([['id' => 9]], $this->data('searchTerms')['results']);
        $this->params->query = ['dimension' => 'etapa', 'browse' => '1'];
        $this->assertSame([['id' => 1]], $this->data('searchTerms')['results']);

        $this->assertSame([
            ['schema:about', '', null, '40', 100],
            ['lrmi:educationalLevel', '', ['3'], null, 100],
            ['etapa', '', 100],
        ], $calls);

        // No parent: refused without a query.
        $calls = [];
        foreach ([[], ['level' => ''], ['level' => ['x', '0']]] as $parent) {
            $this->params->query = ['dimension' => 'schema:about', 'browse' => '1'] + $parent;
            $data = $this->data('searchTerms');
            $this->assertSame([], $data['results']);
            $this->assertSame('parent_required', $data['error']);
        }
        $this->assertSame([], $calls);
    }

    public function testCurriculumTreeValidatesEachCourseSubjectAndMarksRetiredLinks(): void
    {
        $terms = $this->dependencies['curriculumTerms'];
        $tree = ['subject' => ['id' => 500, 'title' => 'Matemáticas · 1º ESO'],
            'lrmi:teaches' => [], 'lrmi:assesses' => []];
        $asked = [];
        $terms->method('courseSubjectTree')->willReturnCallback(function (int $id) use ($tree, &$asked) {
            $asked[] = $id;
            return 500 === $id ? $tree : null;
        });
        $retiredAsked = [];
        $terms->method('retiredTerms')->willReturnCallback(function (array $ids) use (&$retiredAsked) {
            $retiredAsked[] = $ids;
            return in_array(60, $ids, true) ? [['id' => 60, 'title' => 'Viejo', 'replacedBy' => []]] : [];
        });

        // Read only: works on GET, needs no CSRF.
        $this->request->post = false;
        $this->params->query = ['about' => ['500', 'x', '77', '500', '-1'], 'linked' => ['60', '61']];
        $data = $this->data('curriculumTree');
        $this->assertSame([$tree], $data['trees']);
        $this->assertSame([77], $data['invalid']);
        $this->assertSame([['id' => 60, 'title' => 'Viejo', 'replacedBy' => []]], $data['retired']);
        $this->assertSame([500, 77], $asked);
        $this->assertSame([[60, 61]], $retiredAsked);

        // A scalar id works; no course subject means no tree, retired still checked.
        $this->params->query = ['about' => '500'];
        $this->assertCount(1, $this->data('curriculumTree')['trees']);
        $this->params->query = ['linked' => '60'];
        $data = $this->data('curriculumTree');
        $this->assertSame([], $data['trees']);
        $this->assertSame(60, $data['retired'][0]['id']);

        // Bounded: too many course subjects are refused without a query.
        $asked = [];
        $this->params->query = ['about' => range(1, 11)];
        $data = $this->data('curriculumTree');
        $this->assertSame('too_many', $data['error']);
        $this->assertSame([], $asked);
        // Linked ids are capped.
        $retiredAsked = [];
        $this->params->query = ['linked' => range(1, 600)];
        $this->data('curriculumTree');
        $this->assertCount(500, $retiredAsked[0]);
    }

    public function testRecatalogPayloadAndExceptionMapping(): void
    {
        $this->controller->plugins['identity'] = $this->user(1, 'global_admin');
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
        $this->controller->plugins['identity'] = $this->user(1, 'rea_curador');
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

    public function testGovernanceApplyCollectsPostedFieldsAndPassesErrorsThrough(): void
    {
        $this->params->post['governance'] = [
            'dcterms:creator' => ['Ana Pérez', '  ', 'Luis'],
            'dcterms:license' => '',
        ];
        $this->dependencies['governanceService']->expects($this->once())
            ->method('apply')
            ->with(7, ['dcterms:license' => [], 'dcterms:creator' => ['Ana Pérez', 'Luis']], 'unknown')
            ->willReturn(['updated' => false, 'errors' => ['dcterms:license' => 'not-http-uri']]);
        $result = $this->data('governanceApply');
        $this->assertFalse($result['updated']);
        $this->assertSame(['dcterms:license' => 'not-http-uri'], $result['errors']);
    }

    public function testGovernanceApplyErrorsAreSanitized(): void
    {
        $errors = [new PermissionDeniedException(), new \RuntimeException('bad uri'), new \Exception('secret')];
        $this->dependencies['governanceService']->method('apply')->willReturnCallback(function () use (&$errors) {
            throw array_shift($errors);
        });
        foreach (['denied', 'bad uri', 'unexpected'] as $error) {
            $this->assertSame($error, $this->data('governanceApply')['error']);
        }
    }

    public function testGovernanceApplySuccessRereadsAndMergesIntegrity(): void
    {
        $this->dependencies['governanceService']->method('apply')->willReturn([
            'updated' => true,
            'event' => ['when' => 'today', 'summary' => 'Gobernanza · dcterms:license +1'],
        ]);
        $this->dependencies['governanceService']->method('read')
            ->willReturn(['values' => ['dcterms:license' => []]]);
        $this->issues = [[
            'severity' => 'warning', 'code' => 'missing', 'field' => 'license', 'message' => 'Falta licencia',
        ]];
        $result = $this->data('governanceApply');
        $this->assertTrue($result['updated']);
        $this->assertSame(['dcterms:license' => []], $result['values']);
        $this->assertSame($this->issues, $result['integrity']['issues']);
        $this->assertSame(['when' => 'today', 'summary' => 'Gobernanza · dcterms:license +1'], $result['event']);
    }

    public function testGovernanceApplyRereadFailureAfterWriteIsSanitized(): void
    {
        $this->dependencies['governanceService']->method('apply')->willReturn(['updated' => true]);
        $this->missingItem = true;
        $this->assertSame('unexpected', $this->data('governanceApply')['error']);
    }

    public function testHistoryOmitsPrivateEventPayload(): void
    {
        $this->controller->plugins['identity'] = $this->user(1, 'rea_curador');
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
        $this->controller->plugins['identity'] = $this->curator(3);
        foreach (['aiProposeStatus', 'aiProposeCancel'] as $action) {
            $this->assertSame('id', $this->data($action)['error']);
            $this->params->post['jobId'] = 7;
            $saved = $this->jobInfo;
            $this->jobInfo = null;
            $this->assertSame('not_found', $this->data($action)['error']);
            $this->jobInfo = $saved;
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

    /** A logged-in user with a role (TASK-035: the re-cataloguing rule reads it). */
    private function user(int $id, string $role, string $name = 'Admin'): object
    {
        return new class ($id, $role, $name) {
            public function __construct(private int $id, private string $role, private string $name)
            {
            }

            public function getId()
            {
                return $this->id;
            }

            public function getRole()
            {
                return $this->role;
            }

            public function getName()
            {
                return $this->name;
            }
        };
    }

    /** Owner of the item in the fixture (`owner()->id()`). */
    private function ownedBy(int $id): object
    {
        return new class ($id) {
            public function __construct(private int $id)
            {
            }

            public function id()
            {
                return $this->id;
            }
        };
    }

    private function curator(int $id): object
    {
        return new class ($id) {
            public function __construct(private int $id)
            {
            }

            public function getId()
            {
                return $this->id;
            }
        };
    }

    public function testAiProposeJobsAreServedAndStoppedOnlyForTheirOwner(): void
    {
        $this->params->post['jobId'] = 7;
        $this->dependencies['jobDispatcher']->expects($this->never())->method('stop');
        $refused = [
            'another owner' => [$this->curator(99), ['ownerId' => 3]],
            'no identity' => [null, []],
            'ownerless job' => [$this->curator(3), ['ownerId' => null]],
            'another job class' => [$this->curator(3), ['class' => \OERManager\Job\GovernanceBatchJob::class]],
        ];
        $base = $this->jobInfo;
        foreach ($refused as $case => [$identity, $override]) {
            $this->controller->plugins['identity'] = $identity;
            $this->jobInfo = $override + $base;
            $this->assertSame('not_found', $this->data('aiProposeStatus')['error'], $case);
            $this->assertSame('not_found', $this->data('aiProposeCancel')['error'], $case);
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

    /**
     * TASK-064 (ADR-0022, ADR-0006): the curriculum is one item set, by id,
     * accepted only if its identifier is `<base>id/curriculo`; left empty, the
     * single such item set is detected and stored.
     */
    /**
     * «Mis propuestas» (ADR-0023 §3): the user's own REA with status and
     * reason, without the master view. No identity, nothing listed.
     */
    public function testMyProposalsListsOnlyTheUsersOwnReas(): void
    {
        $this->controller->plugins['paginator'] = new class {
            public function __invoke($total)
            {
            }
        };
        $view = $this->controller->myProposalsAction();
        $this->assertSame([], $view->getVariable('items'));

        $this->controller->plugins['identity'] = new class {
            public function getId()
            {
                return 3;
            }
        };
        $this->workflowStatus = WorkflowStatus::REJECTED;
        $view = $this->controller->myProposalsAction();
        $this->assertSame('oer-manager/admin/index/my-proposals', $view->getTemplate());
        $this->assertSame([$this->item], $view->getVariable('items'));
        $this->assertSame(1, $view->getVariable('total'));
        // TASK-035 §3: the page opens the same panel, whose re-cataloguer posts with this token.
        $this->assertNotSame('', (string) $view->getVariable('recatalogCsrf'));
    }

    /** TASK-067: the REA site is validated on save, and the page says when the rule is off. */
    public function testConfigValidatesTheReaSite(): void
    {
        $form = $this->getMockBuilder(\OERManager\Form\ConfigForm::class)->onlyMethods(['isValid'])->getMock();
        $form->init();
        $form->method('isValid')->willReturn(true);
        $this->dependencies['formElementManager']->method('get')->willReturn($form);
        $this->dependencies['reaSite']->method('exists')->willReturnCallback(static fn (int $id): bool => 1 === $id);
        $settings = $this->dependencies['settings'];
        $off = 'Sin sitio REA configurado: el aviso «REA sin sitio REA» no se evalúa.';

        $this->request->post = false;
        $this->controller->configAction();
        $this->assertContains($off, array_map('strval', $this->messages->messages));

        $this->request->post = true;
        $this->params->post = [\OERManager\Service\ReaSite::SETTING => '1'];
        $this->controller->configAction();
        $this->assertSame(1, $settings->get(\OERManager\Service\ReaSite::SETTING));

        $this->params->post = [\OERManager\Service\ReaSite::SETTING => '99'];
        $this->controller->configAction();
        $this->assertSame(1, $settings->get(\OERManager\Service\ReaSite::SETTING));
        $this->assertContains('El sitio 99 no existe: no se guarda como sitio REA.', array_map('strval', $this->messages->messages));
    }

    public function testConfigValidatesOrDetectsTheCurriculumItemSet(): void
    {
        $form = $this->getMockBuilder(\OERManager\Form\ConfigForm::class)->onlyMethods(['isValid'])->getMock();
        $form->init();
        $form->method('isValid')->willReturn(true);
        $this->dependencies['formElementManager']->method('get')->willReturn($form);
        $terms = $this->dependencies['curriculumTerms'];
        $terms->method('isScheme')->willReturnCallback(static fn (int $id): bool => 46259 === $id);
        $terms->method('detectSchemeId')->willReturn(46259);
        $settings = $this->dependencies['settings'];
        $this->request->post = true;

        $settings->set(CurriculumTerms::SCHEME_SETTING, 46259);
        $this->params->post = [CurriculumTerms::SCHEME_SETTING => '7'];
        $this->controller->configAction();
        $this->assertSame(46259, $settings->get(CurriculumTerms::SCHEME_SETTING));
        $this->assertContains(
            'El conjunto 7 no es el currículo: su dcterms:identifier no acaba en id/curriculo.',
            array_map('strval', $this->messages->messages)
        );

        $this->params->post = [CurriculumTerms::SCHEME_SETTING => ''];
        $this->controller->configAction();
        $this->assertSame(46259, $settings->get(CurriculumTerms::SCHEME_SETTING));

        $settings->set(CurriculumTerms::SCHEME_SETTING, null);
        $this->params->post = [CurriculumTerms::SCHEME_SETTING => '46259'];
        $this->controller->configAction();
        $this->assertSame(46259, $settings->get(CurriculumTerms::SCHEME_SETTING));
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
        $this->assertTrue($view->getVariable('canDiscard'));
        $this->assertFalse($view->getVariable('canUnpublish'));
        $this->assertFalse($view->getVariable('canReopen'));
        $this->assertSame('admin/oer-manager/discard', $view->getVariable('discardUrl'));
    }

    /**
     * TASK-067: each workflow button needs its own privilege, so a proposer
     * (who inherits view-all from site_editor) never sees a curator's button.
     */
    public function testDrawerWorkflowButtonsFollowEachPrivilege(): void
    {
        $this->params->query = ['id' => 7];
        $this->workflowStatus = WorkflowStatus::PROPOSED;
        $this->dependencies['itemPanelData']->method('forItem')->willReturn([]);
        $this->dependencies['acl']->method('userIsAllowed')
            ->willReturnCallback(static fn ($resource, $privilege) => 'reject-proposal' === $privilege);
        $view = $this->controller->drawerDetailsAction();
        $this->assertTrue($view->getVariable('canReject'));
        $this->assertFalse($view->getVariable('canPublish'));
        $this->assertFalse($view->getVariable('canDiscard'));
    }

    /**
     * «Confirmar y publicar» (ADR-0023 §3): the panel's pending re-cataloguing
     * is applied first; if that write fails nothing is published.
     */
    public function testConfirmAndPublishAppliesTheRecataloguingFirst(): void
    {
        $this->workflowStatus = WorkflowStatus::PROPOSED;
        $this->controller->plugins['identity'] = $this->user(1, 'rea_curador', 'Curadora');
        $this->params->post['alignment'] = ['lrmi:teaches' => [11]];
        $recatalog = $this->dependencies['recatalogService'];
        $recatalog->expects($this->exactly(2))->method('apply')
            ->with(7, ['lrmi:teaches' => [11]], 'Curadora', [])
            ->willReturnOnConsecutiveCalls(
                $this->throwException(new \RuntimeException('invalid_targets')),
                ['updated' => true, 'properties' => ['lrmi:teaches']]
            );
        $failed = $this->controller->publishProposalAction();
        $this->assertFalse($failed->getVariable('updated'));
        $this->assertSame('invalid_targets', $failed->getVariable('error'));
        $this->assertSame(0, $this->updates, 'a failed re-cataloguing publishes nothing');

        // The gate ignores «Publicado fuera del flujo»: publishing is what regularises it.
        $this->issues = [['severity' => 'warning', 'code' => 'published_out_of_flow', 'field' => 'o:is_public',
            'message' => 'Out of flow']];
        $done = $this->controller->publishProposalAction();
        $this->assertTrue($done->getVariable('updated'));
        $this->assertSame('Publicado', $done->getVariable('status'));
        $this->assertSame(1, $this->updates);
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

    /**
     * TASK-035 §2 (owner decision 2026-11-18): every curricular entry of the
     * panel applies RecatalogRule before any work, and refuses with a code and
     * a message the panel shows.
     */
    public function testRecatalogEntriesRefuseWhatTheRuleRefuses(): void
    {
        $recatalog = $this->dependencies['recatalogService'];
        foreach (['preview', 'apply', 'undo', 'lastEvent'] as $method) {
            $recatalog->expects($this->never())->method($method);
        }
        $this->params->post['alignment'] = ['lrmi:teaches' => [1]];
        $cases = [
            'no identity' => [null, '', RecatalogRule::ROLE],
            'a site administrator' => [$this->user(5, 'site_admin'), '', RecatalogRule::ROLE],
            'curator on Descartado' => [$this->user(1, 'rea_curador'), WorkflowStatus::DISCARDED, RecatalogRule::DISCARDED],
            'admin on Descartado' => [$this->user(1, 'global_admin'), WorkflowStatus::DISCARDED, RecatalogRule::DISCARDED],
            'proposer on Propuesto' => [$this->user(3, 'rea_proponente'), WorkflowStatus::PROPOSED, RecatalogRule::STATUS],
            'proposer on Publicado' => [$this->user(3, 'rea_proponente'), WorkflowStatus::PUBLISHED, RecatalogRule::STATUS],
        ];
        $this->owner = $this->ownedBy(3);
        foreach ($cases as $case => [$identity, $status, $code]) {
            $this->controller->plugins['identity'] = $identity;
            $this->workflowStatus = $status;
            foreach (['recatalogPreview', 'recatalogApply', 'recatalogUndo'] as $action) {
                $data = $this->data($action);
                $this->assertFalse($data['updated'], $case . ' ' . $action);
                $this->assertSame($code, $data['error'], $case . ' ' . $action);
                $this->assertSame(RecatalogRule::message($code), $data['message'], $case . ' ' . $action);
            }
            $this->assertNull($this->data('recatalogLastEvent')['event'], $case);
        }

        // Without native update (IsolatedSites, a foreign site), not even a curator.
        $this->controller->plugins['identity'] = $this->user(1, 'rea_curador');
        $this->workflowStatus = '';
        $this->canUpdate = false;
        $this->assertSame(RecatalogRule::NO_UPDATE, $this->data('recatalogApply')['error']);
        $this->canUpdate = true;

        // A proposer on someone else's REA gets the same answer as for a
        // missing one (security review: existence is not revealed).
        $this->controller->plugins['identity'] = $this->user(4, 'rea_proponente');
        foreach (['recatalogPreview', 'recatalogApply', 'recatalogUndo'] as $action) {
            $this->assertSame('not_found', $this->data($action)['error'], $action);
            $this->assertSame(IndexController::NOT_FOUND_MESSAGE, $this->data($action)['message'], $action);
        }
        $this->assertNull($this->data('recatalogLastEvent')['event']);

        $this->controller->plugins['identity'] = $this->user(1, 'rea_curador');
        $this->missingItem = true;
        foreach (['recatalogPreview', 'recatalogApply', 'recatalogUndo'] as $action) {
            $this->assertSame('not_found', $this->data($action)['error'], $action);
        }
    }

    /** The owner's table, allowed side: who reaches the service and in which state. */
    public function testRecatalogReachesTheServiceWhenTheRuleAllows(): void
    {
        $recatalog = $this->dependencies['recatalogService'];
        $recatalog->method('preview')->willReturn(['diff']);
        $recatalog->method('apply')->willReturn(['updated' => true]);
        $recatalog->method('lastEvent')->willReturn(self::CURRICULUM_EVENT);
        $recatalog->method('undo')->willReturn(['updated' => true]);
        $this->params->post['alignment'] = ['lrmi:teaches' => [1]];
        $this->owner = $this->ownedBy(3);
        $cases = [
            'proposer, own Borrador' => [$this->user(3, 'rea_proponente'), '', false],
            'proposer, own Rechazado' => [$this->user(3, 'rea_proponente'), WorkflowStatus::REJECTED, false],
            'curator, Propuesto' => [$this->user(1, 'rea_curador'), WorkflowStatus::PROPOSED, false],
            'curator, Publicado' => [$this->user(1, 'rea_curador'), WorkflowStatus::PUBLISHED, true],
            'curator, out of flow' => [$this->user(1, 'rea_curador'), '', true],
            'admin, private Borrador' => [$this->user(1, 'global_admin'), '', false],
        ];
        foreach ($cases as $case => [$identity, $status, $isPublic]) {
            $this->controller->plugins['identity'] = $identity;
            $this->workflowStatus = $status;
            $this->isPublic = $isPublic;
            $this->assertSame(['diff'], $this->data('recatalogPreview')['diff'], $case);
            $this->assertTrue($this->data('recatalogApply')['updated'], $case);
            $this->assertTrue($this->data('recatalogUndo')['updated'], $case);
            $this->assertSame('Curator', $this->data('recatalogLastEvent')['event']['contributor'], $case);
        }
    }

    /**
     * The ledger mixes curricular and governance events: a proposer may undo
     * the former on their REA, never a licence or authorship change.
     */
    public function testUndoingAGovernanceEventNeedsTheGovernancePrivilege(): void
    {
        $event = self::CURRICULUM_EVENT;
        $event['payload'] = ['v' => 1, 'op' => 'recatalog', 'undoOf' => null, 'terms' => ['dcterms:license' => []]];
        $this->dependencies['recatalogService']->method('lastEvent')->willReturn($event);
        $this->dependencies['governanceService']->method('undoEvent')->willReturn(['updated' => true]);
        $this->owner = $this->ownedBy(3);
        $this->controller->plugins['identity'] = $this->user(3, 'rea_proponente');
        $allowGovernance = false;
        $this->dependencies['acl']->method('userIsAllowed')
            ->willReturnCallback(static function ($resource, $privilege) use (&$allowGovernance) {
                return 'governance-apply' === $privilege ? $allowGovernance : true;
            });

        $data = $this->data('recatalogUndo');
        $this->assertSame('governance_undo', $data['error']);
        $this->assertNotSame('', $data['message']);

        $allowGovernance = true;
        $this->assertTrue($this->data('recatalogUndo')['updated']);
    }

    /** «Confirmar y publicar» writes a pending re-cataloguing only if the rule allows it. */
    public function testConfirmAndPublishChecksTheRuleBeforeWritingTheAlignment(): void
    {
        $this->workflowStatus = WorkflowStatus::PROPOSED;
        $this->params->post['alignment'] = ['lrmi:teaches' => [11]];
        $this->dependencies['recatalogService']->expects($this->never())->method('apply');
        $this->owner = $this->ownedBy(3);
        $this->controller->plugins['identity'] = $this->user(3, 'rea_proponente');
        $result = $this->controller->publishProposalAction();
        $this->assertSame(RecatalogRule::STATUS, $result->getVariable('error'));
        $this->assertSame(0, $this->updates);
    }

    /**
     * TASK-035 §1: the panel brings its own pre-fill (no `/api`), and whether
     * to offer the editor is the rule's answer for this user and this item.
     */
    public function testDrawerDetailsCarriesThePrefillAndTheRuleDecision(): void
    {
        $current = ['lrmi:teaches' => [['id' => 11, 'title' => 'Saber']]];
        $this->dependencies['itemPanelData']->method('forItem')->willReturn(['current' => $current]);
        $allowed = true;
        $this->dependencies['acl']->method('userIsAllowed')
            ->willReturnCallback(static function ($resource, $privilege) use (&$allowed) {
                return 'recatalog-apply' === $privilege ? $allowed : false;
            });
        $this->params->query = ['id' => 7];
        $this->owner = $this->ownedBy(3);

        // Unreadable item: nothing to offer.
        $this->missingItem = true;
        $this->assertSame(
            ['allowed' => false, 'reason' => '', 'current' => []],
            $this->controller->drawerDetailsAction()->getVariable('recatalog')
        );
        $this->missingItem = false;

        $this->controller->plugins['identity'] = $this->user(3, 'rea_proponente');
        $this->assertSame(
            ['allowed' => true, 'reason' => '', 'current' => $current],
            $this->controller->drawerDetailsAction()->getVariable('recatalog')
        );

        $this->workflowStatus = WorkflowStatus::PROPOSED;
        $this->assertSame(
            ['allowed' => false, 'reason' => RecatalogRule::message(RecatalogRule::STATUS), 'current' => $current],
            $this->controller->drawerDetailsAction()->getVariable('recatalog')
        );

        // Without the privilege at all the panel says nothing about re-cataloguing.
        $allowed = false;
        $this->assertSame(
            ['allowed' => false, 'reason' => '', 'current' => $current],
            $this->controller->drawerDetailsAction()->getVariable('recatalog')
        );
    }

    /**
     * Security review of TASK-035: a proposer reads the panel and the curation
     * history only of their own REA; for any other the answer is the same as
     * for an unreadable item (existence is not revealed).
     */
    public function testAProposerReadsThePanelAndHistoryOnlyOfTheirOwnReas(): void
    {
        $this->dependencies['itemPanelData']->method('forItem')->willReturn(['current' => []]);
        $this->dependencies['recatalogService']->method('history')->willReturn([['summary' => 'Rejected by X']]);
        $this->params->query = ['id' => 7];
        $this->owner = $this->ownedBy(3);

        foreach ([false, true] as $isPublic) {
            $this->isPublic = $isPublic;
            $this->controller->plugins['identity'] = $this->user(4, 'rea_proponente');
            $this->assertNull($this->controller->drawerDetailsAction()->getVariable('panel'));
            $this->assertNull($this->data('drawerHistory')['history']);

            $this->controller->plugins['identity'] = $this->user(3, 'rea_proponente');
            $this->assertNotNull($this->controller->drawerDetailsAction()->getVariable('panel'));
            $this->assertSame([['summary' => 'Rejected by X']], $this->data('drawerHistory')['history']);

            foreach (['rea_curador', 'global_admin'] as $role) {
                $this->controller->plugins['identity'] = $this->user(9, $role);
                $this->assertNotNull($this->controller->drawerDetailsAction()->getVariable('panel'), $role);
                $this->assertNotNull($this->data('drawerHistory')['history'], $role);
            }
        }
        // An item without owner is nobody's own.
        $this->owner = null;
        $this->controller->plugins['identity'] = $this->user(3, 'rea_proponente');
        $this->assertNull($this->data('drawerHistory')['history']);
    }

    /** TASK-070: ids from the resolver bound the page search. */
    public function testConstrainedIdsBoundThePageSearch(): void
    {
        $this->dependencies['idConstraintResolver']->method('resolve')->willReturn([7, 9]);

        $view = $this->controller->indexAction();

        $this->assertSame([$this->item], $view->getVariable('items'));
        $this->assertSame([7, 9], end($this->searches)['id']);
    }

    /** TASK-070: the core ignores `id => []` and would list every REA. */
    public function testAnEmptyIdSetShowsNoResultsWithoutSearching(): void
    {
        $this->dependencies['idConstraintResolver']->method('resolve')->willReturn([]);

        $view = $this->controller->indexAction();

        $this->assertSame([], $view->getVariable('items'));
        $this->assertSame(0, $view->getVariable('totalResults'));
        $this->assertSame([], $this->searches);
    }

    public function testTheSearchFormKnowsWhetherRequiredFieldsExist(): void
    {
        $this->dependencies['idConstraintResolver']->method('hasRequiredFields')->willReturn(true);

        $this->assertTrue($this->controller->searchAction()->getVariable('requiredFieldsAvailable'));
    }

    /**
     * TASK-070 review: «Cola (N)» drops only when the REA was in the queue and
     * is no longer in it; the action name alone does not say so.
     */
    public function testWorkflowJsonSaysWhetherTheReaLeftTheQueue(): void
    {
        $this->request->ajax = true;
        $this->applyWrites = true;
        $cases = [
            'private Propuesto rejected' => ['rejectProposal', WorkflowStatus::PROPOSED, false, true],
            'public Propuesto rejected stays out of the flow' => ['rejectProposal', WorkflowStatus::PROPOSED, true, false],
            'private Rechazado discarded was never queued' => ['discard', WorkflowStatus::REJECTED, false, false],
            'public outside the flow discarded' => ['discard', '', true, true],
        ];
        foreach ($cases as $case => [$action, $status, $isPublic, $left]) {
            $this->workflowStatus = $status;
            $this->isPublic = $isPublic;
            $data = $this->data($action);
            $this->assertTrue($data['updated'], $case);
            $this->assertSame($left, $data['leftQueue'] ?? null, $case);
        }
    }
}
