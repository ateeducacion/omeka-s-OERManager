<?php

// CLI only: these harnesses bootstrap Omeka and some write to the catalogue,
// so they must never run over HTTP (security review of TASK-067).
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

/**
 * TASK-065 (A5): why the curation history shows `#<id>`. READ ONLY. For every
 * REA with curation events, lists each id the history references and whether
 * the authenticated user can read it, or the item no longer exists. Also counts
 * governance (typed) events, which the history painted as `#1` before TASK-065,
 * and the events that already store labels.
 *
 * The marker lives in the event value's annotation, not in its text, so items
 * are found by "has any dcterms:provenance" and filtered by decoded events.
 *
 * Usage (inside the container):
 *   php /var/www/html/modules/OERManager/test/container/history-titles-check.php <userEmail>
 */

chdir('/var/www/html');
require 'bootstrap.php';
require __DIR__ . '/cur-fixture.php';

use OERManager\Service\CurationEvent;
use OERManager\Service\Governance\CurationHistory;
use OERManager\Service\RecatalogService;

$application = \Omeka\Mvc\Application::init(require 'application/config/application.config.php');
$services = $application->getServiceManager();

$email = (string) ($argv[1] ?? '');
if ('' === $email) {
    fwrite(STDERR, "usage: php history-titles-check.php <userEmail>\n");
    exit(2);
}
$user = curAuthenticate($services, $email);
printf("authenticated as %s; read only\n\n", $user->getRole());

$api = $services->get('Omeka\ApiManager');
$em = $services->get('Omeka\EntityManager');
$recatalog = $services->get(RecatalogService::class);

$ids = $api->search('items', ['property' => [[
    'property' => 'dcterms:provenance', 'type' => 'ex',
]]], ['returnScalar' => 'id'])->getContent();

$withEvents = 0;
$events = 0;
$typed = 0;
$labelled = 0;
$missing = [];
$denied = [];
foreach ($ids as $itemId) {
    $itemEvents = $recatalog->events((int) $itemId);
    if (!$itemEvents) {
        continue;
    }
    $withEvents++;
    foreach ($itemEvents as $event) {
        $events++;
        if (CurationEvent::isTyped($event['payload'])) {
            $typed++;
            continue;
        }
        foreach ($event['payload']['terms'] ?? [] as $entry) {
            if (!empty($entry['labels'])) {
                $labelled++;
                break;
            }
        }
    }
    foreach (CurationHistory::referencedIds($itemEvents) as $id) {
        try {
            $api->read('items', $id);
        } catch (\Exception $e) {
            if (null === $em->find(\Omeka\Entity\Item::class, $id)) {
                $missing[$id] = true;
            } else {
                $denied[$id] = true;
            }
        }
    }
}

printf("REA with events: %d (of %d with dcterms:provenance)\n", $withEvents, count($ids));
printf("events: %d; governance (typed): %d; with stored labels: %d\n", $events, $typed, $labelled);
printf("referenced ids that no longer exist: %d %s\n", count($missing), implode(',', array_keys($missing)));
printf("referenced ids this user cannot read: %d %s\n", count($denied), implode(',', array_keys($denied)));
