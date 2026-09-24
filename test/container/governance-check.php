<?php

/**
 * Container harness for TASK-028 slice 3b (RF-015, ADR-0020): licence and
 * authorship editing through the shared curation ledger.
 *
 * 1. READ-ONLY (always): coverage of the five governance fields over the real
 *    REA; the URIs of the configured licence vocabulary; the three-state
 *    licence classification and its integrity warning; the `governance-apply`
 *    privilege per role; and GovernanceService::read() on every REA.
 * 2. WRITE (only with --write): DISPOSABLE FIXTURES ONLY. Without an item id
 *    the harness creates its own private REA (title, description and one
 *    alignment link copied from a real REA — the link does not modify the
 *    curriculum item it points to) and deletes it at the end, so no catalog
 *    item is touched. Passing an item id writes to THAT item and leaves its
 *    ledger with the harness's events: use it only on a fixture.
 *
 * Usage, from inside the container:
 *   php /var/www/html/modules/OERManager/test/container/governance-check.php
 *   php /var/www/html/modules/OERManager/test/container/governance-check.php --write <email> [item_id]
 *
 * Exits 1 if any check fails.
 */

require '/var/www/html/bootstrap.php';

use OERManager\Controller\Admin\IndexController;
use OERManager\Service\Curation\CurationWriter;
use OERManager\Service\Curation\UndoRouter;
use OERManager\Service\CurationEvent;
use OERManager\Service\Governance\GovernanceFields;
use OERManager\Service\Governance\LicenceStatus;
use OERManager\Service\GovernanceService;
use OERManager\Service\IntegrityChecker;
use OERManager\Service\MasterViewQuery;
use OERManager\Service\RecatalogService;

$application = Omeka\Mvc\Application::init(require '/var/www/html/application/config/application.config.php');
$services = $application->getServiceManager();
$api = $services->get('Omeka\ApiManager');
/** @var GovernanceService $governance */
$governance = $services->get(GovernanceService::class);
/** @var IntegrityChecker $checker */
$checker = $services->get(IntegrityChecker::class);
$licenceVocab = $services->get('OERManager\Service\Governance\VocabEntries\Licence');

$passed = 0;
$failed = 0;
$skipped = 0;

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

function skip(string $label, string $why): void
{
    global $skipped;
    $skipped++;
    echo "  SKIP $label — $why\n";
}

$args = array_slice($argv, 1);
$writeMode = '--write' === ($args[0] ?? '');
$contributor = 'governance-check';

if ($writeMode) {
    // Authenticate BEFORE reading: without an identity the API only sees
    // public items and cannot write (same pattern as licence-check.php).
    $email = (string) ($args[1] ?? '');
    $entityManager = $services->get('Omeka\EntityManager');
    $user = '' === $email ? null
        : $entityManager->getRepository(\Omeka\Entity\User::class)->findOneBy(['email' => $email]);
    if (null === $user) {
        fwrite(STDERR, "usage: php governance-check.php --write <userEmail> [item_id] (user not found: '$email')\n");
        exit(2);
    }
    $services->get('Omeka\AuthenticationService')->getStorage()->write($user);
    $contributor = $user->getEmail();
    printf("authenticated as %s (%s)\n", $user->getEmail(), $user->getRole());
}

$classes = $api->search('resource_classes', ['term' => MasterViewQuery::LEARNING_RESOURCE_CLASS_TERM])->getContent();
if (!$classes) {
    fwrite(STDERR, "The lrmi:LearningResource class does not exist in this installation.\n");
    exit(1);
}
$classId = (int) $classes[0]->id();
$items = $api->search('items', ['resource_class_id' => $classId])->getContent();
if (!$items) {
    fwrite(STDERR, "The catalog has no REA.\n");
    exit(1);
}

echo "\n1. Read-only\n";

// 1.1 Coverage of the five fields over the real REA.
$coverage = array_fill_keys(GovernanceFields::all(), 0);
$statuses = [];
$readFailures = [];
$statusMismatch = [];
$integrityMismatch = [];
$vocabUris = $licenceVocab->uris();
foreach ($items as $item) {
    try {
        $read = $governance->read($item);
    } catch (\Throwable $e) {
        $readFailures[] = '#' . $item->id() . ' ' . $e->getMessage();
        continue;
    }
    foreach (GovernanceFields::all() as $term) {
        if ([] !== ($read['values'][$term] ?? [])) {
            $coverage[$term]++;
        }
    }
    $status = $read['licenceStatus'];
    $statuses[$status] = ($statuses[$status] ?? 0) + 1;
    if ($status !== LicenceStatus::of($read['values'][GovernanceFields::LICENCE] ?? [], $vocabUris)) {
        $statusMismatch[] = (int) $item->id();
    }
    $codes = array_column($checker->check($item, false)->getIssues(), 'code');
    if ((LicenceStatus::OUTSIDE_VOCAB === $status) !== in_array('license_not_in_vocab', $codes, true)) {
        $integrityMismatch[] = (int) $item->id();
    }
}
printf("   REA=%d  %s\n", count($items), implode('  ', array_map(
    static fn (string $term, int $n): string => "$term=$n",
    array_keys($coverage),
    $coverage
)));
ksort($statuses);
printf("   licence states: %s\n", implode('  ', array_map(
    static fn (string $state, int $n): string => "$state=$n",
    array_keys($statuses),
    $statuses
)));
check('GovernanceService::read() answers for every REA', [] === $readFailures, implode('; ', $readFailures));
check('read() classifies the licence exactly as LicenceStatus::of()', [] === $statusMismatch,
    'differ: #' . implode(', #', $statusMismatch));
check('license_not_in_vocab appears exactly on the REA classified outside_vocab', [] === $integrityMismatch,
    'differ: #' . implode(', #', $integrityMismatch));

// 1.2 The configured licence vocabulary.
if (null === $vocabUris) {
    skip('the licence vocabulary resolves', 'oermanager_licence_vocab_id empty or pointing at a missing vocabulary');
} else {
    $notHttp = array_filter($vocabUris, static fn (string $uri): bool => !preg_match('~^https?://~i', $uri));
    check('the licence vocabulary carries URIs', [] !== $vocabUris, 'no entry has a URI');
    check('every vocabulary URI is http(s)', [] === $notHttp, implode(', ', $notHttp));
    printf("   vocabulary %s: %d URIs\n", (string) $licenceVocab->dataType(), count($vocabUris));
}

// 1.3 The three states, on the real vocabulary.
check('no licence classifies as missing', LicenceStatus::MISSING === LicenceStatus::of([], $vocabUris));
check('without a vocabulary the licence is unchecked',
    LicenceStatus::UNCHECKED === LicenceStatus::of([['type' => 'uri', 'uri' => 'https://example.org/x/']], null));
if ([] === ($vocabUris ?? [])) {
    skip('in_vocab / outside_vocab on the real vocabulary', 'no vocabulary URIs to classify against');
} else {
    $first = $vocabUris[0];
    check('a vocabulary URI classifies as in_vocab',
        LicenceStatus::IN_VOCAB === LicenceStatus::of([['type' => 'uri', 'uri' => $first]], $vocabUris));
    // canonicalUri()'s contract: host case and one trailing slash only.
    $host = (string) parse_url($first, PHP_URL_HOST);
    $variant = rtrim(substr_replace($first, strtoupper($host), (int) strpos($first, $host), strlen($host)), '/');
    check('the same URI with an upper-case host and no trailing slash still classifies as in_vocab',
        LicenceStatus::IN_VOCAB === LicenceStatus::of([['type' => 'uri', 'uri' => $variant]], $vocabUris), $variant);
    check('an unknown URI classifies as outside_vocab', LicenceStatus::OUTSIDE_VOCAB
        === LicenceStatus::of([['type' => 'uri', 'uri' => 'https://example.org/not-a-licence/']], $vocabUris));
}

// 1.4 ACL: governance-apply at the recatalog-apply level, never for author.
$acl = $services->get('Omeka\Acl');
foreach (['editor' => true, 'site_admin' => true, 'reviewer' => true, 'author' => false] as $role => $expected) {
    if (!$acl->hasRole($role)) {
        skip("governance-apply for $role", 'role does not exist in this installation');
        continue;
    }
    check(sprintf('governance-apply %s for %s', $expected ? 'granted' : 'denied', $role),
        $expected === $acl->isAllowed($role, IndexController::class, 'governance-apply'));
}

// 1.5 The read() contract on one real REA.
$sample = $governance->read($items[0]);
check('read() returns values, licenceStatus, options, notices and defaultRightsHolder',
    [] === array_diff(['values', 'licenceStatus', 'options', 'notices', 'defaultRightsHolder'], array_keys($sample)));
check('read() carries all five fields, in order', GovernanceFields::all() === array_keys($sample['values']));
check('read() offers the licence vocabulary entries', $licenceVocab->entries() === $sample['options']['licence']);
check('a notice appears exactly when the licence vocabulary does not resolve',
    (null === $licenceVocab->dataType()) === isset($sample['notices'][GovernanceFields::LICENCE]));

if (!$writeMode) {
    echo "\n$passed OK, $failed FAIL, $skipped SKIP\n";
    exit($failed > 0 ? 1 : 0);
}

echo "\n2. Write (disposable fixture)\n";

/** @var UndoRouter $router */
$router = $services->get(UndoRouter::class);
/** @var RecatalogService $recatalog */
$recatalog = $services->get(RecatalogService::class);
/** @var CurationWriter $writer */
$writer = $services->get(CurationWriter::class);

$ownFixture = !isset($args[2]);
if ($ownFixture) {
    // One alignment link copied from a real REA: the canary needs a
    // resource:item value, and linking does not modify the target.
    $link = null;
    foreach ($items as $item) {
        $teaches = $item->value('lrmi:teaches', ['type' => 'resource:item']);
        if (null !== $teaches) {
            $link = (int) $teaches->valueResource()->id();
            break;
        }
    }
    $pid = static fn (string $term): int => (int) $api->search('properties', ['term' => $term])->getContent()[0]->id();
    $data = [
        'o:is_public' => false,
        'o:resource_class' => ['o:id' => $classId],
        'dcterms:title' => [['type' => 'literal', 'property_id' => $pid('dcterms:title'),
            '@value' => 'governance-check fixture (safe to delete)']],
        'dcterms:description' => [['type' => 'literal', 'property_id' => $pid('dcterms:description'),
            '@value' => 'Temporary REA created by test/container/governance-check.php.']],
    ];
    if (null !== $link) {
        $data['lrmi:teaches'] = [['type' => 'resource:item', 'property_id' => $pid('lrmi:teaches'),
            'value_resource_id' => $link]];
    }
    $itemId = (int) $api->create('items', $data)->getContent()->id();
    printf("   created fixture REA #%d\n", $itemId);
} else {
    $itemId = (int) $args[2];
    printf("   using fixture REA #%d given on the command line\n", $itemId);
}

$load = static fn () => $api->read('items', $itemId)->getContent();

/** Everything outside the five governance fields and the ledger. */
$snapshot = static function ($item): array {
    $out = [];
    foreach ($item->values() as $term => $entry) {
        if (GovernanceFields::isGovernanceTerm($term) || 'dcterms:provenance' === $term) {
            continue;
        }
        foreach ($entry['values'] as $value) {
            $out[$term][] = [$value->type(), (string) $value->value(), (string) $value->uri(),
                $value->valueResource() ? (int) $value->valueResource()->id() : null];
        }
    }
    ksort($out);
    return $out;
};

$ledger = static fn ($item): int => count($item->value('dcterms:provenance', ['all' => true, 'default' => []]));

/** The provenance value holding the event stamped $when, or null. */
$eventValue = static function ($item, string $when) {
    foreach ($item->value('dcterms:provenance', ['all' => true, 'default' => []]) as $value) {
        $annotation = $value->valueAnnotation();
        if (null !== $annotation && $when === trim((string) $annotation->value('dcterms:modified'))) {
            return $value;
        }
    }
    return null;
};

$licence = $vocabUris[0] ?? 'https://creativecommons.org/licenses/by/4.0/';
$authors = ['Ana Fixture', 'Luis Fixture'];

try {
    $before = $load();
    $beforeRest = $snapshot($before);
    $beforeValues = $governance->read($before)['values'];
    $beforeLedger = $ledger($before);
    check('the fixture has a title, a description and an alignment link for the canary to watch',
        isset($beforeRest['dcterms:title'], $beforeRest['dcterms:description'], $beforeRest['lrmi:teaches']));

    // (a) Save a licence and two authors: nothing else on the item moves.
    $saved = $governance->apply($itemId, [
        GovernanceFields::LICENCE => [$licence],
        GovernanceFields::CREATOR => $authors,
    ], $contributor);
    check('(a) apply writes', true === ($saved['updated'] ?? null), json_encode($saved));
    $after = $load();
    check('(a) title, description and alignment untouched (ValueHydrator canary)', $beforeRest === $snapshot($after));
    $afterValues = $governance->read($after)['values'];
    check('(a) the licence is written', $licence === ($afterValues[GovernanceFields::LICENCE][0]['uri'] ?? null),
        json_encode($afterValues[GovernanceFields::LICENCE]));
    check('(a) both authors are written, in order', $authors === array_column($afterValues[GovernanceFields::CREATOR], 'value'));
    if (null !== $licenceVocab->dataType()) {
        check('(a) a vocabulary licence takes the vocabulary data type',
            $licenceVocab->dataType() === ($afterValues[GovernanceFields::LICENCE][0]['type'] ?? null));
    }

    // (b) Every written value is annotated with the event stamp.
    $when = (string) ($saved['event']['when'] ?? '');
    $stamps = [];
    foreach ([GovernanceFields::LICENCE, GovernanceFields::CREATOR] as $term) {
        foreach ($after->value($term, ['all' => true, 'default' => []]) as $value) {
            $annotation = $value->valueAnnotation();
            $stamps[] = null === $annotation ? '' : trim((string) $annotation->value('dcterms:modified'));
        }
    }
    check('(b) every written value carries dcterms:modified equal to the event stamp',
        3 === count($stamps) && [$when] === array_values(array_unique($stamps)), implode(' | ', $stamps));

    // (c) The event: private, v2, decodable, governance scope.
    $value = $eventValue($after, $when);
    check('(c) exactly one event appended', $beforeLedger + 1 === $ledger($after));
    check('(c) the event value is private', null !== $value && false === $value->isPublic());
    $last = $recatalog->lastEvent($itemId);
    check('(c) lastEvent() is that event', $when === ($last['when'] ?? null));
    check('(c) its payload is v2 and in governance scope', null !== $last
        && CurationEvent::isTyped($last['payload']) && 'governance' === CurationEvent::scopeOf($last['payload'])
        && CurationEvent::OP_GOVERNANCE === $last['payload']['op']);
    check('(c) the payload records only the two changed terms',
        [GovernanceFields::LICENCE, GovernanceFields::CREATOR] === array_keys($last['payload']['terms'] ?? []));

    // (d) Undo restores the exact previous values and writes its own event.
    $undone = $router->undo($itemId, $contributor);
    check('(d) undo writes', true === ($undone['updated'] ?? null), json_encode($undone));
    $restored = $load();
    check('(d) the five fields are back exactly as before', $beforeValues === $governance->read($restored)['values']);
    check('(d) the rest of the item is still untouched', $beforeRest === $snapshot($restored));
    $undoEvent = $recatalog->lastEvent($itemId);
    check('(d) undo appends its own event, pointing at the undone one', $beforeLedger + 2 === $ledger($restored)
        && CurationEvent::OP_UNDO === ($undoEvent['payload']['op'] ?? null)
        && $when === ($undoEvent['payload']['undoOf'] ?? null));

    // (e) Saving the same values twice writes nothing the second time.
    $governance->apply($itemId, [GovernanceFields::CREATOR => $authors], $contributor);
    $ledgerNow = $ledger($load());
    $again = $governance->apply($itemId, [GovernanceFields::CREATOR => $authors], $contributor);
    check('(e) an identical save answers unchanged', true === ($again['unchanged'] ?? null), json_encode($again));
    check('(e) and appends no event', $ledgerNow === $ledger($load()));

    // (f) A change made by another route makes undo stale.
    $creatorId = (int) $writer->propertyId(GovernanceFields::CREATOR);
    $writer->commit($itemId, [$creatorId], [GovernanceFields::CREATOR => [
        ['type' => 'literal', 'property_id' => $creatorId, '@value' => 'Written elsewhere'],
    ]]);
    $stale = $router->undo($itemId, $contributor);
    check('(f) undo answers stale', 'stale' === ($stale['error'] ?? null), json_encode($stale));
    check('(f) and names the drifted term', [GovernanceFields::CREATOR] === ($stale['terms'] ?? null));
    check('(f) and writes nothing', ['Written elsewhere']
        === array_column($governance->read($load())['values'][GovernanceFields::CREATOR], 'value'));

    // (g) The router's other branch: a curriculum event goes back to
    // RecatalogService, which undo-harness.php only reaches directly.
    $teaches = static fn ($item): array => array_map(
        static fn ($value): int => (int) $value->valueResource()->id(),
        $item->value('lrmi:teaches', ['all' => true, 'type' => 'resource:item', 'default' => []])
    );
    $linked = $teaches($load());
    $emptied = $recatalog->apply($itemId, ['lrmi:teaches' => []], $contributor);
    check('(g) a recatalogue that empties lrmi:teaches writes a curriculum event', [] === $teaches($load())
        && 'curriculum' === CurationEvent::scopeOf($recatalog->lastEvent($itemId)['payload'] ?? []),
        json_encode($emptied));
    $routed = $router->undo($itemId, $contributor);
    check('(g) the router sends it to RecatalogService and the link comes back',
        true === ($routed['updated'] ?? null) && $linked === $teaches($load()), json_encode($routed));
    check('(g) governance values are not touched by the curriculum undo', ['Written elsewhere']
        === array_column($governance->read($load())['values'][GovernanceFields::CREATOR], 'value'));
} finally {
    if ($ownFixture) {
        $api->delete('items', $itemId);
        $gone = [] === $api->search('items', ['id' => $itemId])->getContent();
        check("fixture REA #$itemId deleted", $gone);
    } else {
        echo "   fixture REA #$itemId left as the harness wrote it (its ledger keeps the harness events)\n";
    }
}

echo "\n$passed OK, $failed FAIL, $skipped SKIP\n";
exit($failed > 0 ? 1 : 0);
