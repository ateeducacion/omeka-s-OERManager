<?php
// CLI only: these harnesses bootstrap Omeka and this one writes the catalogue,
// so it must never run over HTTP.
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

/**
 * TASK-011: the batch AI proposal end to end, with no LLM cost. Runs the real
 * AiBatchProposeJob::perform() with a fake ProposeRunner (the host test fakes)
 * on four disposable REA, as a real `rea_curador`, and checks that the
 * proposal is stored private in each REA without moving `o:modified`, that
 * the queue filter finds it, that one batch runs at a time and that the
 * removal deletes it. Everything it creates is deleted in `finally`.
 *
 * WRITES the catalogue: run on a development or disposable catalogue only.
 *
 * Use, inside the container:
 *   php /var/www/html/volume/modules/OERManager/test/container/batch-ai-check.php --write <curatorEmail>
 *   php …/batch-ai-check.php --write --disposable-curator
 *
 * `--disposable-curator` creates a throwaway `rea_curador` (inactive, no
 * password), runs as that user and deletes it at the end, for a catalogue
 * that has no curator yet.
 *
 * Exit 1 if a check fails, 2 on wrong usage.
 */

use OERManager\Job\AiBatchProposeJob;
use OERManager\Service\Ai\AiCataloguer;
use OERManager\Service\Ai\BatchProposeEligibility;
use OERManager\Service\Ai\ContextDistiller;
use OERManager\Service\Ai\PromptBuilder;
use OERManager\Service\Ai\ProposalStore;
use OERManager\Service\Ai\ProposeRunner;
use OERManager\Service\Ai\StoredProposal;
use OERManager\Service\Ai\StoredProposals;
use OERManager\Service\Content\ContentExtractor;
use OERManager\Service\Content\MediaSourceInterface;
use OERManager\Service\Content\MediaVisionExtractor;
use OERManager\Service\Governance\BatchJobLookup;
use OERManager\Service\GovernanceSettings;
use OERManager\Service\Workflow\WorkflowStatus;
use OERManager\Test\Service\Ai\FakeClassifier;
use OERManager\Test\Service\Ai\FakeLlmClient;

$args = array_slice($argv, 1);
$write = in_array('--write', $args, true);
$disposable = in_array('--disposable-curator', $args, true);
$email = (string) (array_values(array_filter($args, static fn ($a) => !str_starts_with($a, '--')))[0] ?? '');
if (!$write || ('' === $email && !$disposable)) {
    fwrite(STDERR, "usage: batch-ai-check.php --write <curatorEmail>|--disposable-curator  (writes the catalogue)\n");
    exit(2);
}

require '/var/www/html/bootstrap.php';
$application = Omeka\Mvc\Application::init(require '/var/www/html/application/config/application.config.php');
$services = $application->getServiceManager();
require __DIR__ . '/cur-fixture.php';
require __DIR__ . '/../Service/Ai/FakeLlmClient.php';
require __DIR__ . '/../Service/Ai/FakeClassifier.php';

$disposableUser = null;
if ($disposable) {
    $email = 'batch-ai-check-' . bin2hex(random_bytes(4)) . '@example.invalid';
    $disposableUser = new \Omeka\Entity\User();
    $disposableUser->setEmail($email);
    $disposableUser->setName('batch-ai-check (disposable)');
    $disposableUser->setRole('rea_curador');
    $disposableUser->setIsActive(false);
    $services->get('Omeka\EntityManager')->persist($disposableUser);
    $services->get('Omeka\EntityManager')->flush();
}
$user = curAuthenticate($services, $email);
if ('rea_curador' !== $user->getRole()) {
    fwrite(STDERR, "the user must have the rea_curador role\n");
    exit(2);
}

$api = $services->get('Omeka\ApiManager');
$em = $services->get('Omeka\EntityManager');
$eligibility = $services->get(BatchProposeEligibility::class);
$proposals = $services->get(StoredProposals::class);
$failed = 0;
$check = static function (bool $ok, string $label, string $detail = '') use (&$failed): void {
    echo '  ' . ($ok ? 'OK  ' : 'FAIL') . ' ' . $label . ($ok || '' === $detail ? '' : ': ' . $detail) . "\n";
    $failed += $ok ? 0 : 1;
};

$marker = curMarker('batch-ai-check');
$created = [];
$jobEntity = null;
try {
    $status = static fn (string $text): array => curLiteral($api, WorkflowStatus::STATUS_TERM, $text);
    // The workflow status changes only through StatusGuard::run(), as the workflow actions do.
    $guard = $services->get(\OERManager\Service\Workflow\StatusGuard::class);
    $make = static function (string $label, string $state, bool $public) use ($services, $api, $marker, $status, $guard, &$created): int {
        $id = curCreateRea($services, $marker . ' ' . $label, [], 'batch-ai-check', $created);
        $guard->run(static fn () => $api->update(
            'items',
            $id,
            $status($state) + ['o:is_public' => $public],
            [],
            ['isPartial' => true]
        ));
        return $id;
    };
    $a = $make('A', WorkflowStatus::PROPOSED, false);
    $b = $make('B', WorkflowStatus::PROPOSED, false);
    $c = $make('C', WorkflowStatus::PROPOSED, false);
    $d = $make('D', WorkflowStatus::PUBLISHED, true);

    // C carries the REA template without its required fields: the API would refuse it.
    $templateId = GovernanceSettings::parseId($services->get('Omeka\Settings')->get(GovernanceSettings::REA_TEMPLATE_ID));
    if (null !== $templateId) {
        $em->find(\Omeka\Entity\Item::class, $c)
            ->setResourceTemplate($em->getReference(\Omeka\Entity\ResourceTemplate::class, $templateId));
        $em->flush();
    } else {
        echo "  SKIP template_invalid (no REA template configured)\n";
    }

    $split = $eligibility->split([$a, $b, $c, $d]);
    $check(
        [$a, $b, $c] === $split['enter'] && [$d] === $split['out_of_queue'] && [] === $split['has_proposal'],
        'preview_buckets',
        json_encode($split)
    );

    // The fake pipeline: one real curriculum term, no LLM call.
    $saber = (int) curFixture($services)['saber'];
    $prompt = new PromptBuilder();
    $fakeRunner = new ProposeRunner($api, new AiCataloguer(
        new ContentExtractor(),
        new MediaVisionExtractor(new FakeLlmClient(), $prompt, false),
        new ContextDistiller(new FakeLlmClient(['Resumen']), $prompt),
        new FakeClassifier(['lrmi:teaches' => [$saber]], ['lrmi:teaches' => [$saber => 'Prueba']]),
        new FakeClassifier([])
    ), $services->get(MediaSourceInterface::class));
    $services->setAllowOverride(true);
    $services->setService(ProposeRunner::class, $fakeRunner);

    $modifiedBefore = [];
    foreach ([$a, $b, $c] as $id) {
        $modifiedBefore[$id] = $em->find(\Omeka\Entity\Item::class, $id)->getModified()?->format(DATE_ATOM);
    }

    $jobEntity = new \Omeka\Entity\Job();
    $jobEntity->setOwner($user);
    $jobEntity->setClass(AiBatchProposeJob::class);
    $jobEntity->setStatus(\Omeka\Entity\Job::STATUS_IN_PROGRESS);
    $jobEntity->setStarted(new \DateTime('now'));
    $jobEntity->setArgs(['ids' => [$a, $b, $c]]);
    $em->persist($jobEntity);
    $em->flush();
    (new AiBatchProposeJob($jobEntity, $services))->perform();

    $state = $services->get(ProposalStore::class)->read((int) $jobEntity->getId());
    $stored = true;
    $detail = json_encode($state['tallies'] ?? $state);
    foreach ([$a, $b, $c] as $id) {
        $item = $em->find(\Omeka\Entity\Item::class, $id);
        $em->refresh($item);
        $marked = [];
        foreach ($item->getValues() as $value) {
            if (StoredProposal::isProposal((string) $value->getValue())) {
                $marked[] = $value;
            }
        }
        $ok = 1 === count($marked)
            && !$marked[0]->isPublic()
            && null !== StoredProposal::decode((string) $marked[0]->getValue())
            && $modifiedBefore[$id] === $item->getModified()?->format(DATE_ATOM);
        if (!$ok) {
            $stored = false;
            $detail .= " #$id";
        }
    }
    $check(
        $stored && 'completed' === ($state['status'] ?? '') && 3 === ($state['tallies']['proposed'] ?? 0),
        'job_stores_privately',
        $detail
    );

    $count = static fn (string $type): int => $api->search('items', [
        'id' => [$a, $b, $c, $d],
        'property' => [StoredProposal::searchRow($type)],
    ])->getTotalResults();
    $check(3 === $count('sw') && 1 === $count('nsw'), 'queue_filter', $count('sw') . '/' . $count('nsw'));

    $check([$a] === $eligibility->split([$a])['has_proposal'], 'skips_existing');

    $lookup = $services->get(BatchJobLookup::class);
    $jobEntity->setStatus(\Omeka\Entity\Job::STATUS_IN_PROGRESS);
    $jobEntity->setStarted(new \DateTime('now'));
    $em->flush();
    $running = $lookup->isActive(AiBatchProposeJob::class);
    $jobEntity->setStatus(\Omeka\Entity\Job::STATUS_COMPLETED);
    $em->flush();
    $check($running && !$lookup->isActive(AiBatchProposeJob::class), 'one_at_a_time');

    $removed = $proposals->remove($a);
    $check($removed && 2 === $count('sw'), 'removal');
} catch (\Throwable $e) {
    $errors = $e instanceof \Omeka\Api\Exception\ValidationException
        ? ' ' . json_encode($e->getErrorStore()->getErrors(), JSON_UNESCAPED_UNICODE)
        : '';
    $check(false, 'unexpected', get_class($e) . ': ' . $e->getMessage() . $errors . ' @ ' . $e->getFile() . ':' . $e->getLine());
} finally {
    // A failed write leaves the unit of work dirty: start the cleanup clean.
    $em->clear();
    curAuthenticate($services, $email);
    curDeleteAll($services, $created, $marker);
    if (null !== $jobEntity && null !== $jobEntity->getId()) {
        $services->get('Omeka\Connection')->delete('job', ['id' => $jobEntity->getId()]);
    }
    if (null !== $disposableUser) {
        $em->clear();
        $services->get('Omeka\Connection')->delete('user', ['id' => $disposableUser->getId()]);
    }
}

echo $failed ? "FAILED: $failed\n" : "ALL OK\n";
exit($failed ? 1 : 0);
