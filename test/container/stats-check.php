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

echo "\n$passed OK, $failed FAIL\n";
exit($failed > 0 ? 1 : 0);
