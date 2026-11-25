<?php

declare(strict_types=1);

namespace OERManager\Controller\Admin;

use Laminas\Log\LoggerInterface;
use Laminas\Mvc\Controller\AbstractActionController;
use Laminas\Validator\Csrf;
use Laminas\View\Model\JsonModel;
use Laminas\View\Model\ViewModel;
use OERManager\Job\AiBatchProposeJob;
use OERManager\Service\Ai\BatchProposeEligibility;
use OERManager\Service\Ai\ProposalStore;
use OERManager\Service\Governance\BatchJobLookup;
use OERManager\Service\Governance\BatchSelection;
use OERManager\Service\Governance\BatchSelectionException;
use OERManager\Service\Llm\LlmSettings;
use Omeka\Job\Dispatcher;
use Omeka\Settings\Settings;

/**
 * TASK-011: the batch AI proposal, launched from the master view's selection
 * (ticked rows or everything matching the filter, as the governance batch).
 * Preview and launch only read and dispatch; AiBatchProposeJob writes. One
 * batch at a time. Status and cancel serve only the owner's own AI batch,
 * read from the Job entity (BatchJobLookup), as GovernanceBatchController.
 */
class AiBatchController extends AbstractActionController
{
    public const CSRF_NAME = 'oer_ai_batch';
    public const ROUTE = 'admin/oer-manager-ai-batch';
    /** USD per proposal measured in TASK-062: an estimate shown before launching. */
    public const COST_PER_REA = 0.0052;

    public function __construct(
        private BatchSelection $selection,
        private BatchProposeEligibility $eligibility,
        private Dispatcher $jobDispatcher,
        private ProposalStore $states,
        private LoggerInterface $logger,
        private BatchJobLookup $jobs,
        private Settings $settings
    ) {
    }

    private function csrfValidator(): Csrf
    {
        return new Csrf(['name' => self::CSRF_NAME, 'salt' => 'oermanager', 'timeout' => 3600]);
    }

    private function guard(): ?JsonModel
    {
        if (!$this->getRequest()->isPost()) {
            return new JsonModel(['error' => 'method']);
        }
        if (!$this->csrfValidator()->isValid((string) $this->params()->fromPost('csrf'))) {
            return new JsonModel(['error' => 'csrf']);
        }
        return null;
    }

    public function formAction()
    {
        $url = fn (string $action): string => $this->url()->fromRoute(self::ROUTE, ['action' => $action]);
        $view = new ViewModel([
            'csrf' => $this->csrfValidator()->getHash(),
            'urls' => [
                'preview' => $url('preview'),
                'launch' => $url('launch'),
                'status' => $url('status'),
                'cancel' => $url('cancel'),
                // Failed ids link to their item; `__ID__` is replaced client-side.
                'item' => $this->url()->fromRoute(
                    'admin/id',
                    ['controller' => 'item', 'action' => 'show', 'id' => '__ID__']
                ),
            ],
            'costPerRea' => self::COST_PER_REA,
        ]);
        $view->setTemplate('oer-manager/admin/ai-batch/form');
        $view->setTerminal(true);
        return $view;
    }

    public function previewAction()
    {
        if ($refusal = $this->guard()) {
            return $refusal;
        }
        $split = $this->split();
        if ($split instanceof JsonModel) {
            return $split;
        }
        $enter = count($split['enter']);
        return new JsonModel([
            'total' => $enter + count($split['out_of_queue']) + count($split['has_proposal']),
            'enter' => $enter,
            'outOfQueue' => count($split['out_of_queue']),
            'hasProposal' => count($split['has_proposal']),
            'cost' => round($enter * self::COST_PER_REA, 2),
            'running' => $this->jobs->isActive(AiBatchProposeJob::class),
        ]);
    }

    public function launchAction()
    {
        if ($refusal = $this->guard()) {
            return $refusal;
        }
        if (!$this->aiEnabled()) {
            return new JsonModel(['error' => 'ai_disabled']);
        }
        if ($this->jobs->isActive(AiBatchProposeJob::class)) {
            return new JsonModel(['error' => 'running']);
        }
        $split = $this->split();
        if ($split instanceof JsonModel) {
            return $split;
        }
        if ([] === $split['enter']) {
            return new JsonModel(['error' => 'nothing']);
        }
        $this->states->sweepOld(86400);
        try {
            $job = $this->jobDispatcher->dispatch(AiBatchProposeJob::class, ['ids' => $split['enter']]);
        } catch (\Exception $e) {
            $this->logger->err('OERManager ai batch dispatch: ' . $e->getMessage());
            return new JsonModel(['error' => 'dispatch']);
        }
        return new JsonModel(['jobId' => (int) $job->getId(), 'total' => count($split['enter'])]);
    }

    public function statusAction()
    {
        if ($refusal = $this->guard()) {
            return $refusal;
        }
        [$job, $state, $refusal] = $this->batchJob();
        if ($refusal) {
            return $refusal;
        }
        $native = $job['status'];
        $finished = in_array($native, BatchJobLookup::FINISHED, true);
        if (!$finished && $this->jobs->hasDied($native, $job['started'])) {
            return new JsonModel(['status' => 'error', 'code' => 'job_died']);
        }
        if (null === $state) {
            return new JsonModel($finished
                ? ['status' => 'error', 'code' => 'job_' . $native]
                : ['status' => 'in_progress', 'done' => 0, 'total' => 0]);
        }
        if ($finished && 'in_progress' === ($state['status'] ?? '')) {
            return new JsonModel(['status' => 'error', 'code' => 'job_died']);
        }
        return new JsonModel($state);
    }

    public function cancelAction()
    {
        if ($refusal = $this->guard()) {
            return $refusal;
        }
        [$job, , $refusal] = $this->batchJob();
        if ($refusal) {
            return $refusal;
        }
        $this->jobDispatcher->stop($job['id']);
        return new JsonModel(['stopped' => true]);
    }

    /** @return array{enter:list<int>, out_of_queue:list<int>, has_proposal:list<int>}|JsonModel */
    private function split(): array|JsonModel
    {
        try {
            if ('matching' === $this->params()->fromPost('scope')) {
                parse_str((string) $this->params()->fromPost('query', ''), $query);
                $ids = $this->selection->resolveMatching($query);
            } else {
                $ids = $this->selection->resolveIds((array) $this->params()->fromPost('ids', []));
            }
            return $this->eligibility->split($ids);
        } catch (BatchSelectionException $e) {
            return new JsonModel(['error' => $e->reason]);
        } catch (\Throwable $e) {
            $this->logger->err('OERManager ai batch selection: ' . $e->getMessage());
            return new JsonModel(['error' => 'unexpected']);
        }
    }

    /**
     * The posted Job, only if it is an AI batch owned by the current user.
     * Anyone else's is `not_found`, so its existence is not disclosed.
     *
     * @return array{0:?array, 1:?array, 2:?JsonModel}
     */
    private function batchJob(): array
    {
        $jobId = (int) $this->params()->fromPost('jobId');
        if ($jobId <= 0) {
            return [null, null, new JsonModel(['error' => 'id'])];
        }
        $job = $this->jobs->find($jobId);
        $identity = $this->identity();
        if (null === $job || null === $identity || $job['ownerId'] !== (int) $identity->getId()) {
            return [null, null, new JsonModel(['error' => 'not_found'])];
        }
        if (AiBatchProposeJob::class !== $job['class']) {
            return [null, null, new JsonModel(['error' => 'not_batch'])];
        }
        $state = $this->states->read($jobId);
        if (null !== $state && AiBatchProposeJob::KIND !== ($state['kind'] ?? null)) {
            return [null, null, new JsonModel(['error' => 'not_batch'])];
        }
        return [['id' => $jobId] + $job, $state, null];
    }

    /** The same minimum as IndexController::aiEnabled(): AI on and a model configured. */
    private function aiEnabled(): bool
    {
        return (bool) $this->settings->get(LlmSettings::ENABLED)
            && '' !== trim((string) $this->settings->get(LlmSettings::MODEL));
    }
}
