<?php
// CLI only: these harnesses bootstrap Omeka and some write to the catalogue,
// so they must never run over HTTP (security review of TASK-067).
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

/**
 * TASK-064 slice 4: the `curriculum-tree` endpoint of the re-cataloguing
 * panel on the real `cur:` curriculum.
 *
 * Read only by default: dispatches the controller action (as the browser's
 * GET does) for Matemáticas · 1º ESO (SMAT01) and checks the tree against
 * independent queries — 82 knowledge items and 18 criteria, every block, the
 * nesting by skos:broader, no retired term, blocks not selectable — plus the
 * input validation (wrong class, missing id, too many subjects), one tree per
 * subject with two subjects, its cost (time and SQL) and the ACL.
 *
 * With --write it also creates, marks and deletes (also on failure):
 *  - one disposable REA, private, aligned through RecatalogService to
 *    Matemáticas · 1º ESO; then two knowledge items of depth 2 taken from the
 *    tree are previewed and applied through the same service the panel's
 *    apply calls, and read back;
 *  - one disposable term in «Currículo · Retirados» (cur-fixture.php), to
 *    check the endpoint marks a retired linked id with its successor.
 * Nothing else is written: no curriculum item, existing REA or ADR-0009 term.
 *
 * Usage (inside the container):
 *   php curriculum-tree-check.php <userEmail> [--write]
 */

chdir('/var/www/html');
require 'bootstrap.php';
require __DIR__ . '/cur-fixture.php';

use Doctrine\DBAL\Logging\DebugStack;
use Laminas\Mvc\MvcEvent;
use Laminas\Router\RouteMatch;
use OERManager\Controller\Admin\IndexController;
use OERManager\Service\Curriculum\ApiCurriculumTerms;
use OERManager\Service\Curriculum\CurModel;
use OERManager\Service\Curriculum\CurriculumTerms;
use OERManager\Service\RecatalogService;

$application = \Omeka\Mvc\Application::init(require 'application/config/application.config.php');
$services = $application->getServiceManager();
$api = $services->get('Omeka\ApiManager');

$email = (string) ($argv[1] ?? '');
$write = ($argv[2] ?? '') === '--write';
if ('' === $email) {
    fwrite(STDERR, "usage: php curriculum-tree-check.php <userEmail> [--write]\n");
    exit(2);
}
$user = curAuthenticate($services, $email);
printf("authenticated as %s (%s); mode: %s\n\n", $user->getEmail(), $user->getRole(), $write ? 'write' : 'read only');

$failures = 0;
$checks = 0;
function check(string $label, bool $ok, string $detail = ''): void
{
    global $failures, $checks;
    $checks++;
    $failures += $ok ? 0 : 1;
    printf("  [%s] %s%s\n", $ok ? ' OK ' : 'FAIL', $label, '' === $detail ? '' : " — $detail");
}

/** GET curriculum-tree with $query, as the panel sends it; [data, ms, SQL]. */
function tree($application, array $query): array
{
    $services = $application->getServiceManager();
    // build(), not get(): a fresh controller with the services as they are now.
    $controller = $services->get('ControllerManager')->build(IndexController::class);
    $event = new MvcEvent();
    $event->setApplication($application);
    $event->setRouteMatch(new RouteMatch(['action' => 'curriculum-tree']));
    $event->setRouter($services->get('HttpRouter'));
    $controller->setEvent($event);
    $request = new \Laminas\Http\Request();
    $request->setMethod('GET');
    $request->getQuery()->fromArray($query);
    $configuration = $services->get('Omeka\EntityManager')->getConnection()->getConfiguration();
    $logger = new DebugStack();
    $configuration->setSQLLogger($logger);
    $start = hrtime(true);
    $view = $controller->dispatch($request, new \Laminas\Http\Response());
    $ms = (hrtime(true) - $start) / 1e6;
    $configuration->setSQLLogger(null);
    // Through JSON, as the browser receives it.
    $data = json_decode(json_encode($view->getVariables()), true);
    return [$data, $ms, count($logger->queries)];
}

/** Every node of a tree, with its depth below the root (block = 0). */
function nodes(array $tree, int $depth = 0): array
{
    $out = [];
    foreach ($tree as $node) {
        $out[] = $node + ['depth' => $depth];
        $out = array_merge($out, nodes($node['children'] ?? [], $depth + 1));
    }
    return $out;
}

/**
 * Ids of a class under a course subject, built by hand (not with CurModel) so
 * the check does not share the code it checks. $current adds `nex` on
 * skos:changeNote; without it the count includes retired terms.
 */
function independentIds($api, int $scheme, int $classId, int $subject, bool $current): array
{
    $query = [
        'item_set_id' => $scheme,
        'resource_class_id' => $classId,
        'property' => [['property' => 'cur:materiaCurso', 'type' => 'res', 'text' => (string) $subject]],
    ];
    if ($current) {
        $query['property'][] = ['property' => 'skos:changeNote', 'type' => 'nex', 'joiner' => 'and'];
    }
    $ids = array_map('intval', (array) $api->search('items', $query, ['returnScalar' => 'id'])->getContent());
    sort($ids);
    return array_values($ids);
}

$terms = $services->get(CurriculumTerms::class);
$fixture = curFixture($services);
$scheme = $fixture['scheme'];
$class = static fn (string $term): int => (int) $terms->classId($term);
$smat01 = $fixture['SMAT01'];
check('SMAT01 and SMAT02 found', $smat01 > 0 && $fixture['SMAT02'] > 0, json_encode($fixture));

echo "\n1. Tree of Matemáticas · 1º ESO (SMAT01)\n";
[$data, $ms, $sql] = tree($application, ['about' => [(string) $smat01]]);
printf("  curriculum-tree: %.1f ms, %d SQL (cold for this request)\n", $ms, $sql);
[, $warmMs, $warmSql] = tree($application, ['about' => [(string) $smat01]]);
printf("  again in the same request: %.1f ms, %d SQL\n", $warmMs, $warmSql);
$t = $data['trees'][0] ?? [];
check(
    'one tree, subject Matemáticas · 1º ESO, nothing invalid or retired',
    1 === count($data['trees']) && 'Matemáticas · 1º ESO' === ($t['subject']['title'] ?? '')
        && [] === $data['invalid'] && [] === $data['retired'],
    json_encode($t['subject'] ?? null, JSON_UNESCAPED_UNICODE)
);

$expect = [
    'lrmi:teaches' => [CurModel::BLOQUE_SABERES, CurModel::SABER, 82],
    'lrmi:assesses' => [CurModel::BLOQUE_COMPETENCIAL, CurModel::CRITERIO, 18],
];
$depth2 = [];
foreach ($expect as $dimension => [$blockClass, $leafClass, $count]) {
    $all = nodes($t[$dimension] ?? []);
    $leaves = array_values(array_filter($all, static fn ($n) => $n['selectable']));
    $blocks = array_values(array_filter($all, static fn ($n) => !$n['selectable']));
    $leafIds = array_column($leaves, 'id');
    sort($leafIds);
    $blockIds = array_column($blocks, 'id');
    sort($blockIds);
    $maxDepth = $leaves ? max(array_column($leaves, 'depth')) : 0;
    printf("  %s: %d blocks, %d selectable, deepest level %d\n", $dimension, count($blocks), count($leaves), $maxDepth);
    check("$dimension: $count selectable terms", $count === count($leaves), (string) count($leaves));
    check(
        "$dimension: exactly the current $leafClass of SMAT01 (independent query)",
        $leafIds === independentIds($api, $scheme, $class($leafClass), $smat01, true)
    );
    check(
        "$dimension: every $blockClass of SMAT01, as roots, not selectable",
        $blockIds === independentIds($api, $scheme, $class($blockClass), $smat01, true)
            && [] !== $blocks && 0 === max(array_column($blocks, 'depth'))
    );
    check(
        "$dimension: no orphan — every root is a block",
        [] === array_filter($t[$dimension], static fn ($n) => $n['selectable'])
    );
    $everything = independentIds($api, $scheme, $class($leafClass), $smat01, false);
    check(
        "$dimension: no retired term (all: " . count($everything) . ', current: ' . count($leafIds) . ')',
        [] === array_intersect($leafIds, $terms->retiredIds())
    );
    check(
        "$dimension: labels are «notation · text», definitions present",
        [] === array_filter($leaves, static fn ($n) => !str_starts_with($n['title'], $n['notation'] . ' ·')
            || '' === trim($n['definition']))
    );
    if ('lrmi:teaches' === $dimension) {
        check('knowledge nests below its block (depth ≥ 2)', $maxDepth >= 2, (string) $maxDepth);
        $depth2 = array_values(array_filter($leaves, static fn ($n) => 2 === $n['depth']));
        check(
            'a depth-2 item hangs from a depth-1 knowledge item (skos:broader)',
            [] !== $depth2 && in_array(
                $depth2[0]['parentId'],
                array_column(array_filter($leaves, static fn ($n) => 1 === $n['depth']), 'id'),
                true
            )
        );
    } else {
        check('criteria hang directly from their block', 1 === $maxDepth);
    }
}

echo "\n2. Input validation and several subjects\n";
[$data] = tree($application, ['about' => [(string) $smat01, (string) $fixture['SMAT02']]]);
check(
    'two subjects: one tree each, in order',
    [$smat01, $fixture['SMAT02']] === array_column(array_column($data['trees'], 'subject'), 'id')
);
[$data] = tree($application, ['about' => [(string) $fixture['S01'], (string) $fixture['saber'], '999999999', 'x']]);
check(
    'a course, a knowledge item and a missing id are refused, with no tree',
    [] === $data['trees'] && [$fixture['S01'], $fixture['saber'], 999999999] === $data['invalid'],
    json_encode($data['invalid'])
);
[$data] = tree($application, ['about' => array_map('strval', range(1, 11))]);
check('more than 10 subjects: refused', 'too_many' === ($data['error'] ?? '') && [] === $data['trees']);
[$data] = tree($application, []);
check('no subject: no tree', [] === $data['trees'] && [] === $data['invalid']);

echo "\n3. ACL\n";
$acl = $services->get('Omeka\Acl');
foreach (['editor' => true, 'reviewer' => true, 'site_admin' => true, 'author' => false, 'researcher' => false] as $role => $allowed) {
    check(
        "$role " . ($allowed ? 'may' : 'may not') . ' read the tree',
        $allowed === $acl->isAllowed($role, IndexController::class, 'curriculum-tree')
    );
}

if (!$write) {
    printf("\n%d checks, %d failures (read only; --write adds the disposable REA and retired term)\n", $checks, $failures);
    exit($failures ? 1 : 0);
}

echo "\n4. Disposable REA: knowledge of depth 2 chosen from the tree\n";
$marker = curMarker('curriculum-tree-check');
$created = [];
try {
    $reaId = curCreateRea($services, $marker, ['schema:about' => [$smat01]], $user->getName(), $created);
    printf("  created disposable REA #%d\n", $reaId);
    $chosen = array_slice(array_column($depth2, 'id'), 0, 2);
    sort($chosen);
    $recatalog = $services->get(RecatalogService::class);
    $diff = $recatalog->preview($reaId, ['lrmi:teaches' => $chosen]);
    check(
        'preview: the two depth-2 items are added, none invalid',
        2 === count($chosen) && [] === ($diff['lrmi:teaches']['invalid'] ?? null)
            && $chosen === array_values(array_map('intval', $diff['lrmi:teaches']['added'] ?? [])),
        json_encode($diff['lrmi:teaches'] ?? null, JSON_UNESCAPED_UNICODE)
    );
    $recatalog->apply($reaId, ['lrmi:teaches' => $chosen], $user->getName());
    curClear($services, $email);
    $rea = $api->read('items', $reaId)->getContent();
    $linked = array_column(CurModel::refs($rea, 'lrmi:teaches'), 'id');
    sort($linked);
    check('read back: lrmi:teaches holds exactly the chosen items', $chosen === $linked, json_encode($linked));
    check(
        'read back: schema:about kept',
        [$smat01] === array_column(CurModel::refs($rea, 'schema:about'), 'id')
    );
    [$data] = tree($application, ['about' => [(string) $smat01], 'linked' => array_map('strval', $linked)]);
    $selectable = array_column(array_filter(nodes($data['trees'][0]['lrmi:teaches']), static fn ($n) => $n['selectable']), 'id');
    check(
        'the panel finds the linked items in the tree (it would check them), none retired',
        [] === array_diff($linked, $selectable) && [] === $data['retired']
    );

    $retiredTerm = curCreateRetiredTerm(
        $services,
        $marker,
        $fixture['retiredSet'],
        $fixture['saberClass'],
        $chosen[0],
        $created
    );
    printf("  created disposable retired term #%d in set #%d\n", $retiredTerm, $fixture['retiredSet']);
    curClear($services, $email);
    // The retired ids are cached for the request (ApiCurriculumTerms) and this
    // script is one request: a new request would see the new member.
    $services->setAllowOverride(true);
    $services->setService(CurriculumTerms::class, new ApiCurriculumTerms($api, $services->get('Omeka\Settings')));
    [$data] = tree($application, [
        'about' => [(string) $smat01],
        'linked' => array_map('strval', array_merge($linked, [$retiredTerm])),
    ]);
    $successor = CurModel::label($api->read('items', $chosen[0])->getContent());
    check(
        'a linked retired term comes back marked, with its successor, and is not in the tree',
        [['id' => $retiredTerm, 'title' => 'TEST.1 · Término retirado de prueba', 'replacedBy' => [$successor]]]
            === $data['retired']
            && !in_array($retiredTerm, array_column(nodes($data['trees'][0]['lrmi:teaches']), 'id'), true),
        json_encode($data['retired'], JSON_UNESCAPED_UNICODE)
    );
} finally {
    $left = curDeleteAll($services, $created, $marker);
    check('disposable REA and retired term deleted', 0 === $left, "$left left");
}

printf("\n%d checks, %d failures\n", $checks, $failures);
exit($failures ? 1 : 0);
