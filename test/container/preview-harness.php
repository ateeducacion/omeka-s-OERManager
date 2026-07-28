<?php

/**
 * Arnés de verificación del `preview` en contenedor (TASK-028, tarea 5).
 *
 * La tarea 5 no lleva test automático por decisión del propietario: el arnés de
 * host no puede instanciar RecatalogService (depende del core de Omeka), así que
 * un test de PHPUnit pasaría sin ejercitar producción. Este arnés es su única
 * cobertura real: comprueba que preview() devuelve 'titles' cubriendo
 * current ∪ next, que es la mitad de servidor de la decisión D7.
 *
 * SOLO LECTURA: preview() no escribe nada en el catálogo.
 *
 * Uso (dentro del contenedor):
 *   php modules/OERManager/test/container/preview-harness.php <itemId>
 */

chdir('/var/www/html');
require 'bootstrap.php';

$application = \Omeka\Mvc\Application::init(require 'application/config/application.config.php');
$services = $application->getServiceManager();
$api = $services->get('Omeka\ApiManager');
$recatalog = $services->get(\OERManager\Service\RecatalogService::class);

$itemId = (int) ($argv[1] ?? 0);
if ($itemId <= 0) {
    fwrite(STDERR, "uso: php preview-harness.php <itemId>\n");
    exit(2);
}

$item = $api->read('items', $itemId)->getContent();
printf("item #%d — %s\n", $itemId, $item->displayTitle(''));

// Estado actual de schema:about, la dimensión que se va a modificar.
$current = [];
foreach ($item->value('schema:about', ['all' => true, 'default' => []]) as $value) {
    $resource = $value->valueResource();
    if ($resource) {
        $current[(int) $resource->id()] = (string) $resource->displayTitle();
    }
}
echo 'schema:about actual: ' . json_encode($current, JSON_UNESCAPED_UNICODE) . "\n";

// Una Asignatura distinta de las actuales, para que el diff tenga added y removed
// y para que 'titles' tenga que cubrir ids que no salen del item leído.
$pidType = null;
foreach ($api->search('properties', ['term' => 'dcterms:type'])->getContent() as $property) {
    $pidType = $property->id();
}
$candidates = $api->search('items', [
    'property' => [['property' => $pidType, 'type' => 'eq', 'text' => 'Asignatura']],
    'limit' => 20,
])->getContent();

$nextId = null;
foreach ($candidates as $candidate) {
    if (!array_key_exists((int) $candidate->id(), $current)) {
        $nextId = (int) $candidate->id();
        printf("candidata elegida para 'next': #%d — %s\n", $nextId, $candidate->displayTitle(''));
        break;
    }
}
if (null === $nextId) {
    fwrite(STDERR, "no se encontró una Asignatura distinta de las actuales\n");
    exit(1);
}

$diff = $recatalog->preview($itemId, ['schema:about' => [$nextId]]);
echo "\n=== diff['schema:about'] ===\n";
echo json_encode($diff['schema:about'], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) . "\n";

$expected = array_unique(array_merge($diff['schema:about']['current'], $diff['schema:about']['next']));
sort($expected);
$got = array_keys($diff['schema:about']['titles']);
sort($got);
printf("\nids esperados en titles: %s\n", json_encode($expected));
printf("ids presentes en titles: %s\n", json_encode($got));

$missing = array_diff($expected, $got);
$blank = array_filter($diff['schema:about']['titles'], static fn ($title) => '' === trim($title));
if ($missing) {
    printf("FALLO: faltan títulos para %s\n", json_encode(array_values($missing)));
    exit(1);
}
if ($blank) {
    printf("FALLO: títulos vacíos para %s\n", json_encode(array_keys($blank)));
    exit(1);
}
echo "OK: titles cubre current union next y ningún título viene vacío\n";
