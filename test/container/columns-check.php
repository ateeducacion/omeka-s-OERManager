<?php

/**
 * Arnés de contenedor de la rebanada 2 de TASK-028.
 *
 * Comprueba sobre el catálogo REAL lo que ningún test de host puede: que las
 * columnas nuevas rindan lo que el dato manda. En particular los 4 valores
 * literales (schema:about ×3, lrmi:educationalLevel ×1) que hoy pasan por «ok»
 * y deben pasar a aviso (D2/D-3), y el recuento de dead_link que quedó sin
 * medir al escribir el spec.
 *
 * Uso, desde el contenedor:
 *   php /var/www/html/modules/OERManager/test/container/columns-check.php
 *
 * Sale 1 si alguna comprobación falla, para poder encadenarlo en un smoke test.
 */

require '/var/www/html/bootstrap.php';

$application = Omeka\Mvc\Application::init(require '/var/www/html/application/config/application.config.php');
$services = $application->getServiceManager();
$api = $services->get('Omeka\ApiManager');
$checker = $services->get(OERManager\Service\IntegrityChecker::class);

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

$classes = $api->search('resource_classes', ['term' => 'lrmi:LearningResource'])->getContent();
if (!$classes) {
    echo "No existe la clase lrmi:LearningResource en esta instalación.\n";
    exit(1);
}

$items = $api->search('items', [
    'resource_class_id' => $classes[0]->id(),
    'per_page' => 500,
])->getContent();

echo 'REA en el catálogo: ' . count($items) . "\n\n";

echo "1. Reglas de integridad\n";

$deadLinks = 0;
$literals = 0;
$missingLicence = 0;
$statuses = ['ok' => 0, 'warning' => 0, 'error' => 0];

foreach ($items as $item) {
    // Con enlaces ENCENDIDOS: es la pasada que mide el dead_link real.
    $result = $checker->check($item, true);
    $statuses[$result->getStatus()]++;
    foreach ($result->getIssues() as $issue) {
        if ('dead_link' === $issue['code']) {
            $deadLinks++;
        }
        if ('literal_in_link_property' === $issue['code']) {
            $literals++;
        }
        if ('missing_license' === $issue['code']) {
            $missingLicence++;
        }
    }
}

echo "   estados: ok={$statuses['ok']} warning={$statuses['warning']} error={$statuses['error']}\n";
echo "   dead_link=$deadLinks  literal_in_link_property=$literals  missing_license=$missingLicence\n";

// D2: el estudio contó 4 valores literales en properties de enlace. Si el dato
// no ha cambiado deben aflorar ahora, cuando antes pasaban por «ok».
check('los valores literales en properties de enlace afloran', $literals > 0,
    "se esperaban ~4 y se han encontrado $literals");

// D-5: si esto fuera > 0, el semáforo SÍ necesita su tercer estado y hay que
// volver sobre la decisión.
check('dead_link sigue sin productor (D-5)', 0 === $deadLinks,
    "han aparecido $deadLinks enlaces muertos: reabrir D-5");

echo "\n2. D-7: la comprobación de enlaces se puede apagar\n";

$sample = $items[0];
$withLinks = $checker->check($sample, true);
$withoutLinks = $checker->check($sample, false);

check('apagar los enlaces no inventa ni pierde otros avisos',
    count($withLinks->getIssuesBySeverity('warning'))
    === count($withoutLinks->getIssuesBySeverity('warning')));

$start = microtime(true);
foreach ($items as $item) {
    $checker->check($item, false);
}
$cheap = microtime(true) - $start;

$start = microtime(true);
foreach ($items as $item) {
    $checker->check($item, true);
}
$expensive = microtime(true) - $start;

printf("   sin enlaces: %.3f s   con enlaces: %.3f s\n", $cheap, $expensive);
check('la pasada barata no es más lenta que la cara', $cheap <= $expensive + 0.01);

echo "\n3. D-6: la plantilla suma, no sustituye\n";

$withTemplate = null;
foreach ($items as $item) {
    if ($item->resourceTemplate()) {
        $withTemplate = $item;
        break;
    }
}

if (null === $withTemplate) {
    echo "   (ningún REA tiene plantilla todavía — PEND-012 sigue abierto)\n";
    check('sin plantillas asignadas, la regla mínima gobierna sola', true);
} else {
    // D-6: con plantilla, el mínimo SIGUE evaluándose. Antes de la rebanada 2
    // este REA no habría producido missing_license ni missing_alignment jamás,
    // porque la plantilla era una rama excluyente.
    $codes = array_column($checker->check($withTemplate, false)->getIssues(), 'code');
    $hasLicence = (bool) $withTemplate->value(OERManager\Service\Governance\IntegrityPolicy::LICENSE_TERM);
    check('un REA con plantilla sigue evaluando la licencia (D-6)',
        $hasLicence === !in_array('missing_license', $codes, true),
        $hasLicence
            ? 'tiene licencia y aun asi se avisa de que falta'
            : 'no tiene licencia y el aviso no aparece: la plantilla la esta silenciando');
}

echo "\n4. Columna Curricular\n";

$curricular = new OERManager\ColumnType\Curricular();
$rendered = 0;
foreach ($items as $item) {
    $html = $curricular->renderContent(
        $services->get('ViewRenderer'),
        $item,
        []
    );
    if (null !== $html) {
        $rendered++;
    }
}
check('la celda curricular rinde en todos los REA con anclaje', $rendered > 0,
    "ha rendido en $rendered de " . count($items));

echo "\n" . str_repeat('-', 60) . "\n";
echo "$passed OK, $failed FAIL\n";

exit($failed > 0 ? 1 : 0);
