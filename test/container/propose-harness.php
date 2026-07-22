<?php

/**
 * Arnés de verificación funcional en contenedor (TASK-019/021/022/023).
 *
 * Reproduce fielmente IndexController::aiProposeAction() sin la capa HTTP
 * (CSRF/ACL/proxy inverso), para poder medir la ficha destilada, la visión y el
 * perfil de inferencia sin toparse con el timeout del proxy (504, TASK-020).
 *
 * SOLO LECTURA: ejecuta `propose`, nunca `apply`. No escribe en el catálogo.
 *
 * Uso (dentro del contenedor):
 *   php modules/OERManager/test/container/propose-harness.php <itemId> <salida.json>
 */

chdir('/var/www/html');
require 'bootstrap.php';

$application = \Omeka\Mvc\Application::init(require 'application/config/application.config.php');
$services = $application->getServiceManager();

$api = $services->get('Omeka\ApiManager');
$cataloguer = $services->get(\OERManager\Service\Ai\AiCataloguer::class);
$mediaSource = $services->get(\OERManager\Service\Content\MediaSourceInterface::class);

$id = (int) ($argv[1] ?? 0);
$out = (string) ($argv[2] ?? '');
if ($id <= 0 || '' === $out) {
    fwrite(STDERR, "uso: php propose-harness.php <itemId> <salida.json>\n");
    exit(2);
}

$item = $api->read('items', $id)->getContent();

// Réplica exacta de IndexController::itemMetadataText().
$parts = [];
$title = trim((string) $item->displayTitle(''));
if ('' !== $title) {
    $parts[] = $title;
}
foreach ($item->values() as $info) {
    foreach ($info['values'] as $value) {
        if ('literal' !== $value->type()) {
            continue;
        }
        $text = trim((string) $value->value());
        if ('' !== $text) {
            $parts[] = $text;
        }
    }
}
$metadataText = implode("\n", array_values(array_unique($parts)));

$t0 = microtime(true);
try {
    $proposal = $cataloguer->propose(
        $metadataText,
        $mediaSource->filesFor($id),
        $mediaSource->imagesFor($id)
    );
} catch (\Throwable $e) {
    file_put_contents($out, json_encode([
        'item' => $id,
        'error' => get_class($e),
        'message' => $e->getMessage(),
        'elapsed' => round(microtime(true) - $t0, 1),
    ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
    fwrite(STDERR, 'ERROR item ' . $id . ': ' . get_class($e) . ': ' . $e->getMessage() . "\n");
    exit(1);
}
$elapsed = round(microtime(true) - $t0, 1);

$proposal['item'] = $id;
$proposal['title'] = $title;
$proposal['elapsed'] = $elapsed;
file_put_contents($out, json_encode($proposal, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));

printf("item %d (%s) OK en %ss\n", $id, '' !== $title ? $title : '(sin titulo)', $elapsed);
