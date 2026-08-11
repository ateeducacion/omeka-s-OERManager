<?php

/**
 * Arnés de los chips de filtros activos de la vista maestra.
 *
 * Comprueba los **enlaces de retirada**, que son la única parte del partial con
 * lógica y la única que no se ve hasta pulsarla: un enlace mal construido no
 * rompe nada visible, simplemente se lleva por delante otros filtros que el
 * curador tenía puestos.
 *
 * Hay tres formas distintas de quitar un filtro y las tres se ejercitan aquí:
 * escalar con property (`visibility`), **elemento de un array** (`missing[]`,
 * el caso delicado: quitar la clave entera borraría los filtros hermanos) y
 * escalar fuera del mapa de properties (`integrity`, predicado computado).
 *
 * SOLO LECTURA: no toca el catálogo. Renderiza el partial con una query
 * inyectada y afirma sobre el HTML resultante.
 *
 * Uso, desde el contenedor:
 *   php /var/www/html/modules/OERManager/test/container/search-filters-check.php
 *
 * Sale 1 si alguna comprobación falla.
 */

require '/var/www/html/bootstrap.php';

$application = Omeka\Mvc\Application::init(require '/var/www/html/application/config/application.config.php');
$view = $application->getServiceManager()->get('ViewRenderer');

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

// Un curador con cuatro filtros puestos a la vez y en la página 3.
$query = [
    'missing' => ['licence', 'description'],
    'integrity' => 'warning',
    'visibility' => 'public',
    'page' => 3,
];

$filters = [
    'Visibilidad' => ['Público'],
    OERManager\Module::MISSING_GROUP_LABEL => ['Sin licencia', 'Sin descripción'],
    OERManager\Module::INTEGRITY_GROUP_LABEL => ['Con incidencias'],
];

$html = $view->partial('oer-manager/common/search-filters', ['filters' => $filters, 'query' => $query]);

preg_match_all(
    '/<span class="filter-value">\s*([^<]+?)\s*<a[^>]*href="([^"]*)"/s',
    $html,
    $matches,
    PREG_SET_ORDER
);

$urlByChip = [];
foreach ($matches as $match) {
    $urlByChip[trim(html_entity_decode($match[1]))] = urldecode(html_entity_decode($match[2]));
}

echo "Enlaces de retirada generados:\n";
foreach ($urlByChip as $chip => $url) {
    echo '   ' . str_pad($chip, 20) . " → $url\n";
}
echo "\n";

check(
    'los cuatro chips activos llevan aspa',
    4 === count($urlByChip),
    'se han encontrado ' . count($urlByChip) . ': ' . implode(', ', array_keys($urlByChip))
);

// El caso delicado: `missing[]` es un array y hay que quitar UN elemento.
$sinLicencia = $urlByChip['Sin licencia'] ?? '';
check(
    'quitar «Sin licencia» conserva el otro filtro de gobernanza',
    str_contains($sinLicencia, 'missing[0]=description'),
    $sinLicencia
);
check(
    'quitar «Sin licencia» retira efectivamente licence',
    '' !== $sinLicencia && !str_contains($sinLicencia, 'licence'),
    $sinLicencia
);
check(
    'quitar un filtro de gobernanza no toca los demás filtros',
    str_contains($sinLicencia, 'integrity=warning') && str_contains($sinLicencia, 'visibility=public'),
    $sinLicencia
);

$conIncidencias = $urlByChip['Con incidencias'] ?? '';
check(
    'quitar «Con incidencias» retira integrity y conserva los de gobernanza',
    '' !== $conIncidencias
        && !str_contains($conIncidencias, 'integrity=')
        && str_contains($conIncidencias, 'licence')
        && str_contains($conIncidencias, 'description'),
    $conIncidencias
);

$publico = $urlByChip['Público'] ?? '';
check(
    'quitar un escalar con property retira solo su clave',
    '' !== $publico && !str_contains($publico, 'visibility=') && str_contains($publico, 'integrity=warning'),
    $publico
);

// Quitar un filtro tiene que devolver a la primera página: el recuento cambia y
// la página 3 podría ya no existir.
check(
    'ningún enlace conserva la página',
    [] === array_filter($urlByChip, static fn (string $url): bool => str_contains($url, 'page=')),
    implode(' | ', $urlByChip)
);

// Un filtro de otro módulo, que este partial no sabe deshacer, debe seguir
// mostrándose (sin aspa) en vez de desaparecer.
$foreign = $view->partial('oer-manager/common/search-filters', [
    'filters' => ['Filtro de otro módulo' => ['algo']],
    'query' => ['loquesea' => 'algo'],
]);
check(
    'un filtro ajeno se muestra aunque no se sepa quitar',
    str_contains($foreign, 'algo') && !str_contains($foreign, 'oer-filter-remove')
);

echo "\n" . str_repeat('-', 60) . "\n";
echo "$passed OK, $failed FAIL\n";

exit($failed > 0 ? 1 : 0);
