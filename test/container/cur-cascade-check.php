<?php
// CLI only: these harnesses bootstrap Omeka and some write to the catalogue,
// so they must never run over HTTP (security review of TASK-067).
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

/**
 * TASK-064 (ADR-0022): the re-cataloguer cascade against the real `cur:`
 * curriculum loaded by EducationalCurriculum.
 *
 * Read only by default: stages in order, courses of a stage, course subjects
 * of a course, knowledge/criteria of a course subject, current terms only
 * (`skos:changeNote nex`), label filter. The curriculum items, the existing
 * REA and the ADR-0009 graph are never written.
 *
 * With --write it also creates TWO disposable REA, private and titled
 * «[OERManager TASK-064 · prueba desechable] …», uses them to check target
 * validation (another class is rejected, a valid write goes through) and the
 * `nex` operator on marked items, and deletes them at the end, also on failure.
 * Nothing else is written.
 *
 * Usage (inside the container):
 *   php cur-cascade-check.php <userEmail> [--write]
 */

chdir('/var/www/html');
require 'bootstrap.php';

use OERManager\Service\Curriculum\CurModel;
use OERManager\Service\Curriculum\CurriculumTerms;
use OERManager\Service\CurriculumSearch;
use OERManager\Service\RecatalogService;

$application = \Omeka\Mvc\Application::init(require 'application/config/application.config.php');
$services = $application->getServiceManager();
$api = $services->get('Omeka\ApiManager');
$terms = $services->get(CurriculumTerms::class);
$search = $services->get(CurriculumSearch::class);
$recatalog = $services->get(RecatalogService::class);

$email = (string) ($argv[1] ?? '');
$write = ($argv[2] ?? '') === '--write';
if ('' === $email) {
    fwrite(STDERR, "usage: php cur-cascade-check.php <userEmail> [--write]\n");
    exit(2);
}
$user = $services->get('Omeka\EntityManager')->getRepository(\Omeka\Entity\User::class)
    ->findOneBy(['email' => $email]);
if (null === $user) {
    fwrite(STDERR, "user not found: $email\n");
    exit(2);
}
$services->get('Omeka\AuthenticationService')->getStorage()->write($user);
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

/** Total of a query without fetching rows (per_page 1). */
function total($api, array $query): int
{
    $query['page'] = 1;
    $query['per_page'] = 1;
    return $api->search('items', $query)->getTotalResults();
}

/** First current term of a class with a given notation. */
function byNotation($api, int $schemeId, int $classId, string $notation): ?int
{
    $query = CurModel::termsQuery($schemeId, $classId);
    $query['property'][] = ['property' => CurModel::NOTATION, 'type' => 'eq', 'text' => $notation];
    $items = $api->search('items', $query)->getContent();
    return $items ? (int) reset($items)->id() : null;
}

echo "1. Curriculum resolution\n";
$schemeId = $terms->schemeId();
$retiredId = $terms->retiredSetId();
check('curriculum item set resolved', null !== $schemeId, 'id ' . var_export($schemeId, true));
check('retired set resolved by URI', null !== $retiredId, 'id ' . var_export($retiredId, true));
$classIds = [];
foreach ([CurModel::ETAPA, CurModel::CURSO, CurModel::MATERIA, CurModel::MATERIA_CURSO, CurModel::CRITERIO,
    CurModel::SABER] as $class) {
    $classIds[$class] = $terms->classId($class);
}
check('cur: classes resolved', !in_array(null, $classIds, true), json_encode($classIds));
if (null === $schemeId || in_array(null, $classIds, true)) {
    printf("\n%d checks, %d failures (cannot continue)\n", $checks, $failures);
    exit(1);
}
$version = $api->read('item_sets', $schemeId)->getContent()->value('dcterms:hasVersion');
printf("  curriculum version: %s\n", $version ? (string) $version : '?');

echo "\n2. Stages in pedagogical order\n";
$stages = $search->searchEtapas('');
check(
    'stages INF, PRI, ESO, BAC',
    ['INF', 'PRI', 'ESO', 'BAC'] === array_column($stages, 'notation'),
    implode(', ', array_column($stages, 'notation'))
);
$stageId = array_column($stages, 'id', 'notation');

echo "\n3. Courses of a stage\n";
$eso = $search->searchDimension('lrmi:educationalLevel', '', ['etapa' => $stageId['ESO'] ?? 0]);
check(
    'ESO courses S01..S04 in order',
    ['S01', 'S02', 'S03', 'S04'] === array_column($eso, 'notation'),
    implode(', ', array_column($eso, 'title'))
);
$priEso = $search->searchDimension('lrmi:educationalLevel', '', ['etapa' => [$stageId['ESO'] ?? 0,
    $stageId['PRI'] ?? 0]]);
check(
    'two stages merge in stage order (multiple cardinality)',
    ['P01', 'P02', 'P03', 'P04', 'P05', 'P06', 'S01', 'S02', 'S03', 'S04'] === array_column($priEso, 'notation'),
    implode(', ', array_column($priEso, 'notation'))
);
check('every ESO course points at the ESO stage', [$stageId['ESO']] === array_values(array_unique(
    array_column($eso, 'parentId')
)));
$s01 = (int) (array_column($eso, 'id', 'notation')['S01'] ?? 0);

echo "\n4. Course subjects of a course\n";
$subjects = $search->searchDimension('schema:about', '', ['level' => $s01], 300);
$expected = total($api, CurModel::withResource(
    CurModel::termsQuery($schemeId, $classIds[CurModel::MATERIA_CURSO]),
    CurModel::EDGE_CURSO,
    $s01
));
check('all course subjects of 1º ESO', count($subjects) === $expected && $expected > 0, count($subjects) . " / $expected");
check('labels carry the course', [] === array_filter(
    $subjects,
    static fn (array $row): bool => !str_ends_with($row['title'], '· 1º ESO')
));
$smat01 = byNotation($api, $schemeId, $classIds[CurModel::MATERIA_CURSO], 'SMAT01');
check('SMAT01 is among them', in_array($smat01, array_column($subjects, 'id'), true), 'id ' . $smat01);

echo "\n5. Knowledge and criteria of a course subject\n";
foreach (['lrmi:teaches' => CurModel::SABER, 'lrmi:assesses' => CurModel::CRITERIO] as $dimension => $class) {
    $rows = $search->searchDimension($dimension, '', ['about' => $smat01], 300);
    $direct = total($api, CurModel::withResource(
        CurModel::termsQuery($schemeId, $classIds[$class]),
        CurModel::EDGE_MATERIA_CURSO,
        (int) $smat01
    ));
    check("$dimension: every $class of SMAT01", count($rows) === $direct && $direct > 0, count($rows) . " / $direct");
    check("$dimension: all hang from SMAT01", [$smat01] === array_values(array_unique(array_column($rows, 'parentId'))));
    $withBlock = count(array_filter($rows, static fn (array $row): bool => '' !== $row['block']));
    check("$dimension: every row has its block", $withBlock === count($rows), "$withBlock / " . count($rows));
    $withText = count(array_filter($rows, static fn (array $row): bool => '' !== $row['description']));
    check("$dimension: every row has its skos:definition", $withText === count($rows), "$withText / " . count($rows));
}

echo "\n6. Current terms only (skos:changeNote nex)\n";
foreach ([CurModel::SABER => 8740, CurModel::CRITERIO => 3137, CurModel::MATERIA_CURSO => 232] as $class => $count) {
    $current = total($api, CurModel::termsQuery($schemeId, $classIds[$class]));
    $all = CurModel::termsQuery($schemeId, $classIds[$class]);
    $all['property'] = [];
    check("$class: current = loaded", $current === total($api, $all), "$current current");
    check("$class: matches curriculo.json 1.1.2", $count === $current);
}
$retired = null === $retiredId ? -1 : total($api, ['item_set_id' => $retiredId]);
check('retired set is empty in 1.1.2 (exclusion verified on disposable REA with --write)', 0 === $retired);

echo "\n7. Label filter (skos:prefLabel)\n";
$byCode = $search->searchDimension('lrmi:assesses', 'SMAT01CE1.1', ['about' => $smat01]);
check(
    'a criterion code finds that criterion',
    1 === count($byCode) && str_starts_with($byCode[0]['title'], 'SMAT01CE1.1 '),
    implode(' | ', array_column($byCode, 'title'))
);
$byWord = $search->searchDimension('schema:about', 'geología', ['level' => $s01]);
check(
    'a word filters course subjects',
    [] !== $byWord && [] === array_filter(
        $byWord,
        static fn (array $row): bool => false === mb_stripos($row['title'], 'geología')
    ),
    implode(' | ', array_column($byWord, 'title'))
);

if (!$write) {
    printf("\n%d checks, %d failures (read only; --write adds the disposable REA checks)\n", $checks, $failures);
    exit($failures ? 1 : 0);
}

echo "\n8. Disposable REA: target validation and nex semantics\n";
$marker = '[OERManager TASK-064 · prueba desechable] ' . date('c');
$propertyId = static fn (string $term): int => (int) $api->search('properties', ['term' => $term])
    ->getContent()[0]->id();
$literal = static fn (string $term, string $text): array => [$term => [[
    'type' => 'literal',
    'property_id' => $propertyId($term),
    '@value' => $text,
]]];
$disposable = [];
$create = static function (array $extra) use ($api, $marker, $literal, &$disposable): int {
    $data = ['o:is_public' => false]
        + $literal('dcterms:title', $marker)
        + $literal('dcterms:description', 'Test item created by test/container/cur-cascade-check.php; '
            . 'deleted when it ends.')
        + $extra;
    $id = (int) $api->create('items', $data)->getContent()->id();
    $disposable[] = $id;
    return $id;
};

try {
    $reaId = $create([]);
    $retiredLike = $create($literal('skos:changeNote', 'Retirado (prueba)'));
    printf("  created disposable REA #%d and #%d\n", $reaId, $retiredLike);

    $byMarker = ['property' => [['property' => 'dcterms:title', 'type' => 'eq', 'text' => $marker]]];
    $current = $byMarker;
    $current['property'][] = CurModel::termsQuery(1, 1)['property'][0];
    $found = array_map(static fn ($item) => (int) $item->id(), $api->search('items', $current)->getContent());
    check('nex row keeps the item without changeNote and drops the one with it', [$reaId] === $found, json_encode($found));
    check('both items exist without the nex row', 2 === total($api, $byMarker));

    $saber = $search->searchDimension('lrmi:teaches', '', ['about' => $smat01], 1)[0]['id'];
    $criterion = $search->searchDimension('lrmi:assesses', '', ['about' => $smat01], 1)[0]['id'];
    $diff = $recatalog->preview($reaId, ['lrmi:educationalLevel' => [$s01, $saber]]);
    check('preview marks a knowledge item as an invalid course', [$saber] === $diff['lrmi:educationalLevel']['invalid']);
    try {
        $recatalog->apply($reaId, ['lrmi:educationalLevel' => [$saber]], $user->getName());
        check('apply refuses a target of another class', false);
    } catch (\RuntimeException $e) {
        check('apply refuses a target of another class', true, $e->getMessage());
    }
    $result = $recatalog->apply($reaId, [
        'lrmi:educationalLevel' => [$s01],
        'schema:about' => [$smat01],
        'lrmi:teaches' => [$saber],
        'lrmi:assesses' => [$criterion],
    ], $user->getName());
    check('a valid alignment is written', true === $result['updated']);
    $item = $api->read('items', $reaId)->getContent();
    $linked = [];
    foreach (['lrmi:educationalLevel', 'schema:about', 'lrmi:teaches', 'lrmi:assesses'] as $term) {
        $value = $item->value($term);
        $linked[$term] = $value && $value->valueResource() ? (int) $value->valueResource()->id() : 0;
    }
    check('read back', [$s01, $smat01, $saber, $criterion] === array_values($linked), json_encode($linked));
    $titles = $recatalog->preview($reaId, ['lrmi:teaches' => [$saber]])['lrmi:teaches']['titles'];
    check('knowledge title is qualified by its course subject', str_ends_with(
        (string) ($titles[$saber] ?? ''),
        '(Matemáticas · 1º ESO)'
    ), (string) ($titles[$saber] ?? ''));
} finally {
    // One batch: deleting the items one by one in the same unit of work
    // fails on the second with the Access module installed (its AccessStatus
    // of the other new item is flushed without cascade, 2026-11-16).
    try {
        $api->batchDelete('items', $disposable);
    } catch (\Exception $e) {
        printf("  batch delete failed (%s); deleting one by one\n", $e->getMessage());
        foreach ($disposable as $id) {
            try {
                $services->get('Omeka\EntityManager')->clear();
                $api->delete('items', $id);
            } catch (\Exception $e) {
                printf("  could not delete #%d: %s\n", $id, $e->getMessage());
            }
        }
    }
    $left = total($api, ['property' => [['property' => 'dcterms:title', 'type' => 'eq', 'text' => $marker]]]);
    check('disposable REA deleted', 0 === $left, "$left left");
}

printf("\n%d checks, %d failures\n", $checks, $failures);
exit($failures ? 1 : 0);
