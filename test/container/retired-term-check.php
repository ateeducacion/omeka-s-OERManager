<?php
// CLI only: these harnesses bootstrap Omeka and some write to the catalogue,
// so they must never run over HTTP (security review of TASK-067).
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

/**
 * TASK-064 slice 5 (ADR-0022 §6): the integrity rule «REA linked to a retired
 * term», its cost, and the `cur:` pairs, panel and statistics on a REA
 * aligned to the real curriculum.
 *
 * Read only by default: resolves the retired set, measures the scalar query
 * of its ids (and, as a synthetic upper bound, the same query over the whole
 * curriculum item set), and measures IntegrityChecker with and without link
 * checks over the catalogue's REA (time and SQL statements).
 *
 * With --write it also creates, marks and deletes (also on failure):
 *  - one disposable REA, private, aligned through RecatalogService to 1º ESO
 *    and 2º ESO, Matemáticas · 1º ESO, one knowledge item and one criterion;
 *  - one disposable term in the retired set «Currículo · Retirados», with
 *    skos:changeNote and dcterms:isReplacedBy → that knowledge item;
 *  and links the REA to the retired term. Nothing else is written: no
 *  curriculum item, existing REA or ADR-0009 term is touched.
 *
 * Usage (inside the container):
 *   php retired-term-check.php <userEmail> [--write]
 */

chdir('/var/www/html');
require 'bootstrap.php';
require __DIR__ . '/cur-fixture.php';

use Doctrine\DBAL\Logging\DebugStack;
use OERManager\ColumnType\Curricular;
use OERManager\Service\Curriculum\ApiCurriculumTerms;
use OERManager\Service\Curriculum\CurModel;
use OERManager\Service\Curriculum\CurriculumTerms;
use OERManager\Service\Governance\CurricularGrouping;
use OERManager\Service\Governance\CurricularPairs;
use OERManager\Service\IntegrityChecker;
use OERManager\Service\ItemPanelData;

$application = \Omeka\Mvc\Application::init(require 'application/config/application.config.php');
$services = $application->getServiceManager();
$api = $services->get('Omeka\ApiManager');
$settings = $services->get('Omeka\Settings');

$email = (string) ($argv[1] ?? '');
$write = ($argv[2] ?? '') === '--write';
if ('' === $email) {
    fwrite(STDERR, "usage: php retired-term-check.php <userEmail> [--write]\n");
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

/** @return array{0:mixed,1:float,2:int} result, milliseconds, SQL statements */
function measure($services, callable $run): array
{
    $configuration = $services->get('Omeka\EntityManager')->getConnection()->getConfiguration();
    $logger = new DebugStack();
    $configuration->setSQLLogger($logger);
    $start = hrtime(true);
    $result = $run();
    $ms = (hrtime(true) - $start) / 1e6;
    $configuration->setSQLLogger(null);
    return [$result, $ms, count($logger->queries)];
}

$licence = $services->get('OERManager\Service\Governance\VocabEntries\Licence');
$freshChecker = static fn (): IntegrityChecker => new IntegrityChecker(
    $licence,
    new ApiCurriculumTerms($api, $settings)
);

echo "1. Retired set\n";
$terms = $services->get(CurriculumTerms::class);
$schemeId = $terms->schemeId();
$retiredSetId = $terms->retiredSetId();
check('curriculum and retired set resolved', null !== $schemeId && null !== $retiredSetId, "$schemeId / $retiredSetId");
if (null === $schemeId || null === $retiredSetId) {
    exit(1);
}

echo "\n2. Cost of the retired ids (one scalar query per request)\n";
[$ids, $ms, $sql] = measure($services, static fn () => (new ApiCurriculumTerms($api, $settings))->retiredIds());
printf("  real retired set: %d ids, %.1f ms, %d SQL (includes resolving scheme and set)\n", count($ids), $ms, $sql);
check('the real retired set is empty in 1.1.2', [] === $ids);
[$all, $ms, $sql] = measure($services, static fn () => $api->search(
    'items',
    CurModel::retiredMembersQuery((int) $schemeId),
    ['returnScalar' => 'id']
)->getContent());
printf("  synthetic upper bound (same query over the whole curriculum set): %d ids, %.1f ms, %d SQL\n", count($all), $ms, $sql);
check('the synthetic list is the whole curriculum (> 13 000 ids)', count($all) > 13000, (string) count($all));
$flipped = array_flip(array_map('intval', $all));
$start = hrtime(true);
$hits = 0;
for ($i = 0; $i < 100000; $i++) {
    $hits += isset($flipped[$i]) ? 1 : 0;
}
printf("  100 000 id lookups against it: %.2f ms\n", (hrtime(true) - $start) / 1e6);

echo "\n3. Cost of IntegrityChecker over the catalogue's REA (each scenario cold)\n";
$class = $api->search('resource_classes', ['term' => 'lrmi:LearningResource'])->getContent()[0];
$withoutRule = new IntegrityChecker($licence);
// One CurriculumTerms per scenario run, as one request has: the first row
// with an alignment link pays the retired-id resolution, the rest reuse it.
$scenarios = [
    'master view, no retired rule' => static fn (): callable => static fn ($item) => $withoutRule->check($item, false),
    'master view, retired rule' => static function () use ($freshChecker): callable {
        $checker = $freshChecker();
        return static fn ($item) => $checker->check($item, false);
    },
    'drawer/save, retired rule' => static function () use ($freshChecker): callable {
        $checker = $freshChecker();
        return static fn ($item) => $checker->check($item, true);
    },
];
/** Cold run: empty unit of work, items read again, then the scenario measured. */
$cold = static function (array $ids, callable $scenario) use ($services, $api, $email): array {
    curClear($services, $email);
    $items = $ids ? $api->search('items', ['id' => $ids, 'page' => 1, 'per_page' => count($ids)])->getContent() : [];
    foreach ($items as $item) {
        $item->values();
    }
    return measure($services, static fn () => array_map($scenario(), $items));
};
$reaIds = array_map('intval', array_keys($api->search(
    'items',
    ['resource_class_id' => $class->id(), 'page' => 1, 'per_page' => 25],
    ['returnScalar' => 'id']
)->getContent()));
foreach ($scenarios as $label => $scenario) {
    [, $ms, $sql] = $cold($reaIds, $scenario);
    printf("  %-30s %d REA: %.1f ms, %d SQL\n", $label, count($reaIds), $ms, $sql);
}
echo "  (values() is loaded before measuring, as the master view has it; the retired-rule rows include\n"
    . "  resolving the curriculum, the retired set and its ids once)\n";

if (!$write) {
    printf("\n%d checks, %d failures (read only; --write adds the disposable REA and retired term)\n", $checks, $failures);
    exit($failures ? 1 : 0);
}

echo "\n4. Disposable REA aligned to cur:\n";
$fixture = curFixture($services);
check('fixture nodes found', !in_array(0, $fixture, true), json_encode($fixture));
$marker = curMarker('retired-term-check');
$created = [];
try {
    $reaId = curCreateRea($services, $marker, [
        'lrmi:educationalLevel' => [$fixture['S01'], $fixture['S02']],
        'schema:about' => [$fixture['SMAT01']],
        'lrmi:teaches' => [$fixture['saber']],
        'lrmi:assesses' => [$fixture['criterio']],
    ], $user->getName(), $created);
    printf("  created disposable REA #%d\n", $reaId);
    echo "  cost on this REA (5 alignment links), each scenario cold:\n";
    foreach ($scenarios as $label => $scenario) {
        [, $ms, $sql] = $cold([$reaId], $scenario);
        printf("    %-30s %.1f ms, %d SQL\n", $label, $ms, $sql);
    }
    curClear($services, $email);
    $rea = $api->read('items', $reaId)->getContent();

    $pairs = CurricularPairs::of($rea);
    check(
        'pairs: Matemáticas / 1º ESO, orphan 2º ESO',
        [['subject' => 'Matemáticas', 'course' => '1º ESO', 'isLiteral' => false]] === $pairs['pairs']
            && ['2º ESO'] === $pairs['orphanCourses'],
        json_encode($pairs, JSON_UNESCAPED_UNICODE)
    );
    $html = (string) (new Curricular())->renderContent($services->get('ViewRenderer'), $rea, []);
    check(
        'Curricular cell shows the subject pill with its course and the orphan course',
        str_contains($html, '<span class="oer-curricular-subject">Matemáticas</span>')
            && str_contains($html, '1º ESO') && str_contains($html, 'oer-curricular-orphan'),
        strip_tags($html)
    );

    $panel = $services->get(ItemPanelData::class)->forItem($rea)['alignment'];
    $group = $panel['groups'][0] ?? [];
    check(
        'panel: one group, 1º ESO, with the subject, the knowledge item and the criterion',
        1 === count($panel['groups']) && $fixture['S01'] === ($group['courseId'] ?? 0)
            && ['Matemáticas · 1º ESO'] === $group['subjects']
            && 1 === count($group['teaches']) && 1 === count($group['assesses']),
        json_encode($panel, JSON_UNESCAPED_UNICODE)
    );
    check(
        'panel: 2º ESO is an orphan course without subject',
        [['term' => 'lrmi:educationalLevel', 'title' => '2º ESO',
            'reason' => CurricularGrouping::REASON_UNSUPPORTED_COURSE]] === $panel['orphans']
    );

    echo "\n5. Retired term rule\n";
    $checker = $freshChecker();
    $codes = array_column($checker->check($rea, true)->getIssues(), 'code');
    check('no retired_term before the link', !in_array('retired_term', $codes, true), json_encode($codes));

    $retiredTerm = curCreateRetiredTerm(
        $services,
        $marker,
        $fixture['retiredSet'],
        $fixture['saberClass'],
        $fixture['saber'],
        $created
    );
    printf("  created disposable retired term #%d in set #%d\n", $retiredTerm, $fixture['retiredSet']);
    try {
        $services->get(\OERManager\Service\RecatalogService::class)->apply($reaId, [
            'lrmi:teaches' => [$fixture['saber'], $retiredTerm],
        ], $user->getName());
        check('the re-cataloguer refuses a new link to a retired term', false);
    } catch (\RuntimeException $e) {
        check('the re-cataloguer refuses a new link to a retired term', true, $e->getMessage());
    }
    // An existing link (made before the term was retired) is simulated by a
    // direct append on the disposable REA only.
    $api->update('items', $reaId, curLink($api, 'lrmi:teaches', $retiredTerm), [], [
        'isPartial' => true,
        'collectionAction' => 'append',
    ]);
    curClear($services, $email);
    $rea = $api->read('items', $reaId)->getContent();

    [$result, $ms, $sql] = measure($services, static fn () => $freshChecker()->check($rea, true));
    $retired = array_values(array_filter(
        $result->getIssues(),
        static fn (array $issue): bool => 'retired_term' === $issue['code']
    ));
    printf("  drawer/save check of the REA with the retired link: %.1f ms, %d SQL\n", $ms, $sql);
    check(
        'one retired_term warning on lrmi:teaches',
        1 === count($retired) && 'warning' === $retired[0]['severity'] && 'lrmi:teaches' === $retired[0]['field']
    );
    $successor = CurModel::label($api->read('items', $fixture['saber'])->getContent());
    check(
        'the warning names the retired term and its successor',
        isset($retired[0]) && str_contains($retired[0]['message'], '«TEST.1 · Término retirado de prueba»')
            && str_contains($retired[0]['message'], '«' . $successor . '»'),
        $retired[0]['message'] ?? ''
    );
    check(
        'the master view check (column, status filter) reports it too',
        in_array('retired_term', array_column($freshChecker()->check($rea, false)->getIssues(), 'code'), true)
    );
} finally {
    $left = curDeleteAll($services, $created, $marker);
    check('disposable REA and retired term deleted', 0 === $left, "$left left");
}

printf("\n%d checks, %d failures\n", $checks, $failures);
exit($failures ? 1 : 0);
