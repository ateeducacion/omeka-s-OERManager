<?php

declare(strict_types=1);

namespace OERManager\Controller\Admin;

use Laminas\Log\LoggerInterface;
use Laminas\Mvc\Controller\AbstractActionController;
use Laminas\Validator\Csrf;
use Laminas\View\Model\JsonModel;
use Laminas\View\Model\ViewModel;
use OERManager\Job\GovernanceBatchJob;
use OERManager\Service\Ai\ProposalStore;
use OERManager\Service\Governance\BatchPlan;
use OERManager\Service\Governance\BatchPlanStore;
use OERManager\Service\Governance\BatchProgressReporter;
use OERManager\Service\Governance\BatchRequest;
use OERManager\Service\Governance\BatchSelection;
use OERManager\Service\Governance\BatchSelectionException;
use OERManager\Service\GovernanceService;
use Omeka\Job\Dispatcher;

/**
 * Batch assignment of licence and authorship (TASK-028 slice 4, RF-015).
 * Own controller and route, like StatsController, so the master view's
 * controller does not grow further. ACL per privilege in Module::onBootstrap,
 * same roles as `governance-apply`. Preview writes nothing; apply only
 * dispatches; the job does the writing.
 */
class GovernanceBatchController extends AbstractActionController
{
    public const CSRF_NAME = 'oer_governance_batch';
    public const ROUTE = 'admin/oer-manager-batch';

    public function __construct(
        private BatchSelection $selection,
        private BatchPlanStore $plans,
        private GovernanceService $governance,
        private Dispatcher $jobDispatcher,
        private ProposalStore $states,
        private LoggerInterface $logger
    ) {
    }

    private function csrfValidator(): Csrf
    {
        return new Csrf(['name' => self::CSRF_NAME, 'salt' => 'oermanager', 'timeout' => 3600]);
    }

    /** @return JsonModel|null the refusal, or null when the POST may proceed */
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
        $view = new ViewModel([
            'governance' => $this->governance->formOptions(),
            'csrf' => $this->csrfValidator()->getHash(),
            'urls' => [
                'preview' => $this->url()->fromRoute(self::ROUTE, ['action' => 'preview']),
                'apply' => $this->url()->fromRoute(self::ROUTE, ['action' => 'apply']),
                'status' => $this->url()->fromRoute(self::ROUTE, ['action' => 'status']),
                'cancel' => $this->url()->fromRoute(self::ROUTE, ['action' => 'cancel']),
                // Failed ids in the result link to their item; `__ID__` is replaced client-side.
                'item' => $this->url()->fromRoute('admin/id', [
                    'controller' => 'item',
                    'action' => 'show',
                    'id' => '__ID__',
                ]),
            ],
        ]);
        $view->setTemplate('oer-manager/admin/governance-batch/form');
        $view->setTerminal(true);
        return $view;
    }

    public function previewAction()
    {
        if ($refusal = $this->guard()) {
            return $refusal;
        }
        $request = BatchRequest::fromPost(
            $this->params()->fromPost('governance', []),
            $this->params()->fromPost('mode')
        );
        if (!$request->isValid()) {
            return new JsonModel(['error' => 'invalid', 'errors' => $request->errors]);
        }

        try {
            if ('matching' === $this->params()->fromPost('scope')) {
                parse_str((string) $this->params()->fromPost('query', ''), $query);
                $ids = $this->selection->resolveMatching($query);
            } else {
                $ids = $this->selection->resolveIds((array) $this->params()->fromPost('ids', []));
            }
            $haveValue = [];
            foreach (array_keys($request->raw) as $term) {
                $haveValue[$term] = $this->selection->countWithValue($ids, $term);
            }
        } catch (BatchSelectionException $e) {
            return new JsonModel(['error' => $e->reason]);
        } catch (\Exception $e) {
            $this->logger->err('OERManager governance batch preview: ' . $e->getMessage());
            return new JsonModel(['error' => 'unexpected']);
        }

        $plan = new BatchPlan((int) $this->identity()->getId(), $ids, $request->raw, $request->mode);
        $summary = $plan->summary($haveValue);
        $this->plans->sweepOld();
        return new JsonModel([
            'token' => $this->plans->put($plan),
            'summary' => $summary,
            'writeTotal' => BatchPlan::writeTotal($summary),
            'mode' => $request->mode,
            'total' => count($ids),
        ]);
    }

    public function applyAction()
    {
        if ($refusal = $this->guard()) {
            return $refusal;
        }
        $identity = $this->identity();
        $plan = $this->plans->take((string) $this->params()->fromPost('token', ''), (int) $identity->getId());
        if (null === $plan) {
            return new JsonModel(['error' => 'plan_expired']);
        }
        $this->states->sweepOld(86400);
        try {
            $job = $this->jobDispatcher->dispatch(GovernanceBatchJob::class, [
                'ids' => $plan->ids,
                'raw' => $plan->raw,
                'mode' => $plan->mode,
                'contributor' => (string) $identity->getName(),
            ]);
        } catch (\Exception $e) {
            $this->logger->err('OERManager governance batch dispatch: ' . $e->getMessage());
            return new JsonModel(['error' => 'dispatch']);
        }
        return new JsonModel(['jobId' => (int) $job->getId()]);
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
        $native = (string) $job->status();
        $finished = in_array($native, ['completed', 'error', 'stopped'], true);
        if (null === $state) {
            return new JsonModel($finished
                ? ['status' => 'error', 'code' => 'job_' . $native]
                : ['status' => 'in_progress', 'done' => 0, 'total' => 0]);
        }
        // A finished job whose state still says in_progress died without
        // writing its outcome (OOM, kill): same zombie rule as the AI propose.
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
        $this->jobDispatcher->stop((int) $this->params()->fromPost('jobId'));
        return new JsonModel(['stopped' => true]);
    }

    /**
     * The posted job, readable by this user (the native job ACL restricts it
     * to its owner and admins), and its stored state only if it is a batch.
     *
     * @return array{0:mixed,1:?array,2:?JsonModel}
     */
    private function batchJob(): array
    {
        $jobId = (int) $this->params()->fromPost('jobId');
        if ($jobId <= 0) {
            return [null, null, new JsonModel(['error' => 'id'])];
        }
        try {
            $job = $this->api()->read('jobs', $jobId)->getContent();
        } catch (\Exception $e) {
            return [null, null, new JsonModel(['error' => 'not_found'])];
        }
        $state = $this->states->read($jobId);
        if (null !== $state && BatchProgressReporter::KIND !== ($state['kind'] ?? null)) {
            return [null, null, new JsonModel(['error' => 'not_batch'])];
        }
        return [$job, $state, null];
    }
}
