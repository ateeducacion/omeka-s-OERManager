<?php

/**
 * Container harness for TASK-028 slice 4: batch licence and authorship.
 *
 * DISPOSABLE FIXTURES ONLY. Creates N private REA of its own (title,
 * description), runs batches over them and deletes them at the end, even on
 * failure. Never touches a catalogue item. Requires --write <userEmail>.
 *
 *   php /var/www/html/modules/OERManager/test/container/governance-batch-check.php --write <email> [fixtures=30]
 *
 * Exits 1 if any check fails.
 */

require '/var/www/html/bootstrap.php';

use OERManager\Job\GovernanceBatchJob;
use OERManager\Service\Ai\ProgressReporter;
use OERManager\Service\Ai\ProposalStore;
use OERManager\Service\Governance\BatchJobLookup;
use OERManager\Service\Curation\UndoRouter;
use OERManager\Service\Governance\BatchSelection;
use OERManager\Service\Governance\GovernanceBatchRunner;
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
    fwrite(STDERR, "usage: php governance-batch-check.php --write <userEmail> [fixtures]\n");
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
$count = max(4, (int) ($args[2] ?? 30));
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

$fixtures = [];
$seeded = [];
$canary = [];
$creatorOf = static function (int $id) use ($api, $governance): array {
    $values = $governance->read($api->read('items', $id)->getContent())['values'];
    return array_column($values[GovernanceFields::CREATOR] ?? [], 'value');
};
$valueOf = static function (int $id, string $term) use ($api, $governance): array {
    $values = $governance->read($api->read('items', $id)->getContent())['values'];
    return array_map(static fn (array $v): string => (string) ($v['uri'] ?? $v['value'] ?? ''), $values[$term] ?? []);
};

$userId = (int) $user->getId();

/**
 * Dispatches the real job synchronously and returns [jobId, state, seconds, peakBytes, sync].
 *
 * Running several jobs in ONE process is a harness-only situation (production
 * runs each job in its own PhpCli process): after a synchronous job the
 * authenticated User entity is no longer managed, and every new value
 * annotation (a Resource owned by the identity) would then fail with "a new
 * entity was found through Resource#owner". Re-reading the user and writing it
 * back as the identity after each job keeps the next writes valid.
 */
$runJob = static function (array $jobArgs) use ($services, $states, $userId): array {
    $dispatcher = $services->get('Omeka\Job\Dispatcher');
    $strategy = $services->has('Omeka\Job\DispatchStrategy\Synchronous')
        ? $services->get('Omeka\Job\DispatchStrategy\Synchronous')
        : null;
    // Measure the job, not the harness: drop what fixture creation left in
    // Doctrine's identity map, then re-authenticate (clear() detaches the user).
    $em = $services->get('Omeka\EntityManager');
    $em->clear();
    $services->get('Omeka\AuthenticationService')->getStorage()->write($em->find(\Omeka\Entity\User::class, $userId));
    gc_collect_cycles();
    $before = memory_get_usage();
    memory_reset_peak_usage();
    $start = microtime(true);
    $job = null === $strategy
        ? $dispatcher->dispatch(GovernanceBatchJob::class, $jobArgs)
        : $dispatcher->dispatch(GovernanceBatchJob::class, $jobArgs, $strategy);
    $seconds = microtime(true) - $start;
    $jobId = (int) $job->getId();
    $fresh = $services->get('Omeka\EntityManager')->find(\Omeka\Entity\User::class, $userId);
    $services->get('Omeka\AuthenticationService')->getStorage()->write($fresh);
    $growth = memory_get_usage() - $before;
    return [$jobId, $states->read($jobId), $seconds, memory_get_peak_usage(), null !== $strategy, $growth];
};

try {
    echo "\n1. fixtures\n";
    for ($i = 0; $i < $count; $i++) {
        $data = [
            'o:is_public' => false,
            'o:resource_class' => ['o:id' => $classId],
            'dcterms:title' => [['type' => 'literal', 'property_id' => $pid('dcterms:title'),
                '@value' => "governance-batch-check fixture $i (safe to delete)"]],
            'dcterms:description' => [['type' => 'literal', 'property_id' => $pid('dcterms:description'),
                '@value' => 'Temporary REA created by test/container/governance-batch-check.php.']],
        ];
        if ($i < intdiv($count, 4)) {
            $data['dcterms:creator'] = [['type' => 'literal', 'property_id' => $pid('dcterms:creator'),
                '@value' => 'Existing Author']];
        }
        $id = (int) $api->create('items', $data)->getContent()->id();
        $fixtures[] = $id;
        if ($i < intdiv($count, 4)) {
            $seeded[] = $id;
        }
        $item = $api->read('items', $id)->getContent();
        $canary[$id] = [(string) $item->value('dcterms:title'), (string) $item->value('dcterms:description')];
    }
    sort($fixtures);
    printf("   created %d private fixtures, %d with an existing author\n", count($fixtures), count($seeded));
    check('fixtures created', $count === count($fixtures));

    echo "\n2. core assumptions (spec §10.1)\n";
    $scalar = $api->search('items', ['id' => $fixtures, 'resource_class_id' => $classId], ['returnScalar' => 'id'])->getContent();
    $scalar = array_map('intval', (array) $scalar);
    sort($scalar);
    check('returnScalar=id and a list-valued `id` return exactly the fixtures', $fixtures === $scalar,
        'got ' . json_encode(array_slice($scalar, 0, 5)));

    echo "\n3. selection\n";
    /** @var BatchSelection $selection */
    $selection = $services->get(BatchSelection::class);
    check('resolveIds() returns the fixtures', $fixtures === $selection->resolveIds($fixtures));
    check('countWithValue() counts the seeded authors',
        count($seeded) === $selection->countWithValue($fixtures, GovernanceFields::CREATOR));

    echo "\n4. fill mode through the real job\n";
    [$fillJob, $fillState, $fillSeconds, $fillPeak, $sync, $fillGrowth] = $runJob([
        'ids' => $fixtures,
        'raw' => [GovernanceFields::CREATOR => ['Batch Author'], GovernanceFields::RIGHTS_HOLDER => ['Batch Holder']],
        'mode' => 'fill',
        'contributor' => $contributor,
    ]);
    if (!$sync) {
        echo "   (no Synchronous strategy service: dispatched with the default strategy)\n";
    }
    check('fill job completed', 'completed' === ($fillState['status'] ?? null), json_encode($fillState));
    check('every fixture written (rights holder was empty everywhere)',
        $count === (int) ($fillState['tallies']['written'] ?? -1), json_encode($fillState['tallies'] ?? null));
    $seededKept = true;
    $othersWritten = true;
    $holderWritten = true;
    foreach ($fixtures as $id) {
        $creators = $creatorOf($id);
        if (in_array($id, $seeded, true)) {
            $seededKept = $seededKept && ['Existing Author'] === $creators;
        } else {
            $othersWritten = $othersWritten && ['Batch Author'] === $creators;
        }
        $holderWritten = $holderWritten && ['Batch Holder'] === $valueOf($id, GovernanceFields::RIGHTS_HOLDER);
    }
    check('fill never overwrote an existing author', $seededKept);
    check('fill wrote the author where it was empty', $othersWritten);
    check('fill wrote the rights holder everywhere', $holderWritten);

    echo "\n5. events\n";
    $batchOk = true;
    foreach ($fixtures as $id) {
        $event = $recatalog->lastEvent($id);
        $batchOk = $batchOk && ('batch-' . $fillJob) === ($event['payload']['batch'] ?? null);
    }
    check('every written fixture carries batch-' . $fillJob . ' in its last event', $batchOk);

    $info = $services->get(BatchJobLookup::class)->find($fillJob);
    check('BatchJobLookup reads class and owner from the real Job entity',
        GovernanceBatchJob::class === ($info['class'] ?? null) && $userId === ($info['ownerId'] ?? null), json_encode($info));

    echo "\n5b. fill skips items that already have the value\n";
    [, $skipState] = $runJob([
        'ids' => $fixtures,
        'raw' => [GovernanceFields::CREATOR => ['Another Author']],
        'mode' => 'fill',
        'contributor' => $contributor,
    ]);
    check('a fill over authors that all exist skips every item and writes none',
        $count === (int) ($skipState['tallies']['skipped'] ?? -1) && 0 === (int) ($skipState['tallies']['written'] ?? -1),
        json_encode($skipState['tallies'] ?? null));

    echo "\n6. replace mode\n";
    [, $replaceState] = $runJob([
        'ids' => $fixtures,
        'raw' => [GovernanceFields::CREATOR => ['Replaced']],
        'mode' => 'replace',
        'contributor' => $contributor,
    ]);
    check('replace job completed', 'completed' === ($replaceState['status'] ?? null), json_encode($replaceState));
    $replaced = true;
    foreach ($fixtures as $id) {
        $replaced = $replaced && ['Replaced'] === $creatorOf($id);
    }
    check('replace overwrote every author', $replaced, json_encode($replaceState['tallies'] ?? null));

    echo "\n7. per-item undo\n";
    /** @var UndoRouter $router */
    $router = $services->get(UndoRouter::class);
    $first = $seeded[0] ?? $fixtures[0];
    $undo = $router->undo($first, $contributor);
    check('undo of the replace restores the fill state',
        true === ($undo['updated'] ?? false) && ['Existing Author'] === $creatorOf($first), json_encode($undo));

    echo "\n8. canary\n";
    $intact = true;
    foreach ($fixtures as $id) {
        $item = $api->read('items', $id)->getContent();
        $intact = $intact && $canary[$id] === [(string) $item->value('dcterms:title'), (string) $item->value('dcterms:description')];
    }
    check('titles and descriptions untouched by the batches', $intact);

    echo "\n9. cancel between items\n";
    $progress = new class implements ProgressReporter {
        private int $checks = 0;

        public function report(string $step, int $done, int $total): void
        {
        }

        public function shouldStop(): bool
        {
            return ++$this->checks > 3;
        }
    };
    $tallies = (new GovernanceBatchRunner())->run(
        $fixtures,
        static fn (int $id): array => $governance->apply(
            $id,
            [GovernanceFields::PUBLISHER => ['Cancel Publisher']],
            $contributor,
            null,
            false,
            'batch-cancel',
            false
        ),
        $progress
    );
    $marked = 0;
    foreach ($fixtures as $id) {
        if ('batch-cancel' === ($recatalog->lastEvent($id)['payload']['batch'] ?? null)) {
            $marked++;
        }
    }
    check('cancel stopped after 3 items', true === $tallies['stopped'] && 3 === $tallies['done'], json_encode($tallies));
    check('exactly 3 fixtures carry the cancelled batch', 3 === $marked, "marked=$marked");

    echo "\n10. throughput (not a check)\n";
    $perItem = $fillSeconds / max(1, $count);
    $perItemBytes = $fillGrowth / max(1, $count);
    printf(
        "   per item: %.1f ms · memory growth: %.1f KB/item (%.1f MB over %d items, peak %.1f MB)\n"
        . "   extrapolated to 3000: %.1f min, +%.0f MB\n",
        $perItem * 1000,
        $perItemBytes / 1024,
        $fillGrowth / 1048576,
        $count,
        $fillPeak / 1048576,
        ($perItem * 3000) / 60,
        ($perItemBytes * 3000) / 1048576
    );
} finally {
    // Same one-process artefact as in $runJob, wider: listeners of other
    // modules (Access) hold entities the earlier jobs left unmanaged, and a
    // delete would then fail. Start the cleanup from a clean unit of work.
    $entityManager->clear();
    $services->get('Omeka\AuthenticationService')->getStorage()
        ->write($entityManager->find(\Omeka\Entity\User::class, $userId ?? (int) $user->getId()));
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
        'delete leftovers whose title starts with "governance-batch-check fixture"');
}

printf("\n%d OK, %d FAIL\n", $passed, $failed);
exit($failed ? 1 : 0);
