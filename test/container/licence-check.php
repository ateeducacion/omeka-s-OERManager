<?php

/**
 * Arnés de contenedor de TASK-040 (ADR-0019): la licencia es dcterms:license, como URI.
 *
 * 1. SOLO LECTURA (siempre): sobre los REA reales, `missing_license` sale
 *    exactamente en los que no tienen dcterms:license; un REA que solo tiene
 *    dcterms:rights ya no cuenta como licenciado; la columna por defecto
 *    apunta a dcterms:license.
 * 2. ESCRITURA CON RESTAURACIÓN (solo con --write): en un REA SIN
 *    dcterms:license escribe, uno tras otro, una URI sin etiqueta, una URI con
 *    etiqueta, un literal y —si el setting apunta a un CustomVocab— un valor de
 *    ese vocabulario; comprueba integridad, columna, panel, estadísticas y
 *    filtros; y deja dcterms:license vacío como estaba. Comprueba al final que
 *    el resto del item no ha cambiado.
 *
 * Uso, desde el contenedor:
 *   php /var/www/html/modules/OERManager/test/container/licence-check.php
 *   php /var/www/html/modules/OERManager/test/container/licence-check.php --write <email> [item_id]
 *
 * Sale 1 si alguna comprobación falla.
 */

require '/var/www/html/bootstrap.php';

use OERManager\ColumnType\GovernanceValue;
use OERManager\Service\GovernanceSettings;
use OERManager\Service\Governance\IntegrityPolicy;
use OERManager\Service\Governance\ValueText;
use OERManager\Service\IntegrityChecker;
use OERManager\Service\ItemPanelData;
use OERManager\Service\MasterViewQuery;
use OERManager\Service\Stats\DimensionFacts;

$application = Omeka\Mvc\Application::init(require '/var/www/html/application/config/application.config.php');
$services = $application->getServiceManager();
$api = $services->get('Omeka\ApiManager');
/** @var IntegrityChecker $checker */
$checker = $services->get(IntegrityChecker::class);
$view = $services->get('ViewRenderer');

$passed = 0;
$failed = 0;
$skipped = 0;

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

function skip(string $label, string $why): void
{
    global $skipped;
    $skipped++;
    echo "  SKIP $label — $why\n";
}

/** Solo los códigos de licencia, en orden. */
function licenceCodes(IntegrityChecker $checker, $item): array
{
    return array_values(array_filter(
        array_column($checker->check($item, false)->getIssues(), 'code'),
        static fn (string $code): bool => in_array($code, ['missing_license', 'license_not_uri'], true)
    ));
}

$args = array_slice($argv, 1);
$writeMode = '--write' === ($args[0] ?? '');

if ($writeMode) {
    // Autenticar ANTES de leer: sin identidad, la API solo ve items públicos y
    // no deja escribir (mismo patrón que workflow-check.php).
    $email = (string) ($args[1] ?? '');
    $entityManager = $services->get('Omeka\EntityManager');
    $user = '' === $email ? null
        : $entityManager->getRepository(\Omeka\Entity\User::class)->findOneBy(['email' => $email]);
    if (null === $user) {
        fwrite(STDERR, "uso: php licence-check.php --write <emailUsuario> [item_id] (usuario no encontrado: '$email')\n");
        exit(2);
    }
    $services->get('Omeka\AuthenticationService')->getStorage()->write($user);
    printf("autenticado como %s (%s)\n", $user->getEmail(), $user->getRole());
}

$classes = $api->search('resource_classes', ['term' => MasterViewQuery::LEARNING_RESOURCE_CLASS_TERM])->getContent();
if (!$classes) {
    fwrite(STDERR, "No existe la clase lrmi:LearningResource en esta instalación.\n");
    exit(1);
}
$items = $api->search('items', ['resource_class_id' => $classes[0]->id()])->getContent();
if (!$items) {
    fwrite(STDERR, "No hay ningún REA en el catálogo.\n");
    exit(1);
}

$column = new GovernanceValue();
$columnData = ['property_term' => IntegrityPolicy::LICENSE_TERM, 'empty_label' => 'Sin licencia'];

echo "\n1. Solo lectura\n";

check('el término de licencia es dcterms:license', 'dcterms:license' === IntegrityPolicy::LICENSE_TERM);

$defaultTerms = array_values(array_filter(array_column(
    $services->get('Config')['column_defaults']['admin']['oer_items'] ?? [],
    'property_term'
)));
check('la columna por defecto «Licencia» apunta a dcterms:license',
    in_array('dcterms:license', $defaultTerms, true) && !in_array('dcterms:rights', $defaultTerms, true),
    'property_term: ' . implode(', ', $defaultTerms));

$withLicence = 0;
$rightsOnly = [];
$mismatch = [];
foreach ($items as $item) {
    $hasLicence = [] !== $item->value(IntegrityPolicy::LICENSE_TERM, ['all' => true, 'default' => []]);
    $missing = in_array('missing_license', licenceCodes($checker, $item), true);
    if ($hasLicence) {
        $withLicence++;
    } elseif (null !== $item->value('dcterms:rights')) {
        $rightsOnly[] = $item;
    }
    if ($missing === $hasLicence) {
        $mismatch[] = $item->id();
    }
}
printf("   REA=%d  con dcterms:license=%d  solo dcterms:rights=%d\n", count($items), $withLicence, count($rightsOnly));

check('missing_license sale exactamente en los REA sin dcterms:license', [] === $mismatch,
    'discrepan: #' . implode(', #', $mismatch));

if ([] === $rightsOnly) {
    skip('un REA con solo dcterms:rights se ve «Sin licencia» en la columna',
        'ningún REA visible tiene dcterms:rights sin dcterms:license');
} else {
    $cell = (string) $column->renderContent($view, $rightsOnly[0], $columnData);
    check(sprintf('un REA con solo dcterms:rights (#%d) se ve «Sin licencia» en la columna', $rightsOnly[0]->id()),
        str_contains($cell, 'oer-value-missing'), strip_tags($cell));
}

if (!$writeMode) {
    echo "\n(parte de escritura omitida: usar --write <email> [item_id])\n";
    echo "\n$passed OK, $failed FAIL, $skipped SKIP\n";
    exit($failed > 0 ? 1 : 0);
}

echo "\n2. Escritura con restauración\n";

$itemId = isset($args[2]) ? (int) $args[2] : null;
if (null === $itemId) {
    foreach ($items as $candidate) {
        if (null === $candidate->value(IntegrityPolicy::LICENSE_TERM)) {
            $itemId = (int) $candidate->id();
            break;
        }
    }
}
if (null === $itemId) {
    fwrite(STDERR, "Todos los REA tienen ya dcterms:license: pasar un item_id sin licencia.\n");
    exit(1);
}
$item = $api->read('items', $itemId)->getContent();
if (null !== $item->value(IntegrityPolicy::LICENSE_TERM)) {
    fwrite(STDERR, "El REA #$itemId ya tiene dcterms:license: el arnés no escribe sobre licencias reales.\n");
    exit(1);
}
$properties = $api->search('properties', ['term' => IntegrityPolicy::LICENSE_TERM])->getContent();
if (!$properties) {
    fwrite(STDERR, "No existe la property dcterms:license en esta instalación.\n");
    exit(1);
}
$pid = $properties[0]->id();
echo "   REA #$itemId, dcterms:license property_id=$pid\n";

/** Todo el item salvo la licencia y la fecha de modificación: lo que NO debe cambiar. */
$snapshot = static function ($item): array {
    $json = json_decode(json_encode($item), true);
    unset($json[IntegrityPolicy::LICENSE_TERM], $json['o:modified']);
    return $json;
};
$before = $snapshot($item);

// ⚠️ clear_property_values + append: el único patrón que no borra el resto del item.
$write = static function (array $licenceValues) use ($api, $itemId, $pid) {
    $api->update('items', $itemId, [
        IntegrityPolicy::LICENSE_TERM => $licenceValues,
        'clear_property_values' => [$pid],
    ], [], ['isPartial' => true, 'collectionAction' => 'append']);
    return $api->read('items', $itemId)->getContent();
};

$panelData = $services->get(ItemPanelData::class);
$facts = $services->get(DimensionFacts::class);
$query = $services->get(MasterViewQuery::class);

$observe = static function ($item) use ($checker, $column, $columnData, $view, $panelData, $facts): array {
    return [
        'codes' => licenceCodes($checker, $item),
        'cell' => trim(strip_tags((string) $column->renderContent($view, $item, $columnData))),
        'panel' => $panelData->forItem($item)['record'][IntegrityPolicy::LICENSE_TERM] ?? null,
        'stats' => $facts->extract([$item])[(int) $item->id()]['licencia'],
    ];
};

$found = static function (array $filters) use ($api, $query, $itemId): bool {
    $params = $query->buildSearchParams($filters);
    $params['id'] = $itemId;
    return 1 === $api->search('items', $params)->getTotalResults();
};

$uri = 'https://creativecommons.org/licenses/by/4.0/';
$label = 'Creative Commons Attribution 4.0 International';

try {
    // (a) URI nativa sin etiqueta: el caso que el core convierte en ''.
    $seen = $observe($write([['type' => 'uri', 'property_id' => $pid, '@id' => $uri]]));
    check('(a) URI sin etiqueta: sin avisos de licencia', [] === $seen['codes'], implode(',', $seen['codes']));
    check('(a) la celda enseña la URI', $uri === $seen['cell'], $seen['cell']);
    check('(a) el panel enseña la URI', $uri === $seen['panel'], (string) $seen['panel']);
    check('(a) estadísticas agrupan por la URI', $uri === $seen['stats'], (string) $seen['stats']);
    check('(a) el filtro por licencia casa con la URI exacta', $found(['licence' => $uri]));
    check('(a) el filtro «Sin licencia» ya no lo incluye', !$found(['missing' => ['licence']]));

    // (b) URI con etiqueta.
    $seen = $observe($write([['type' => 'uri', 'property_id' => $pid, '@id' => $uri, 'o:label' => $label]]));
    check('(b) URI con etiqueta: sin avisos de licencia', [] === $seen['codes'], implode(',', $seen['codes']));
    check('(b) la celda enseña la etiqueta', $label === $seen['cell'], $seen['cell']);
    check('(b) el panel enseña la etiqueta', $label === $seen['panel'], (string) $seen['panel']);
    check('(b) estadísticas agrupan por la etiqueta', $label === $seen['stats'], (string) $seen['stats']);
    check('(b) el filtro por licencia casa con la etiqueta exacta', $found(['licence' => $label]));

    // (c) Literal: tiene licencia, pero no es URI.
    $seen = $observe($write([['type' => 'literal', 'property_id' => $pid, '@value' => 'CC BY']]));
    check('(c) literal: avisa license_not_uri y no missing_license', ['license_not_uri'] === $seen['codes'],
        implode(',', $seen['codes']));
    check('(c) la celda sigue enseñando el literal', 'CC BY' === $seen['cell'], $seen['cell']);

    // (d) El vocabulario configurado.
    $vocabId = GovernanceSettings::parseId($services->get('Omeka\Settings')->get(GovernanceSettings::LICENCE_VOCAB_ID));
    if (null === $vocabId) {
        skip('(d) licencia desde el CustomVocab configurado', 'oermanager_licence_vocab_id vacío');
    } else {
        $vocab = $api->read('custom_vocabs', $vocabId)->getContent();
        $uriLabels = $vocab->listUriLabels() ?? [];
        if ('uri' === $vocab->type() && [] !== $uriLabels) {
            $vocabUri = (string) array_key_first($uriLabels);
            $seen = $observe($write([['type' => "customvocab:$vocabId", 'property_id' => $pid, '@id' => $vocabUri]]));
            check("(d) CustomVocab de URIs #$vocabId: sin avisos de licencia", [] === $seen['codes'],
                implode(',', $seen['codes']));
            // Misma pieza que la columna: el arnés no reimplementa la regla.
            check('(d) la celda enseña la etiqueta del vocabulario',
                ValueText::of($uriLabels[$vocabUri], $vocabUri) === $seen['cell'], $seen['cell']);
        } else {
            // Hoy (2026-09-10) el setting apunta al vocabulario 2, de TÉRMINOS:
            // el paso manual del propietario está pendiente. La regla nueva debe
            // delatarlo sola.
            $term = (string) (($vocab->listTerms() ?? [])[0] ?? 'CC BY');
            $seen = $observe($write([['type' => "customvocab:$vocabId", 'property_id' => $pid, '@value' => $term]]));
            check("(d) CustomVocab #$vocabId de términos: license_not_uri delata el vocabulario mal apuntado",
                ['license_not_uri'] === $seen['codes'], implode(',', $seen['codes']));
            skip('(d) licencia desde un CustomVocab de URIs',
                "el vocabulario #$vocabId es de tipo '" . $vocab->type() . "': falta el paso manual de ADR-0019");
        }
    }
} finally {
    // Restaurar: el REA no tenía dcterms:license, así que basta con vaciarla.
    $restored = $write([]);
    check("REA #$itemId restaurado sin dcterms:license", null === $restored->value(IntegrityPolicy::LICENSE_TERM));
    check('el resto del item no ha cambiado (título, descripción, anclaje…)', $before === $snapshot($restored));
}

echo "\n$passed OK, $failed FAIL, $skipped SKIP\n";
exit($failed > 0 ? 1 : 0);
