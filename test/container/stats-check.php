<?php

/**
 * Arnés de verificación de TASK-006 (estadísticas). CatalogSnapshot y
 * DimensionFacts dependen del core (Omeka\Api\Manager / ItemRepresentation):
 * no se pueden instanciar en un test de host (ver «Limitación conocida del
 * arnés», project-memory.md). Los agregadores puros (DimensionCounter,
 * DimensionCrosser, CompletenessAggregator, CsvExport) ya tienen TDD real en
 * host — este arnés cubre solo la extracción real desde el catálogo.
 *
 * SOLO LECTURA: ninguna llamada aquí escribe en el catálogo.
 *
 * Uso, desde el contenedor:
 *   php /var/www/html/modules/OERManager/test/container/stats-check.php
 */

require '/var/www/html/bootstrap.php';

use OERManager\Controller\Admin\StatsController;
use OERManager\Service\Stats\CatalogSnapshot;
use OERManager\Service\Stats\CompletenessAggregator;
use OERManager\Service\Stats\DimensionCounter;
use OERManager\Service\Stats\DimensionCrosser;
use OERManager\Service\Stats\DimensionFacts;
use OERManager\Service\IntegrityChecker;

$application = Omeka\Mvc\Application::init(require '/var/www/html/application/config/application.config.php');
$services = $application->getServiceManager();

/** @var CatalogSnapshot $snapshot */
$snapshot = $services->get(CatalogSnapshot::class);
$facts = $services->get(DimensionFacts::class);
$counter = $services->get(DimensionCounter::class);
$crosser = $services->get(DimensionCrosser::class);
$completeness = $services->get(CompletenessAggregator::class);
/** @var IntegrityChecker $integrityChecker */
$integrityChecker = $services->get(IntegrityChecker::class);

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

$data = $snapshot->fetch();
check('fetch() trae al menos 1 item', count($data['items']) > 0, 'catálogo vacío');
check('fetch() no está truncado con el catálogo real', false === $data['truncated']);

$extracted = $facts->extract($data['items']);
check('extract() devuelve una fila por item', count($extracted) === count($data['items']));

$materiaCounts = $counter->count($extracted, 'materia');
check('DimensionCounter da conteos para materia', array_sum($materiaCounts) > 0);

$cross = $crosser->cross($extracted, 'materia', 'etapa');
check('DimensionCrosser da al menos una combinación materia×etapa', count($cross) > 0);

$statuses = [];
foreach ($data['items'] as $item) {
    $statuses[] = $integrityChecker->check($item, false)->getStatus();
}
$result = $completeness->aggregate($statuses);
check(
    'CompletenessAggregator: ok+warning+error = total',
    $result['ok'] + $result['warning'] + $result['error'] === $result['total']
);
echo "  INFO completitud: {$result['okPercent']}% ok ({$result['ok']}/{$result['total']})\n";

$distinctIds = $facts->distinctResourceIds($extracted);
check('distinctResourceIds() no repite ids', count($distinctIds) === count(array_unique($distinctIds)));

// StatsController (finding #4 de la revisión final de TASK-006): hasta ahora
// nada resolvía el controlador real, así que la factory de 8 argumentos en
// module.config.php, resolveTitles(), relabelCounts()/relabelCrossTable()/
// transpose() y el cableado de ruta/ACL no tenían verificación automatizada.
$controller = $services->get('ControllerManager')->get(StatsController::class);
$view = $controller->indexAction();
$statsData = $view->getVariable('statsData');
check('indexAction() resuelve y devuelve statsData', null !== $statsData);
check(
    'statsData trae los 20 pares de cruce (10 combinaciones × 2 órdenes)',
    20 === count($statsData['cross'] ?? [])
);
check(
    'statsData trae completitud con los 4 campos',
    isset(
        $statsData['completeness']['ok'],
        $statsData['completeness']['warning'],
        $statsData['completeness']['error'],
        $statsData['completeness']['total']
    )
);

// ---------------------------------------------------------------------------
// TASK-047 (RF-019): decision dashboard. Same read-only rule: only searches.
// ---------------------------------------------------------------------------
echo "\nTASK-047\n";

use OERManager\Service\Stats\CoverageMatrix;
use OERManager\Service\Stats\CurriculumOutline;
use OERManager\Service\Stats\StageCounts;
use OERManager\Service\Stats\SubjectCounts;
use OERManager\Service\Stats\YearFilter;
use OERManager\Service\Governance\SubjectTint;

check('cada fila de hechos trae el año de o:created', [] === array_filter(
    $extracted,
    static fn (array $row): bool => !is_int($row['year'] ?? null)
));

$idsOf = static function (string $dimension) use ($extracted): array {
    $ids = [];
    foreach ($extracted as $row) {
        foreach ((array) $row[$dimension] as $id) {
            $ids[(int) $id] = true;
        }
    }
    return array_keys($ids);
};
/** @var CurriculumOutline $outline */
$outline = $services->get(CurriculumOutline::class);
$map = $outline->load($idsOf('etapa'), $idsOf('materia'), [...$idsOf('eje'), ...$idsOf('proyecto')]);
check('el mapa curricular tiene universo de asignaturas', $map->hasUniverse());

$stageLabels = array_map(static fn (int $id): string => $map->label($id), $map->orderedStageIds());
check(
    'etapas en el orden de schema:position',
    ['Educación Infantil', 'Educación Primaria', 'ESO', 'Bachillerato'] === $stageLabels,
    implode(' | ', $stageLabels)
);
$courseLabels = array_map(static fn (int $id): string => $map->label($id), $map->orderedCourseIds());
echo '  INFO orden de cursos: ' . implode(' | ', $courseLabels) . "\n";
$esoCourses = array_values(array_filter($courseLabels, static fn (string $l): bool => str_ends_with($l, 'ESO')));
check('cursos de ESO por nivel', ['1º ESO', '2º ESO', '3º ESO', '4º ESO'] === $esoCourses, implode(' | ', $esoCourses));
check(
    'cada curso tiene etapa conocida',
    [] === array_filter($map->orderedCourseIds(), static fn (int $id): bool => null === $map->stageOfCourse($id))
);

$names = [];
foreach ($map->subjects() as $id => $subject) {
    $names[SubjectTint::normalise($subject['label'])][] = $id;
}
$homonyms = array_filter($names, static fn (array $ids): bool => count($ids) > 1);
echo '  INFO asignaturas: ' . count($map->subjects()) . ' items, ' . count($names) . ' nombres, '
    . count($homonyms) . " nombres repetidos\n";
$sample = reset($homonyms) ?: [];
$qualified = array_unique(array_map(static fn (int $id): string => $map->qualifiedSubjectLabel($id), $sample));
check('una materia homónima se distingue por su curso', count($sample) > 1 && count($qualified) === count($sample));

$stageGroups = (new StageCounts())->build($extracted, $map, 'Sin etapa');
$withStage = array_values(array_filter($stageGroups, static fn (array $g): bool => null !== $g['stageId']));
check('Etapa y curso agrupa por etapa en orden curricular', array_column($withStage, 'label') === $stageLabels);

$subjectCounts = new SubjectCounts();
$subjectGroups = $subjectCounts->build($extracted, $map, 'Sin curso');
$linksWithCourse = 0;
foreach ($extracted as $row) {
    foreach (array_unique($row['materia']) as $id) {
        $linksWithCourse += null !== $map->courseOfSubject((int) $id) ? 1 : 0;
    }
}
$listed = 0;
foreach ($subjectGroups as $group) {
    foreach ($group['courses'] as $course) {
        if (null !== $course['courseId']) {
            $listed += array_sum(array_column($course['subjects'], 'count'));
        }
    }
}
check('Materia: toda materia con curso aparece bajo su curso', $linksWithCourse === $listed, "$linksWithCourse vs $listed");

$coverage = (new CoverageMatrix())->build($extracted, $map, 'Sin etapa');
$cells = array_merge(...array_column($coverage['rows'], 'cells'));
check('el mapa de calor tiene una fila por nombre de materia', count($coverage['rows']) === count($names));
check('el mapa distingue n/a (null) de hueco (0)', in_array(null, $cells, true) && in_array(0, $cells, true));
check('el mapa cuenta todos los enlaces con curso', array_sum(array_map('intval', $cells)) === $linksWithCourse);
echo '  INFO mapa: ' . count($coverage['rows']) . ' x ' . count($coverage['columns']) . ', max ' . $coverage['max']
    . ', huecos ' . count(array_filter($cells, static fn ($c): bool => 0 === $c))
    . ', sin curso ' . $coverage['unplaced'] . "\n";

$yearFilter = new YearFilter();
$years = $yearFilter->years($extracted);
check('la suma de REA por año es el total', array_sum($years) === count($extracted));
$someYear = array_key_first($years);
check('aplicar el año deja exactamente sus REA', count($yearFilter->apply($extracted, $someYear)) === $years[$someYear]);
echo '  INFO años: ' . json_encode($years) . "\n";

$export = static function (array $query) use ($services): string {
    $controller = $services->get('ControllerManager')->get(StatsController::class);
    $controller->getRequest()->getQuery()->fromArray($query);
    return (string) $controller->exportAction()->getContent();
};
$csvRows = static function (string $csv): array {
    $lines = array_values(array_filter(explode("\n", trim($csv))));
    array_shift($lines);
    return array_map(static fn (string $line): array => str_getcsv($line, ',', '"', ''), $lines);
};
$esoId = $map->orderedStageIds()[2];
$esoRows = $csvRows($export(['dimension' => 'materia', 'stage' => (string) $esoId]));
check(
    'CSV de Materia filtrado por ESO solo trae ESO',
    [] !== $esoRows && [] === array_filter($esoRows, static fn (array $r): bool => 'ESO' !== $r[0]),
    count($esoRows) . ' filas'
);
$coverageRows = $csvRows($export(['type' => 'coverage']));
check(
    'CSV de cobertura = celdas existentes del mapa',
    count($coverageRows) === count(array_filter($cells, static fn ($c): bool => null !== $c))
);
$futureRows = $csvRows($export(['dimension' => 'licencia', 'year' => '2099']));
check('CSV con un año sin REA sale vacío', [] === $futureRows);

$controller = $services->get('ControllerManager')->get(StatsController::class);
$controller->getRequest()->getQuery()->fromArray(['year' => (string) $someYear]);
$view = $controller->indexAction();
check('indexAction() con año: total = REA de ese año', $view->getVariable('total') === $years[$someYear]);
check('indexAction() con año: completitud sobre esos REA', $view->getVariable('statsData')['completeness']['total'] === $years[$someYear]);

echo "\n$passed OK, $failed FAIL\n";
exit($failed > 0 ? 1 : 0);
