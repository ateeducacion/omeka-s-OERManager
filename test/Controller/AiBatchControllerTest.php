<?php

declare(strict_types=1);

namespace OERManager\Test\Controller;

use OERManager\Controller\Admin\AiBatchController;
use OERManager\Job\AiBatchProposeJob;
use OERManager\Service\Ai\BatchProposeEligibility;
use OERManager\Service\Ai\ProposalStore;
use OERManager\Service\Governance\BatchJobLookup;
use OERManager\Service\Governance\BatchSelection;
use OERManager\Service\Governance\BatchSelectionException;
use OERManager\Service\Llm\LlmSettings;
use Omeka\Job\Dispatcher;
use Omeka\Settings\Settings;
use PHPUnit\Framework\TestCase;

final class AiBatchControllerTest extends TestCase
{
    private AiBatchController $controller;
    private $selection;
    private $eligibility;
    private $dispatcher;
    private ProposalStore $states;
    private Settings $settings;
    private $params;
    private $request;
    private string $dir;
    private bool $active = false;
    private ?array $job = null;
    private bool $died = false;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/oer_ai_batch_' . bin2hex(random_bytes(6));
        $this->selection = $this->createMock(BatchSelection::class);
        $this->eligibility = $this->createMock(BatchProposeEligibility::class);
        $this->dispatcher = $this->createMock(Dispatcher::class);
        $this->states = new ProposalStore($this->dir);
        $this->settings = new Settings();
        $this->settings->set(LlmSettings::ENABLED, true);
        $this->settings->set(LlmSettings::MODEL, 'claude-test');
        $jobs = $this->createMock(BatchJobLookup::class);
        $jobs->method('isActive')->willReturnCallback(fn (string $class) => AiBatchProposeJob::class === $class && $this->active);
        $jobs->method('find')->willReturnCallback(fn (int $id) => $this->job);
        $jobs->method('hasDied')->willReturnCallback(fn () => $this->died);
        $this->controller = new AiBatchController(
            $this->selection,
            $this->eligibility,
            $this->dispatcher,
            $this->states,
            $this->createMock(\Laminas\Log\LoggerInterface::class),
            $jobs,
            $this->settings
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
        };
        $this->controller->plugins = [
            'params' => $this->params, 'request' => $this->request, 'identity' => $identity,
            'url' => new class {
                public function fromRoute($route, $params = [])
                {
                    return $route . '/' . ($params['action'] ?? '');
                }
            },
        ];
    }

    protected function tearDown(): void
    {
        array_map('unlink', glob($this->dir . '/*') ?: []);
        @rmdir($this->dir);
    }

    private function data(string $action): array
    {
        return $this->controller->{$action . 'Action'}()->getVariables();
    }

    private function selectionOf(array $ids, array $split): void
    {
        $this->params->post = ['csrf' => 'valid', 'scope' => 'ids', 'ids' => array_map('strval', $ids)];
        $this->selection->method('resolveIds')->willReturn($ids);
        $this->eligibility->method('split')->with($ids)->willReturn($split);
    }

    public function testEveryActionIsPostAndCsrfGuarded(): void
    {
        foreach (['preview', 'launch', 'status', 'cancel'] as $action) {
            $this->request->post = false;
            $this->assertSame('method', $this->data($action)['error'], $action);
            $this->request->post = true;
            $this->params->post = ['csrf' => 'bad'];
            $this->assertSame('csrf', $this->data($action)['error'], $action);
        }
    }

    public function testPreviewCountsTheBucketsAndTheCost(): void
    {
        $this->selectionOf([1, 2, 3, 4], ['enter' => [1, 2], 'out_of_queue' => [3], 'has_proposal' => [4]]);
        $this->assertSame(
            ['total' => 4, 'enter' => 2, 'outOfQueue' => 1, 'hasProposal' => 1, 'cost' => 0.01, 'running' => false],
            $this->data('preview')
        );
    }

    public function testPreviewOfTheMatchingFilter(): void
    {
        $this->params->post = ['csrf' => 'valid', 'scope' => 'matching', 'query' => 'status=queue&item_set_id=5'];
        $this->selection->expects($this->once())->method('resolveMatching')
            ->with(['status' => 'queue', 'item_set_id' => '5'])->willReturn([7]);
        $this->eligibility->method('split')->willReturn(['enter' => [7], 'out_of_queue' => [], 'has_proposal' => []]);
        $this->assertSame(1, $this->data('preview')['enter']);
    }

    public function testASelectionErrorIsAnswered(): void
    {
        $this->params->post = ['csrf' => 'valid', 'scope' => 'ids', 'ids' => []];
        $this->selection->method('resolveIds')->willThrowException(new BatchSelectionException('empty'));
        $this->assertSame('empty', $this->data('preview')['error']);
    }

    public function testLaunchDispatchesTheReaThatEnter(): void
    {
        $this->selectionOf([1, 2, 3], ['enter' => [1, 3], 'out_of_queue' => [2], 'has_proposal' => []]);
        $job = new \Omeka\Entity\Job();
        $this->dispatcher->expects($this->once())->method('dispatch')
            ->with(AiBatchProposeJob::class, ['ids' => [1, 3]])->willReturn($job);
        $this->assertSame(['jobId' => 1, 'total' => 2], $this->data('launch'));
    }

    public function testLaunchIsRefusedWhileABatchRuns(): void
    {
        $this->selectionOf([1], ['enter' => [1], 'out_of_queue' => [], 'has_proposal' => []]);
        $this->active = true;
        $this->dispatcher->expects($this->never())->method('dispatch');
        $this->assertSame('running', $this->data('launch')['error']);
        $this->assertTrue($this->data('preview')['running']);
    }

    public function testLaunchWithNothingToProposeOrWithoutAiDispatchesNothing(): void
    {
        $this->selectionOf([1], ['enter' => [], 'out_of_queue' => [1], 'has_proposal' => []]);
        $this->dispatcher->expects($this->never())->method('dispatch');
        $this->assertSame('nothing', $this->data('launch')['error']);
        $this->settings->set(LlmSettings::ENABLED, false);
        $this->assertSame('ai_disabled', $this->data('launch')['error']);
    }

    public function testStatusAndCancelServeOnlyTheOwnersAiBatch(): void
    {
        $this->params->post = ['csrf' => 'valid', 'jobId' => 0];
        $this->assertSame('id', $this->data('status')['error']);

        $this->params->post['jobId'] = 5;
        $this->assertSame('not_found', $this->data('status')['error']);

        $this->job = ['class' => AiBatchProposeJob::class, 'ownerId' => 99, 'status' => 'in_progress', 'args' => [], 'started' => null];
        $this->assertSame('not_found', $this->data('status')['error']);
        $this->assertSame('not_found', $this->data('cancel')['error']);

        $this->job['ownerId'] = 3;
        $this->job['class'] = 'OERManager\Job\GovernanceBatchJob';
        $this->assertSame('not_batch', $this->data('status')['error']);

        $this->job['class'] = AiBatchProposeJob::class;
        $this->assertSame(['status' => 'in_progress', 'done' => 0, 'total' => 0], $this->data('status'));
        $this->states->write(5, ['kind' => 'governance-batch', 'status' => 'in_progress']);
        $this->assertSame('not_batch', $this->data('status')['error']);
        $this->states->write(5, ['kind' => 'ai-batch', 'status' => 'in_progress', 'done' => 2, 'total' => 9]);
        $this->assertSame(2, $this->data('status')['done']);

        $this->died = true;
        $this->assertSame(['status' => 'error', 'code' => 'job_died'], $this->data('status'));
        $this->died = false;

        $this->dispatcher->expects($this->once())->method('stop')->with(5);
        $this->assertTrue($this->data('cancel')['stopped']);
    }

    public function testFormServesTheUrlsAndCsrf(): void
    {
        $view = $this->controller->formAction();
        $this->assertSame('oer-manager/admin/ai-batch/form', $view->getTemplate());
        $vars = $view->getVariables();
        $this->assertSame('admin/oer-manager-ai-batch/launch', $vars['urls']['launch']);
        $this->assertSame(AiBatchController::COST_PER_REA, $vars['costPerRea']);
        $this->assertNotSame('', $vars['csrf']);
    }
}
