<?php
// CLI only: these harnesses bootstrap Omeka, so they must never run over HTTP.
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

/**
 * TASK-070, read-only: «En cola» returns exactly the REA that
 * WorkflowStatus::inQueue() accepts, and as many as «Cola (N)» counts.
 * Writes nothing. Run on a development or disposable catalogue only.
 *
 * Use, inside the container:
 *   php /var/www/html/modules/OERManager/test/container/queue-check.php <userEmail>
 *
 * Exit 1 if a check fails.
 */

require '/var/www/html/bootstrap.php';

$application = Omeka\Mvc\Application::init(require '/var/www/html/application/config/application.config.php');
$services = $application->getServiceManager();
require __DIR__ . '/cur-fixture.php';

$email = (string) ($argv[1] ?? '');
if ('' === $email) {
    fwrite(STDERR, "usage: queue-check.php <userEmail>\n");
    exit(2);
}
curAuthenticate($services, $email);

$api = $services->get('Omeka\ApiManager');
$resolver = $services->get(OERManager\Service\IdConstraintResolver::class);
$counter = $services->get(OERManager\Service\Workflow\QueueCounter::class);
$base = $services->get(OERManager\Service\MasterViewQuery::class)->buildSearchParams([]);
$failed = 0;
$check = static function (bool $ok, string $label) use (&$failed): void {
    echo '  ' . ($ok ? 'OK  ' : 'FAIL') . ' ' . $label . "\n";
    $failed += $ok ? 0 : 1;
};

// Reference: every REA, decided one by one with the same rule the panel uses.
$expected = [];
foreach ($api->search('items', $base)->getContent() as $item) {
    $statusValue = $item->value(OERManager\Service\Workflow\WorkflowStatus::STATUS_TERM);
    $status = null === $statusValue
        ? null
        : OERManager\Service\Workflow\WorkflowStatus::normalize((string) $statusValue->value());
    if (OERManager\Service\Workflow\WorkflowStatus::inQueue($status, $item->isPublic())) {
        $expected[] = (int) $item->id();
    }
}
sort($expected);

$queue = $resolver->resolve(['status' => 'queue']);
$check(is_array($queue), 'status=queue is a constraint');
$check($queue === $expected, sprintf('En cola = inQueue() (%d REA)', count($expected)));
$check(count($expected) === $counter->count(), sprintf('Cola (N) = %d', $counter->count()));
$check(null === $resolver->resolve(['status' => 'proposed']), 'status=proposed needs no constraint');

exit($failed > 0 ? 1 : 0);
