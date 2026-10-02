<?php

/**
 * Container harness for TASK-028 slice 5a: undo a whole governance batch.
 *
 * DISPOSABLE FIXTURES ONLY. Creates N private REA of its own, runs a batch over
 * them, undoes it and deletes every fixture at the end, even on failure. Never
 * touches a catalogue item. Requires --write <userEmail>.
 *
 *   php /var/www/html/modules/OERManager/test/container/governance-batch-undo-check.php --write <email> [fixtures=200]
 *
 * Exits 1 if any check fails.
 */

require '/var/www/html/bootstrap.php';

use OERManager\Job\GovernanceBatchJob;
use OERManager\Job\GovernanceBatchUndoJob;
use OERManager\Service\Ai\ProgressReporter;
use OERManager\Service\Ai\ProposalStore;
use OERManager\Service\Curation\UndoRouter;
use OERManager\Service\Governance\BatchJobLookup;
use OERManager\Service\Governance\BatchUndoRunner;
use OERManager\Service\Governance\GovernanceFields;
use OERManager\Service\GovernanceService;
use OERManager\Service\MasterViewQuery;
use OERManager\Service\RecatalogService;

$application = Omeka\Mvc\Application::init(require '/var/www/html/application/config/application.config.php');
$services = $application->getServiceManager();
$api = $services->get('Omeka\ApiManager');

$passed = 0;
$failed = 0;

function check(string $label, bool $condition, string $detail = ''): void
{
    global $passed, $failed;
    if ($condition) {
        $passed++;
        echo "  OK   $label\n";
        return;
    }
    $failed++;
    echo "  FAIL $label" . ('' !== $detail ? " — $detail" : '') . "\n";
}

$args = array_slice($argv, 1);
if ('--write' !== ($args[0] ?? '')) {
    fwrite(STDERR, "usage: php governance-batch-undo-check.php --write <userEmail> [fixtures]\n");
    exit(2);
}
$entityManager = $services->get('Omeka\EntityManager');
$user = $entityManager->getRepository(\Omeka\Entity\User::class)->findOneBy(['email' => (string) ($args[1] ?? '')]);
if (null === $user) {
    fwrite(STDERR, "user not found: '" . ($args[1] ?? '') . "'\n");
    exit(2);
}
$services->get('Omeka\AuthenticationService')->getStorage()->write($user);
$contributor = $user->getEmail();
$userId = (int) $user->getId();
$count = max(20, (int) ($args[2] ?? 200));
printf("authenticated as %s (%s); %d fixtures\n", $user->getEmail(), $user->getRole(), $count);

/** @var GovernanceService $governance */
$governance = $services->get(GovernanceService::class);
/** @var RecatalogService $recatalog */
$recatalog = $services->get(RecatalogService::class);
/** @var ProposalStore $states */
$states = $services->get(ProposalStore::class);

$classes = $api->search('resource_classes', ['term' => MasterViewQuery::LEARNING_RESOURCE_CLASS_TERM])->getContent();
if (!$classes) {
    fwrite(STDERR, "The lrmi:LearningResource class does not exist in this installation.\n");
    exit(1);
}
$classId = (int) $classes[0]->id();
$pid = static fn (string $term): int => (int) $api->search('properties', ['term' => $term])->getContent()[0]->id();
$creatorOf = static function (int $id) use ($api, $governance): array {
    $values = $governance->read($api->read('items', $id)->getContent())['values'];
    return array_column($values[GovernanceFields::CREATOR] ?? [], 'value');
};

/**
 * Runs a Job synchronously and returns [jobId, state, seconds, memoryGrowth].
 * Same one-process precautions as governance-batch-check.php: start from a
 * clean unit of work and re-authenticate after each Job.
 */
$runJob = static function (string $class, array $jobArgs) use ($services, $states, $userId): array {
    $dispatcher = $services->get('Omeka\Job\Dispatcher');
    $strategy = $services->has('Omeka\Job\DispatchStrategy\Synchronous')
        ? $services->get('Omeka\Job\DispatchStrategy\Synchronous')
        : null;
    $em = $services->get('Omeka\EntityManager');
    $em->clear();
    $services->get('Omeka\AuthenticationService')->getStorage()->write($em->find(\Omeka\Entity\User::class, $userId));
    gc_collect_cycles();
    $before = memory_get_usage();
    $start = microtime(true);
    $job = null === $strategy
        ? $dispatcher->dispatch($class, $jobArgs)
        : $dispatcher->dispatch($class, $jobArgs, $strategy);
    $seconds = microtime(true) - $start;
    $jobId = (int) $job->getId();
    $services->get('Omeka\AuthenticationService')->getStorage()
        ->write($services->get('Omeka\EntityManager')->find(\Omeka\Entity\User::class, $userId));
    return [$jobId, $states->read($jobId), $seconds, memory_get_usage() - $before];
};

$fixtures = [];
$canary = [];

try {
    echo "\n1. fixtures\n";
    for ($i = 0; $i < $count; $i++) {
        $data = [
            'o:is_public' => false,
            'o:resource_class' => ['o:id' => $classId],
            'dcterms:title' => [['type' => 'literal', 'property_id' => $pid('dcterms:title'),
                '@value' => "governance-batch-undo-check fixture $i (safe to delete)"]],
            'dcterms:description' => [['type' => 'literal', 'property_id' => $pid('dcterms:description'),
                '@value' => 'Temporary REA created by test/container/governance-batch-undo-check.php.']],
            'dcterms:creator' => [['type' => 'literal', 'property_id' => $pid('dcterms:creator'),
                '@value' => 'Original Author']],
        ];
        $id = (int) $api->create('items', $data)->getContent()->id();
        $fixtures[] = $id;
        $item = $api->read('items', $id)->getContent();
        $canary[$id] = [(string) $item->value('dcterms:title'), (string) $item->value('dcterms:description')];
    }
    sort($fixtures);
    check('fixtures created', $count === count($fixtures));

    echo "\n2. a replace batch\n";
    [$batchJob, $batchState] = $runJob(GovernanceBatchJob::class, [
        'ids' => $fixtures,
        'raw' => [GovernanceFields::CREATOR => ['Batch Author']],
        'mode' => 'replace',
        'contributor' => $contributor,
    ]);
    check('batch completed and wrote every fixture',
        'completed' === ($batchState['status'] ?? null) && $count === (int) ($batchState['tallies']['written'] ?? -1),
        json_encode($batchState['tallies'] ?? null));

    echo "\n3. work after the batch\n";
    $modified = array_slice($fixtures, 0, 3);
    foreach ($modified as $id) {
        $governance->apply($id, [GovernanceFields::CREATOR => ['Later Edit']], $contributor);
    }
    $byHand = array_slice($fixtures, 3, 2);
    $router = $services->get(UndoRouter::class);
    foreach ($byHand as $id) {
        $router->undo($id, $contributor);
    }
    check('3 REA edited and 2 undone by hand after the batch',
        ['Later Edit'] === $creatorOf($modified[0]) && ['Original Author'] === $creatorOf($byHand[0]));

    echo "\n4. recent and undo state\n";
    $lookup = $services->get(BatchJobLookup::class);
    $recent = $lookup->recent($userId);
    check('the batch is listed as recent and not undone',
        $batchJob === ($recent[0]['jobId'] ?? null) && 'none' === ($recent[0]['undo']['state'] ?? null)
        && $count === ($recent[0]['planned'] ?? null), json_encode($recent[0] ?? null));

    echo "\n5. batch undo through the real job\n";
    [$undoJob, $undoState, $undoSeconds, $undoGrowth] = $runJob(GovernanceBatchUndoJob::class, [
        'batchJobId' => $batchJob,
        'contributor' => $contributor,
    ]);
    $tallies = $undoState['tallies'] ?? [];
    check('undo completed', 'completed' === ($undoState['status'] ?? null), json_encode($undoState));
    check('exact figures: undone, modified later, already undone',
        $count - 5 === ($tallies['undone'] ?? -1) && 3 === ($tallies['modified_later'] ?? -1)
        && 2 === ($tallies['already_undone'] ?? -1) && 0 === ($tallies['not_in_batch'] ?? -1)
        && [] === ($tallies['failed'] ?? null), json_encode($tallies));
    $restored = true;
    foreach (array_slice($fixtures, 5) as $id) {
        $restored = $restored && ['Original Author'] === $creatorOf($id);
    }
    check('pre-batch values restored on every undone REA', $restored);
    check('later edits untouched', ['Later Edit'] === $creatorOf($modified[0]) && ['Later Edit'] === $creatorOf($modified[2]));
    $tagged = true;
    foreach (array_slice($fixtures, 5) as $id) {
        $tagged = $tagged && ('batch-' . $undoJob) === ($recatalog->lastEvent($id)['payload']['batch'] ?? null);
    }
    check('every batch-undo event carries batch-' . $undoJob, $tagged);
    $intact = true;
    foreach ($fixtures as $id) {
        $item = $api->read('items', $id)->getContent();
        $intact = $intact && $canary[$id] === [(string) $item->value('dcterms:title'), (string) $item->value('dcterms:description')];
    }
    check('titles and descriptions untouched', $intact);
    check('the list now shows the batch as undone', 'done' === ($lookup->undoState($batchJob)['state'] ?? null),
        json_encode($lookup->undoState($batchJob)));

    echo "\n6. relaunch writes nothing\n";
    [, $againState] = $runJob(GovernanceBatchUndoJob::class, ['batchJobId' => $batchJob, 'contributor' => $contributor]);
    check('a second undo undoes nothing', 0 === ($againState['tallies']['undone'] ?? -1)
        && $count - 3 === ($againState['tallies']['already_undone'] ?? -1), json_encode($againState['tallies'] ?? null));

    echo "\n7. cancel between REA (runner level, spec R2)\n";
    [$secondBatch] = $runJob(GovernanceBatchJob::class, [
        'ids' => $fixtures,
        'raw' => [GovernanceFields::CREATOR => ['Second Batch']],
        'mode' => 'replace',
        'contributor' => $contributor,
    ]);
    $stopper = new class implements ProgressReporter {
        private int $checks = 0;

        public function report(string $step, int $done, int $total): void
        {
        }

        public function shouldStop(): bool
        {
            return ++$this->checks > 3;
        }
    };
    $partial = (new BatchUndoRunner())->run(
        $fixtures,
        'batch-' . $secondBatch,
        static fn (int $id): array => $recatalog->events($id),
        static fn (int $id, array $event): array => $governance->undoEvent($id, $event, $contributor, false, 'batch-cancel'),
        $stopper
    );
    check('cancel stopped after 3 REA, all 3 undone',
        true === $partial['stopped'] && 3 === $partial['done'] && 3 === $partial['undone'], json_encode($partial));

    echo "\n8. throughput (not a check)\n";
    $perItem = $undoSeconds / max(1, $count);
    $perItemBytes = $undoGrowth / max(1, $count);
    printf(
        "   per REA: %.1f ms · memory growth: %.1f KB/REA\n   extrapolated to 3000: %.1f min, +%.0f MB\n",
        $perItem * 1000,
        $perItemBytes / 1024,
        ($perItem * 3000) / 60,
        ($perItemBytes * 3000) / 1048576
    );
} finally {
    $entityManager->clear();
    $services->get('Omeka\AuthenticationService')->getStorage()
        ->write($entityManager->find(\Omeka\Entity\User::class, $userId));
    $deleted = 0;
    foreach ($fixtures as $id) {
        try {
            $api->delete('items', $id);
            $deleted++;
        } catch (\Throwable $e) {
            echo "   could not delete fixture #$id: " . $e->getMessage() . "\n";
        }
    }
    printf("\n   deleted %d of %d fixtures\n", $deleted, count($fixtures));
    check('every fixture deleted', $deleted === count($fixtures),
        'delete leftovers whose title starts with "governance-batch-undo-check fixture"');
}

printf("\n%d OK, %d FAIL\n", $passed, $failed);
exit($failed ? 1 : 0);
