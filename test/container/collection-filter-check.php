<?php

/**
 * Arnés del filtro por colección (TASK-045, RF-018) y del contrato de la
 * búsqueda avanzada con `search-terms`.
 *
 * SOLO LECTURA: solo consulta la API, no escribe nada en el catálogo.
 *
 * Uso, desde el contenedor:
 *   php /var/www/html/modules/OERManager/test/container/collection-filter-check.php
 *
 * Sale 1 si alguna comprobación falla.
 */

require '/var/www/html/bootstrap.php';

$application = Omeka\Mvc\Application::init(require '/var/www/html/application/config/application.config.php');
$services = $application->getServiceManager();
$api = $services->get('Omeka\ApiManager');
$services->get('Omeka\EntityManager');
$services->get('Omeka\AuthenticationService')->getStorage()->write(
    $services->get('Omeka\EntityManager')->getRepository('Omeka\Entity\User')->findOneBy([], ['id' => 'ASC'])
);

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

$query = new OERManager\Service\MasterViewQuery($api);
$base = $query->buildSearchParams([]);
$total = $api->search('items', $base + ['limit' => 1])->getTotalResults();

$started = microtime(true);
$offered = $query->learningResourceItemSets();
$elapsed = microtime(true) - $started;

echo "REA totales: $total; colecciones ofrecidas: " . count($offered) . sprintf(" (%.2fs)\n", $elapsed);
foreach ($offered as $id => $title) {
    echo "   #$id $title\n";
}
echo "\n";

$allSets = $api->search('item_sets', ['limit' => OERManager\Service\MasterViewQuery::MAX_ITEM_SETS])->getContent();
foreach ($allSets as $set) {
    $inSet = $api->search('items', $base + ['item_set_id' => $set->id(), 'limit' => 1])->getTotalResults();
    check(
        "colección #{$set->id()}: ofrecida si y solo si tiene REA ($inSet)",
        ($inSet > 0) === isset($offered[$set->id()])
    );
}

foreach (array_slice(array_keys($offered), 0, 3) as $id) {
    $viaQuery = $api->search('items', $query->buildSearchParams(['item_set_id' => (string) $id]) + ['limit' => 1]);
    $direct = $api->search('items', $base + ['item_set_id' => $id, 'limit' => 1]);
    check(
        "filtrar por la colección #$id devuelve exactamente sus REA",
        $viaQuery->getTotalResults() === $direct->getTotalResults() && $direct->getTotalResults() > 0
    );
}

check('el tiempo de calcular las colecciones es razonable (< 5 s)', $elapsed < 5.0, sprintf('%.2fs', $elapsed));

// Contrato con el endpoint de autocompletado: cada filtro buscable tiene que
// poder devolver sugerencias, no [] por llamarse distinto.
$search = $services->get(OERManager\Service\CurriculumSearch::class);
foreach (OERManager\Service\MasterViewQuery::SEARCHABLE_FILTER_DIMENSIONS as $filter => $dimension) {
    $results = 'dcterms:relation' === $dimension
        ? $search->searchAxes('a')
        : $search->searchDimension($dimension, 'a');
    check("filtro {$filter} ({$dimension}) devuelve sugerencias", [] !== $results, 'respuesta vacía');
}

echo "\n" . str_repeat('-', 60) . "\n";
echo "$passed OK, $failed FAIL\n";

exit($failed > 0 ? 1 : 0);
