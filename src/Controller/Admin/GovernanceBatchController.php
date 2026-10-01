<?php

declare(strict_types=1);

namespace OERManager\Controller\Admin;

use Laminas\Log\LoggerInterface;
use Laminas\Mvc\Controller\AbstractActionController;
use Laminas\Validator\Csrf;
use Laminas\View\Model\JsonModel;
use Laminas\View\Model\ViewModel;
use OERManager\Job\GovernanceBatchJob;
use OERManager\Job\GovernanceBatchUndoJob;
use OERManager\Service\Ai\ProposalStore;
use OERManager\Service\Governance\BatchJobLookup;
use OERManager\Service\Governance\BatchPlan;
use OERManager\Service\Governance\BatchPlanStore;
use OERManager\Service\Governance\BatchProgressReporter;
use OERManager\Service\Governance\BatchRequest;
use OERManager\Service\Governance\BatchSelection;
use OERManager\Service\Governance\BatchSelectionException;
use OERManager\Service\GovernanceService;
use Omeka\Job\Dispatcher;
use Omeka\Permissions\Acl;

/**
 * Batch assignment of licence and authorship (TASK-028 slice 4, RF-015).
 * Own controller and route, like StatsController, so the master view's
 * controller does not grow further. ACL per privilege in Module::onBootstrap,
 * same roles as `governance-apply`. Preview writes nothing; apply and undo
 * only dispatch; the Jobs do the writing.
 */
class GovernanceBatchController extends AbstractActionController
{
    public const CSRF_NAME = 'oer_governance_batch';
    public const ROUTE = 'admin/oer-manager-batch';
    public const PRIVILEGE_UNDO_ANY = 'undo-any-batch';

    /** Job class → the state kind its reporter writes. */
    private const KINDS = [
        GovernanceBatchJob::class => BatchProgressReporter::KIND,
        GovernanceBatchUndoJob::class => BatchProgressReporter::UNDO_KIND,
    ];

    public function __construct(
        private BatchSelection $selection,
        private BatchPlanStore $plans,
        private GovernanceService $governance,
        private Dispatcher $jobDispatcher,
        private ProposalStore $states,
        private LoggerInterface $logger,
        private BatchJobLookup $jobs,
        private Acl $acl
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
                'recent' => $this->url()->fromRoute(self::ROUTE, ['action' => 'recent']),
                'undo' => $this->url()->fromRoute(self::ROUTE, ['action' => 'undo']),
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
        } catch (\Throwable $e) {
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
        $native = $job['status'];
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
        $this->jobDispatcher->stop($job['id']);
        return new JsonModel(['stopped' => true]);
    }

    /**
     * The most recent finished batches the user may undo (slice 5a): their
     * own, or everyone's with `undo-any-batch`. `owner` names the author only
     * for someone else's batch.
     */
    public function recentAction()
    {
        if ($refusal = $this->guard()) {
            return $refusal;
        }
        $identity = $this->identity();
        if (null === $identity) {
            return new JsonModel(['error' => 'not_found']);
        }
        $me = (int) $identity->getId();
        $all = (bool) $this->acl->userIsAllowed(self::class, self::PRIVILEGE_UNDO_ANY);
        try {
            $rows = $this->jobs->recent($all ? null : $me);
        } catch (\Throwable $e) {
            $this->logger->err('OERManager governance batch recent: ' . $e->getMessage());
            return new JsonModel(['error' => 'unexpected']);
        }
        $batches = array_map(static fn (array $row): array => [
            'jobId' => $row['jobId'],
            'batch' => 'batch-' . $row['jobId'],
            'owner' => $row['ownerId'] === $me ? null : $row['ownerName'],
            'started' => $row['started'],
            'ended' => $row['ended'],
            'terms' => $row['terms'],
            'mode' => $row['mode'],
            'planned' => $row['planned'],
            'status' => $row['status'],
            'undo' => $row['undo'],
        ], $rows);
        return new JsonModel(['batches' => $batches]);
    }

    /**
     * Starts the undo of a finished batch (slice 5a). Only its owner, or a user
     * with `undo-any-batch`; anyone else is told `not_found`. The ids are not
     * posted: the undo Job reads them from the batch's own args.
     */
    public function undoAction()
    {
        if ($refusal = $this->guard()) {
            return $refusal;
        }
        $batchJobId = (int) $this->params()->fromPost('batchJobId');
        if ($batchJobId <= 0) {
            return new JsonModel(['error' => 'id']);
        }
        $identity = $this->identity();
        $job = $this->jobs->find($batchJobId);
        if (
            null === $job
            || null === $identity
            || ($job['ownerId'] !== (int) $identity->getId()
                && !$this->acl->userIsAllowed(self::class, self::PRIVILEGE_UNDO_ANY))
        ) {
            return new JsonModel(['error' => 'not_found']);
        }
        if (GovernanceBatchJob::class !== $job['class']) {
            return new JsonModel(['error' => 'not_batch']);
        }
        if (!in_array($job['status'], BatchJobLookup::FINISHED, true)) {
            return new JsonModel(['error' => 'running']);
        }
        if ('running' === $this->jobs->undoState($batchJobId)['state']) {
            return new JsonModel(['error' => 'undo_running']);
        }
        $this->states->sweepOld(86400);
        try {
            $undo = $this->jobDispatcher->dispatch(GovernanceBatchUndoJob::class, [
                'batchJobId' => $batchJobId,
                'contributor' => (string) $identity->getName(),
            ]);
        } catch (\Exception $e) {
            $this->logger->err('OERManager governance batch undo dispatch: ' . $e->getMessage());
            return new JsonModel(['error' => 'dispatch']);
        }
        return new JsonModel(['jobId' => (int) $undo->getId()]);
    }

    /**
     * The posted job, only if it is a governance batch owned by the current
     * user, and its stored state. Read from the Job entity, not the API: in
     * Omeka 4.2 `api()->read('jobs')` is denied to editor and reviewer — the
     * curators this is for — and granted to site_admin for every user's job.
     * The class is checked, not only the state's `kind`: a job with no state
     * yet must never be served or stopped as a batch. Another user's batch is
     * answered as `not_found`, so its existence is not disclosed. Serves the
     * batch and its undo (slice 5a), each only to the Job's own owner:
     * `undo-any-batch` lets a site_admin start an undo of someone else's
     * batch, and the undo Job so started is theirs.
     *
     * @return array{0:?array{id:int, class:string, ownerId:?int, status:string}, 1:?array, 2:?JsonModel}
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
        $kind = self::KINDS[$job['class']] ?? null;
        if (null === $kind) {
            return [null, null, new JsonModel(['error' => 'not_batch'])];
        }
        $state = $this->states->read($jobId);
        if (null !== $state && $kind !== ($state['kind'] ?? null)) {
            return [null, null, new JsonModel(['error' => 'not_batch'])];
        }
        return [['id' => $jobId] + $job, $state, null];
    }
}
