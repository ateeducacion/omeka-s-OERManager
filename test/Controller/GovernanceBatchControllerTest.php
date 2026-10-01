<?php

declare(strict_types=1);

namespace OERManager\Test\Controller;

use OERManager\Controller\Admin\GovernanceBatchController;
use OERManager\Service\Ai\ProposalStore;
use OERManager\Service\Governance\BatchJobLookup;
use OERManager\Service\Governance\BatchPlanStore;
use OERManager\Service\Governance\BatchSelection;
use OERManager\Service\Governance\BatchSelectionException;
use OERManager\Service\GovernanceService;
use Omeka\Job\Dispatcher;
use PHPUnit\Framework\TestCase;

final class GovernanceBatchControllerTest extends TestCase
{
    private GovernanceBatchController $controller;
    private $selection;
    private $governance;
    private $dispatcher;
    private BatchPlanStore $plans;
    private ProposalStore $states;
    private $params;
    private $request;
    private $api;
    private string $dir;
    private string $jobStatus = 'in_progress';
    private bool $jobMissing = false;
    private string $jobClass = \OERManager\Job\GovernanceBatchJob::class;
    private ?int $jobOwner = 3;
    private bool $undoAny = false;
    private array $recentRows = [];
    private array $undoStates = [];
    private ?array $recentOwnerAsked = [];
    private ?string $jobStarted = null;
    private bool $jobDied = false;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/oer_batch_ctl_' . bin2hex(random_bytes(6));
        $this->selection = $this->createMock(BatchSelection::class);
        $this->governance = $this->createMock(GovernanceService::class);
        $this->dispatcher = $this->createMock(Dispatcher::class);
        $this->plans = new BatchPlanStore($this->dir . '/plans');
        $this->states = new ProposalStore($this->dir . '/states');
        $this->api = $this->createMock(\Omeka\Api\Manager::class);
        $jobs = $this->createMock(BatchJobLookup::class);
        $jobs->method('find')->willReturnCallback(fn (int $id) => $this->jobMissing ? null : [
            'class' => $this->jobClass,
            'ownerId' => $this->jobOwner,
            'status' => $this->jobStatus,
            'args' => [],
            'started' => $this->jobStarted,
        ]);
        $jobs->method('recent')->willReturnCallback(function (?int $ownerId) {
            $this->recentOwnerAsked = [$ownerId];
            return $this->recentRows;
        });
        $jobs->method('undoState')->willReturnCallback(
            fn (int $id) => $this->undoStates[$id] ?? ['state' => 'none', 'jobId' => null]
        );
        $jobs->method('hasDied')->willReturnCallback(fn (?string $status, $started) => $this->jobDied);
        $acl = $this->createMock(\Omeka\Permissions\Acl::class);
        $acl->method('userIsAllowed')->willReturnCallback(
            fn ($resource, $privilege) => GovernanceBatchController::PRIVILEGE_UNDO_ANY === $privilege ? $this->undoAny : true
        );
        $this->controller = new GovernanceBatchController(
            $this->selection,
            $this->plans,
            $this->governance,
            $this->dispatcher,
            $this->states,
            $this->createMock(\Laminas\Log\LoggerInterface::class),
            $jobs,
            $acl
        );
        $this->params = new class {
            public array $post = ['csrf' => 'valid'];
            public function fromPost($key = null, $default = null)
            {
                return null === $key ? $this->post : ($this->post[$key] ?? $default);
            }
            public function fromQuery($key = null, $default = null)
            {
                return $default;
            }
        };
        $this->request = new class {
            public bool $post = true;
            public function isPost()
            {
                return $this->post;
            }
        };
        $identity = new class {
            public function getId()
            {
                return 3;
            }
            public function getName()
            {
                return 'Curator';
            }
        };
        $this->controller->plugins = [
            'api' => $this->api, 'params' => $this->params, 'request' => $this->request,
            'identity' => $identity,
            'url' => new class {
                public function fromRoute($route, $params = [])
                {
                    return $route . '/' . $params['action'];
                }
            },
        ];
    }

    protected function tearDown(): void
    {
        foreach (['/plans', '/states'] as $sub) {
            array_map('unlink', glob($this->dir . $sub . '/*') ?: []);
            @rmdir($this->dir . $sub);
        }
        @rmdir($this->dir);
    }

    private function data(string $action): array
    {
        return $this->controller->{$action . 'Action'}()->getVariables();
    }

    private function validPreview(): array
    {
        $this->params->post = ['csrf' => 'valid', 'mode' => 'fill', 'ids' => ['1', '2'],
            'governance' => ['dcterms:license' => 'https://x/by/4.0/']];
        $this->selection->method('resolveIds')->willReturn([1, 2]);
        $this->selection->method('countWithValue')->willReturn(1);
        return $this->data('preview');
    }

    public function testPostActionsRejectGetAndInvalidCsrf(): void
    {
        foreach (['preview', 'apply', 'status', 'cancel', 'recent', 'undo'] as $action) {
            $this->request->post = false;
            $this->assertSame('method', $this->data($action)['error']);
            $this->request->post = true;
            $this->params->post = ['csrf' => 'invalid'];
            $this->assertSame('csrf', $this->data($action)['error']);
        }
    }

    public function testFormServesOptionsCsrfAndUrls(): void
    {
        $this->governance->method('formOptions')->willReturn(['options' => [], 'notices' => [], 'defaultRightsHolder' => '']);

        $view = $this->controller->formAction();

        $this->assertSame('oer-manager/admin/governance-batch/form', $view->getTemplate());
        $this->assertSame('valid', $view->getVariable('csrf'));
        $this->assertSame('admin/oer-manager-batch/preview', $view->getVariable('urls')['preview']);
        $this->assertSame('admin/oer-manager-batch/recent', $view->getVariable('urls')['recent']);
        $this->assertSame('admin/oer-manager-batch/undo', $view->getVariable('urls')['undo']);
    }

    public function testPreviewCountsAndReturnsAOneShotToken(): void
    {
        $result = $this->validPreview();

        $this->assertMatchesRegularExpression('/^[a-f0-9]{32}$/', $result['token']);
        $this->assertSame(['total' => 2, 'write' => 1, 'skipped_has_value' => 1], $result['summary']['dcterms:license']);
        $this->assertSame(1, $result['writeTotal']);
        $this->assertSame(2, $result['total']);
    }

    public function testPreviewRefusalsAreCodes(): void
    {
        $this->params->post = ['csrf' => 'valid', 'mode' => 'fill', 'governance' => []];
        $result = $this->data('preview');
        $this->assertSame('invalid', $result['error']);
        $this->assertSame('no-field', $result['errors']['_']);

        $this->params->post = ['csrf' => 'valid', 'mode' => 'fill', 'scope' => 'matching', 'query' => 'integrity=warning',
            'governance' => ['dcterms:creator' => ['Ana']]];
        $this->selection->method('resolveMatching')->with(['integrity' => 'warning'])
            ->willThrowException(new BatchSelectionException('computed_truncated'));
        $this->assertSame('computed_truncated', $this->data('preview')['error']);
    }

    public function testApplyTakesThePlanOnceAndDispatchesTheJob(): void
    {
        $token = $this->validPreview()['token'];
        $this->dispatcher->expects($this->once())->method('dispatch')
            ->with(\OERManager\Job\GovernanceBatchJob::class, [
                'ids' => [1, 2],
                'raw' => ['dcterms:license' => ['https://x/by/4.0/']],
                'mode' => 'fill',
                'contributor' => 'Curator',
            ])
            ->willReturn(new \Omeka\Entity\Job());

        $this->params->post = ['csrf' => 'valid', 'token' => $token];
        $this->assertSame(1, $this->data('apply')['jobId']);
        $this->assertSame('plan_expired', $this->data('apply')['error']);
    }

    public function testApplyDispatchFailureIsSanitised(): void
    {
        $token = $this->validPreview()['token'];
        $this->dispatcher->method('dispatch')->willThrowException(new \RuntimeException('secret'));
        $this->params->post = ['csrf' => 'valid', 'token' => $token];

        $this->assertSame('dispatch', $this->data('apply')['error']);
    }

    public function testStatusServesOnlyBatchStateOfAReadableJob(): void
    {
        $this->params->post = ['csrf' => 'valid', 'jobId' => 0];
        $this->assertSame('id', $this->data('status')['error']);

        $this->params->post['jobId'] = 5;
        $this->jobMissing = true;
        $this->assertSame('not_found', $this->data('status')['error']);
        $this->jobMissing = false;

        $this->assertSame('in_progress', $this->data('status')['status']);

        $this->states->write(5, ['status' => 'completed', 'payload' => []]);
        $this->assertSame('not_batch', $this->data('status')['error']);

        $this->states->write(5, ['kind' => 'governance-batch', 'status' => 'in_progress', 'done' => 25, 'total' => 100]);
        $this->assertSame(25, $this->data('status')['done']);

        $this->jobStatus = 'completed';
        $this->assertSame('job_died', $this->data('status')['code']);

        $this->states->write(5, ['kind' => 'governance-batch', 'status' => 'completed', 'batch' => 'batch-5', 'tallies' => []]);
        $this->assertSame('batch-5', $this->data('status')['batch']);
    }

    public function testCancelStopsOnlyABatchJob(): void
    {
        $this->params->post = ['csrf' => 'valid', 'jobId' => 5];
        $this->states->write(5, ['status' => 'in_progress', 'step' => 'x']);
        $this->assertSame('not_batch', $this->data('cancel')['error']);

        $this->states->write(5, ['kind' => 'governance-batch', 'status' => 'in_progress', 'done' => 0, 'total' => 1]);
        $this->dispatcher->expects($this->once())->method('stop')->with(5);
        $this->assertTrue($this->data('cancel')['stopped']);
    }

    public function testAJobOfAnotherClassWithoutStateIsNeverServedOrStopped(): void
    {
        $this->params->post = ['csrf' => 'valid', 'jobId' => 5];
        $this->jobClass = 'Omeka\Job\BatchUpdate';
        $this->dispatcher->expects($this->never())->method('stop');

        $this->assertSame('not_batch', $this->data('status')['error']);
        $this->assertSame('not_batch', $this->data('cancel')['error']);
    }

    public function testAnotherUsersBatchIsNeitherServedNorStopped(): void
    {
        $this->params->post = ['csrf' => 'valid', 'jobId' => 5];
        $this->jobOwner = 99;
        $this->states->write(5, ['kind' => 'governance-batch', 'status' => 'in_progress', 'done' => 1, 'total' => 2]);
        $this->dispatcher->expects($this->never())->method('stop');

        $this->assertSame('not_found', $this->data('status')['error']);
        $this->assertSame('not_found', $this->data('cancel')['error']);

        $this->jobOwner = null;
        $this->assertSame('not_found', $this->data('status')['error']);
    }

    public function testAJustDispatchedBatchWithoutStateCanBeCancelled(): void
    {
        $this->params->post = ['csrf' => 'valid', 'jobId' => 5];
        $this->dispatcher->expects($this->once())->method('stop')->with(5);

        $this->assertTrue($this->data('cancel')['stopped']);
    }

    public function testRecentScopesToTheOwnerUnlessAllowedToUndoAny(): void
    {
        $this->recentRows = [
            ['jobId' => 10, 'ownerId' => 3, 'ownerName' => 'Curator', 'started' => null, 'ended' => null,
                'terms' => ['dcterms:license'], 'mode' => 'fill', 'planned' => 2, 'status' => 'completed',
                'undo' => ['state' => 'none', 'jobId' => null]],
            ['jobId' => 12, 'ownerId' => 9, 'ownerName' => 'Other', 'started' => null, 'ended' => null,
                'terms' => [], 'mode' => 'replace', 'planned' => 5, 'status' => 'stopped',
                'undo' => ['state' => 'done', 'jobId' => 20]],
        ];

        $batches = $this->data('recent')['batches'];
        $this->assertSame([3], $this->recentOwnerAsked);
        $this->assertSame('batch-10', $batches[0]['batch']);
        $this->assertNull($batches[0]['owner']);
        $this->assertSame('Other', $batches[1]['owner']);
        $this->assertArrayNotHasKey('ownerId', $batches[0]);

        $this->undoAny = true;
        $this->data('recent');
        $this->assertSame([null], $this->recentOwnerAsked);
    }

    public function testUndoRefusalsAreCodes(): void
    {
        $this->params->post = ['csrf' => 'valid', 'batchJobId' => 0];
        $this->assertSame('id', $this->data('undo')['error']);

        $this->params->post['batchJobId'] = 10;
        $this->jobMissing = true;
        $this->assertSame('not_found', $this->data('undo')['error']);
        $this->jobMissing = false;

        $this->jobOwner = 99;
        $this->assertSame('not_found', $this->data('undo')['error']);
        $this->jobOwner = 3;

        $this->jobClass = \OERManager\Job\GovernanceBatchUndoJob::class;
        $this->assertSame('not_batch', $this->data('undo')['error']);
        $this->jobClass = \OERManager\Job\GovernanceBatchJob::class;

        $this->jobStatus = 'in_progress';
        $this->assertSame('running', $this->data('undo')['error']);
        $this->jobStatus = 'completed';

        $this->undoStates[10] = ['state' => 'running', 'jobId' => 20];
        $this->assertSame('undo_running', $this->data('undo')['error']);
    }

    public function testUndoDispatchesForTheOwnerOrAnyoneAllowedToUndoAny(): void
    {
        $this->params->post = ['csrf' => 'valid', 'batchJobId' => 10];
        $this->jobStatus = 'completed';
        $this->dispatcher->expects($this->exactly(2))->method('dispatch')
            ->with(\OERManager\Job\GovernanceBatchUndoJob::class, ['batchJobId' => 10, 'contributor' => 'Curator'])
            ->willReturn(new \Omeka\Entity\Job());

        $this->assertSame(1, $this->data('undo')['jobId']);

        $this->jobOwner = 99;
        $this->undoAny = true;
        $this->undoStates[10] = ['state' => 'partial', 'jobId' => 20];
        $this->assertSame(1, $this->data('undo')['jobId']);
    }

    public function testUndoOfADeadBatchDispatchesButALiveOneAnswersRunning(): void
    {
        $this->params->post = ['csrf' => 'valid', 'batchJobId' => 10];
        $this->jobStatus = 'in_progress';
        $this->jobStarted = '2026-09-30T09:00:00+00:00';

        $this->jobDied = false;
        $this->assertSame('running', $this->data('undo')['error']);

        $this->jobDied = true;
        $this->dispatcher->expects($this->once())->method('dispatch')
            ->with(\OERManager\Job\GovernanceBatchUndoJob::class, ['batchJobId' => 10, 'contributor' => 'Curator'])
            ->willReturn(new \Omeka\Entity\Job());
        $this->assertSame(1, $this->data('undo')['jobId']);
    }

    public function testUndoDispatchFailureIsSanitised(): void
    {
        $this->params->post = ['csrf' => 'valid', 'batchJobId' => 10];
        $this->jobStatus = 'completed';
        $this->dispatcher->method('dispatch')->willThrowException(new \RuntimeException('secret'));

        $this->assertSame('dispatch', $this->data('undo')['error']);
    }

    public function testStatusAndCancelServeAnUndoJobToItsOwnerOnly(): void
    {
        $this->params->post = ['csrf' => 'valid', 'jobId' => 5];
        $this->jobClass = \OERManager\Job\GovernanceBatchUndoJob::class;
        $this->states->write(5, ['kind' => 'governance-batch', 'status' => 'in_progress']);
        $this->assertSame('not_batch', $this->data('status')['error']);

        $this->states->write(5, ['kind' => 'governance-batch-undo', 'status' => 'in_progress', 'done' => 1, 'total' => 4]);
        $this->assertSame(1, $this->data('status')['done']);

        $this->dispatcher->expects($this->once())->method('stop')->with(5);
        $this->assertTrue($this->data('cancel')['stopped']);

        $this->jobOwner = 99;
        $this->undoAny = true;
        $this->assertSame('not_found', $this->data('status')['error']);
        // F3: undoAny lets this user start an undo of someone else's batch, but
        // it does not make the undo Job theirs to cancel; dispatcher->stop must
        // never be called for it (the `expects($this->once())` above already
        // used its one allowed call).
        $this->assertSame('not_found', $this->data('cancel')['error']);
    }

    public function testStatusOfADeadTrackedJobEndsPollingWithJobDiedForBatchAndUndo(): void
    {
        $this->params->post = ['csrf' => 'valid', 'jobId' => 5];
        $this->jobStatus = 'in_progress';
        $this->jobStarted = '2026-09-30T09:00:00+00:00';
        $this->states->write(5, ['kind' => 'governance-batch', 'status' => 'in_progress', 'done' => 1, 'total' => 4]);

        $this->jobDied = false;
        $this->assertSame(1, $this->data('status')['done']);

        $this->jobDied = true;
        $result = $this->data('status');
        $this->assertSame('error', $result['status']);
        $this->assertSame('job_died', $result['code']);

        $this->jobClass = \OERManager\Job\GovernanceBatchUndoJob::class;
        $this->states->write(5, ['kind' => 'governance-batch-undo', 'status' => 'in_progress', 'done' => 1, 'total' => 4]);
        $undoResult = $this->data('status');
        $this->assertSame('error', $undoResult['status']);
        $this->assertSame('job_died', $undoResult['code']);
    }
}
