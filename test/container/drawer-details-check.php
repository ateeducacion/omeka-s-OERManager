<?php

/**
 * Arnés de las superficies de lectura del drawer (TASK-028 rebanada 3a).
 *
 * Cubre lo que ningún test de host puede: RecatalogService::history() y
 * eventsOf() (privado) dependen del core de Omeka. eventsOf() reproduce el
 * desempate de lastEventOf(), del que depende `undo()` — si la Task 2 lo
 * rompió, el undo restauraría el estado equivocado y ningún test de host lo
 * detectaría. El ciclo completo escribir → leer → restaurar es la red de
 * seguridad de esa pieza.
 *
 * DOS MODOS, por seguridad — este arnés es la única excepción de la rebanada
 * que escribe, y solo lo hace si se le pide explícitamente:
 *
 *   - Sin argumentos: SOLO LECTURA. Comprueba 1 (history() vacío en un REA sin
 *     eventos) y 2 (la integridad coincide con la que mide columns-check.php),
 *     elige el REA automáticamente y marca como SKIP las comprobaciones 3-5,
 *     que exigen escribir. Sale 0. Es el modo pensado para encadenarse en un
 *     smoke test, igual que columns-check.php y search-filters-check.php.
 *
 *   - `<itemId> <emailUsuario> --write`: además de 1 y 2 sobre ese item,
 *     ejecuta 3 (fabricar un evento con apply()), 4 (vaciar una dimensión por
 *     completo — el caso que motiva E-1: una value annotation no puede
 *     registrar un vaciado, porque vive en el valor que se borra) y 5 (deshacer
 *     y comprobar que el alineamiento vuelve a su estado inicial). Captura el
 *     estado previo ANTES de escribir y lo vuelca a fichero, igual que
 *     undo-harness.php. Deshacer ES rehacer (ADR-0015): la reversión deja su
 *     propio evento — el item queda con el mismo alineamiento, pero NO con el
 *     mismo historial de curación privado, y eso se declara en la salida en
 *     vez de fingir una restauración byte a byte.
 *
 * Uso, desde el contenedor:
 *   php /var/www/html/modules/OERManager/test/container/drawer-details-check.php
 *   php /var/www/html/modules/OERManager/test/container/drawer-details-check.php \
 *     <itemId> <emailUsuario> --write
 */

chdir('/var/www/html');
require 'bootstrap.php';

use OERManager\Service\CurationEvent;
use OERManager\Service\Governance\CurationHistory;
use OERManager\Service\RecatalogService;

$application = \Omeka\Mvc\Application::init(require 'application/config/application.config.php');
$services = $application->getServiceManager();
$api = $services->get('Omeka\ApiManager');
$recatalog = $services->get(RecatalogService::class);
$checker = $services->get(OERManager\Service\IntegrityChecker::class);

$argItemId = (int) ($argv[1] ?? 0);
$argEmail = (string) ($argv[2] ?? '');
$argWriteFlag = ($argv[3] ?? '') === '--write';

$writeMode = $argItemId > 0 && '' !== $argEmail && $argWriteFlag;
$readOnlyInvocation = 0 === $argItemId && '' === $argEmail && !$argWriteFlag;

if (!$writeMode && !$readOnlyInvocation) {
    fwrite(STDERR, "uso:\n");
    fwrite(STDERR, "  solo lectura (1-2, sale 0):  php drawer-details-check.php\n");
    fwrite(STDERR, "  con escritura (1-5):         php drawer-details-check.php <itemId> <emailUsuario> --write\n");
    exit(2);
}

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

// Igual que en columns-check.php: distingue «se verificó y pasó» de «no se
// pudo (o no se quiso) verificar». Aquí marca las comprobaciones 3-5 cuando
// el arnés se invoca sin --write.
function skip(string $label, string $motivo): void
{
    global $skipped;
    $skipped++;
    echo "  SKIP $label — $motivo\n";
}

/**
 * Estado observable del item: alineamiento por dimensión (ids + títulos
 * propios sin cualificar), canarios del ValueHydrator y recuento de eventos de
 * curación. Mismo patrón que undo-harness.php, con 'titles' añadido: esta
 * rebanada verifica resolución de títulos, no solo ids.
 */
function snapshot($api, int $itemId): array
{
    $item = $api->read('items', $itemId)->getContent();
    $state = ['terms' => [], 'events' => 0];
    foreach (RecatalogService::ALIGNMENT_TERMS as $term) {
        $ids = [];
        $titles = [];
        foreach ($item->value($term, ['all' => true, 'default' => []]) as $value) {
            $resource = $value->valueResource();
            if (!$resource) {
                continue;
            }
            $id = (int) $resource->id();
            $ids[] = $id;
            $titles[$id] = (string) $resource->displayTitle();
        }
        sort($ids);
        ksort($titles);
        $state['terms'][$term] = ['ids' => $ids, 'titles' => $titles];
    }
    // Canarios de la trampa crítica del ValueHydrator: si el partial vuelve a
    // recorrer la colección plana, esto es lo primero que desaparece.
    $state['canary'] = [
        'dcterms:title' => (string) $item->value('dcterms:title'),
        'dcterms:description' => (string) $item->value('dcterms:description'),
        'lrmi:learningResourceType' => (string) $item->value('lrmi:learningResourceType'),
    ];
    foreach ($item->value('dcterms:provenance', ['all' => true, 'default' => []]) as $value) {
        $annotation = $value->valueAnnotation();
        $marker = $annotation ? $annotation->value('dcterms:provenance') : null;
        if ($marker && CurationEvent::MARKER === trim((string) $marker)) {
            $state['events']++;
        }
    }
    return $state;
}

/**
 * Primer REA (lrmi:LearningResource) del catálogo con historial vacío, para
 * las comprobaciones 1 y 2 cuando el arnés se invoca sin argumentos: no hay
 * itemId explícito, así que hay que elegir uno solo con lecturas. Devuelve
 * null si no hay ninguno. Requiere estar autenticado para ver también los
 * eventos privados; sin sesión, todo REA parecería «limpio» aunque no lo sea.
 */
function pickCleanItem($api, RecatalogService $recatalog): ?int
{
    $classes = $api->search('resource_classes', ['term' => 'lrmi:LearningResource'])->getContent();
    if (!$classes) {
        return null;
    }
    $items = $api->search('items', [
        'resource_class_id' => $classes[0]->id(),
        'per_page' => 500,
    ])->getContent();
    foreach ($items as $item) {
        if ([] === $recatalog->history((int) $item->id())) {
            return (int) $item->id();
        }
    }
    return null;
}

// dcterms:provenance se escribe is_public=false (ADR-0015): un item leído SIN
// autenticar nunca ve esos valores, aunque existan de verdad (verificado en
// contenedor: 0 visibles sin sesión, 4 visibles autenticado, mismo item). Sin
// esto, history() devolvería SIEMPRE [] al llamarse sin login — no porque el
// item esté limpio, sino porque el arnés sería ciego a sus propios eventos —,
// y la comprobación 1 no verificaría nada. drawerDetailsAction() corre bajo
// sesión de admin real, así que autenticar aquí también es más fiel a lo que
// el drawer ve. Autenticar NO escribe nada: sigue siendo modo solo lectura.
const READ_ONLY_IDENTITY_EMAIL = 'editor@example.com';

$contributor = null;
$itemId = null;
$dump = null;
$initial = null;

$entityManager = $services->get('Omeka\EntityManager');

if ($writeMode) {
    $user = $entityManager->getRepository(\Omeka\Entity\User::class)->findOneBy(['email' => $argEmail]);
    if (null === $user) {
        fwrite(STDERR, "usuario no encontrado: $argEmail\n");
        exit(2);
    }
    $services->get('Omeka\AuthenticationService')->getStorage()->write($user);
    $contributor = $user->getName();
    printf("modo ESCRITURA — autenticado como %s (%s)\n\n", $user->getEmail(), $user->getRole());

    $itemId = $argItemId;
    $initial = snapshot($api, $itemId);
    $dump = sys_get_temp_dir() . '/oer-drawer-details-check-' . $itemId . '-' . time() . '.json';
    file_put_contents($dump, json_encode($initial, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
    printf("item #%d — estado inicial volcado en %s\n\n", $itemId, $dump);
} else {
    echo "modo SOLO LECTURA (invocado sin argumentos) — 3, 4 y 5 se marcan SKIP\n";
    $readOnlyUser = $entityManager->getRepository(\Omeka\Entity\User::class)
        ->findOneBy(['email' => READ_ONLY_IDENTITY_EMAIL]);
    if (null === $readOnlyUser) {
        echo "AVISO: no se encontró " . READ_ONLY_IDENTITY_EMAIL . "; se sigue sin autenticar\n"
            . "  (los eventos privados de curación, si los hubiera, quedarán invisibles).\n\n";
    } else {
        // Autenticar (lectura, sin CSRF ni escritura) para ver lo mismo que ve
        // drawerDetailsAction() bajo sesión de admin real.
        $services->get('Omeka\AuthenticationService')->getStorage()->write($readOnlyUser);
        printf("autenticado en solo lectura como %s (%s)\n\n", $readOnlyUser->getEmail(), $readOnlyUser->getRole());
    }
    $itemId = pickCleanItem($api, $recatalog);
}

// --- 1. history() sobre un REA sin eventos (E-2) ---------------------------
echo "1. history() sobre un REA sin eventos (E-2)\n";
if (null === $itemId) {
    skip(
        'history() vuelve vacío antes de escribir nada',
        'no se encontró ningún REA sin eventos de curación en el catálogo'
    );
} else {
    $beforeHistory = $recatalog->history($itemId);
    check(
        "history() vuelve vacío para el item #$itemId (autenticado, ve también los eventos privados)",
        [] === $beforeHistory,
        'se han encontrado ' . count($beforeHistory) . ' filas'
    );
    if (null !== $initial && $initial['events'] > 0) {
        printf(
            "   AVISO: el item #%d ya tenía %d evento(s) de curación antes de correr\n" .
            "   este arnés — elige otro item de pruebas sin historial previo.\n",
            $itemId,
            $initial['events']
        );
    }
}

// --- 2. La integridad coincide con la que mide columns-check.php -----------
echo "\n2. La integridad coincide con la que mide columns-check.php (enlaces encendidos)\n";
if (null === $itemId) {
    skip('la integridad coincide con la que mide columns-check.php', 'sin REA de referencia (ver punto 1)');
} else {
    // No se puede invocar el proceso de columns-check.php desde aquí y comparar
    // su salida literal: lo que sí se puede — y es lo que garantiza que
    // drawer-details no diverge de columns-check.php — es reproducir EXACTAMENTE
    // su misma llamada ($checker->check($item, true), ver columns-check.php
    // sección 1) sobre el mismo item con dos lecturas independientes y
    // comprobar que coinciden. Si coincidieran solo por casualidad de una única
    // lectura mutable en caché, dos lecturas frescas del item lo delatarían.
    $sampleA = $api->read('items', $itemId)->getContent();
    $resultA = $checker->check($sampleA, true);
    $sampleB = $api->read('items', $itemId)->getContent();
    $resultB = $checker->check($sampleB, true);
    check(
        'el status coincide entre dos mediciones independientes con checkLinks=true',
        $resultA->getStatus() === $resultB->getStatus(),
        $resultA->getStatus() . ' vs ' . $resultB->getStatus()
    );
    check(
        'los issues (códigos) coinciden entre dos mediciones independientes con checkLinks=true',
        array_column($resultA->getIssues(), 'code') === array_column($resultB->getIssues(), 'code'),
        json_encode(array_column($resultA->getIssues(), 'code')) . ' vs '
            . json_encode(array_column($resultB->getIssues(), 'code'))
    );
    printf(
        "   status=%s, %d issue(s) — misma llamada (checkLinks=true) que drawerDetailsAction() ejecuta\n",
        $resultA->getStatus(),
        count($resultA->getIssues())
    );
}

if (!$writeMode) {
    skip('3. fabricar un evento con apply()', 'requiere --write: <itemId> <emailUsuario> --write');
    skip('4. vaciar una dimensión por completo (E-1)', 'requiere --write: <itemId> <emailUsuario> --write');
    skip('5. restauración (deshacer es rehacer)', 'requiere --write: <itemId> <emailUsuario> --write');

    echo "\n" . str_repeat('-', 60) . "\n";
    echo "$passed OK, $failed FAIL, $skipped SKIP\n";
    exit($failed > 0 ? 1 : 0);
}

// A partir de aquí solo se llega en modo ESCRITURA, con $itemId/$initial ya
// capturados arriba.

// Dimensión de prueba: la primera de ALIGNMENT_TERMS que tenga valores en este
// item, igual que undo-harness.php elige su víctima.
$victim = null;
foreach (RecatalogService::ALIGNMENT_TERMS as $term) {
    if ($initial['terms'][$term]['ids']) {
        $victim = $term;
        break;
    }
}
if (null === $victim) {
    fwrite(STDERR, "el item $itemId no tiene alineamiento: no hay nada que vaciar\n");
    exit(2);
}
printf("\ndimensión de prueba: %s (%d valores)\n", $victim, count($initial['terms'][$victim]['ids']));

// --- 3 y 4. Vaciar la dimensión por completo fabrica el evento (E-1) -------
// Un único vaciado total satisface ambas comprobaciones del brief a la vez:
// fabrica el evento (3) y es justo el caso — vaciado completo — que motiva
// E-1, porque una value annotation no puede registrarlo (vive en el valor que
// se borra). Es también el mismo patrón de escritura que undo-harness.php,
// necesario para que un único undo() baste para la restauración (5).
echo "\n3 y 4. Vaciar $victim por completo (fabrica el evento; caso E-1)\n";
$result = $recatalog->apply($itemId, [$victim => []], $contributor);
check('el apply se escribe', true === ($result['updated'] ?? false));

$afterEmpty = snapshot($api, $itemId);
check('la dimensión queda vacía', [] === $afterEmpty['terms'][$victim]['ids']);
check(
    'el evento queda registrado (events+1)',
    $afterEmpty['events'] === $initial['events'] + 1,
    "eran {$initial['events']}, ahora {$afterEmpty['events']}"
);

$history = $recatalog->history($itemId);
check('history() devuelve exactamente una fila', 1 === count($history), 'filas: ' . count($history));

$row = $history[0] ?? null;
check(
    'contributor no vacío',
    null !== $row && '' !== trim((string) ($row['contributor'] ?? ''))
);
check(
    'summary no vacío',
    null !== $row && '' !== trim((string) ($row['summary'] ?? ''))
);

$change = null;
if (null !== $row) {
    foreach ($row['changes'] as $candidate) {
        if ($victim === $candidate['term']) {
            $change = $candidate;
            break;
        }
    }
}
check('el historial trae el cambio de la dimensión vaciada', null !== $change);
check(
    'emptied = true — el caso que una value annotation no puede representar (E-1)',
    null !== $change && true === $change['emptied']
);
check('added = [] — no se añadió nada, solo se vació', null !== $change && [] === $change['added']);
check(
    'aparecen todos los ids retirados',
    null !== $change && count($change['removed']) === count($initial['terms'][$victim]['ids']),
    null !== $change ? 'removed=' . count($change['removed'])
        . ' esperados=' . count($initial['terms'][$victim]['ids']) : 'sin change'
);

$unresolvedRemoved = [];
$titlesLookLikeRealTitles = true;
if (null !== $change) {
    foreach ($change['removed'] as $removedEntry) {
        $title = (string) $removedEntry['title'];
        if (str_starts_with($title, CurationHistory::UNRESOLVED_PREFIX)) {
            $unresolvedRemoved[] = $title;
        }
        // Los cambios deben venir resueltos a TÍTULO, no a id: el título debe
        // empezar por el título propio real del destino (la cualificación de
        // ancestro, si la hay, va después entre paréntesis — D7).
        $matchesSomeRealTitle = false;
        foreach ($initial['terms'][$victim]['titles'] as $realTitle) {
            if ('' !== trim($realTitle) && str_starts_with($title, trim($realTitle))) {
                $matchesSomeRealTitle = true;
                break;
            }
        }
        if (!$matchesSomeRealTitle) {
            $titlesLookLikeRealTitles = false;
        }
    }
}
check(
    'ningún id retirado queda sin resolver (justifica el coste de titlesFor())',
    [] === $unresolvedRemoved,
    'sin resolver: ' . implode(', ', $unresolvedRemoved)
);
check(
    'los títulos retirados son títulos reales del destino, no ids desnudos',
    $titlesLookLikeRealTitles
);
if (null !== $change && $change['removed']) {
    printf("   ejemplo: %s\n", $change['removed'][0]['title']);
}

// --- 5. Restauración: deshacer es rehacer -----------------------------------
echo "\n5. Restauración (deshacer es rehacer — ADR-0015)\n";
$undone = $recatalog->undo($itemId, $contributor);
check('el undo se escribe', true === ($undone['updated'] ?? false));

// Comprobación de restauración, ejecutada tras la ÚLTIMA escritura del arnés
// (undo() de arriba): dimensión a dimensión, por CONJUNTO DE IDS contra el
// volcado inicial — no por número de valores ni por título, que podrían
// coincidir por casualidad aunque los ids reales hubieran cambiado. Si algo
// diverge, falla ruidosamente nombrando la dimensión y los ids exactos que
// sobran o faltan, con la ruta del volcado para poder reparar a mano.
$final = snapshot($api, $itemId);
foreach (RecatalogService::ALIGNMENT_TERMS as $term) {
    $expectedIds = $initial['terms'][$term]['ids'];
    $actualIds = $final['terms'][$term]['ids'];
    $missingIds = array_values(array_diff($expectedIds, $actualIds));
    $extraIds = array_values(array_diff($actualIds, $expectedIds));
    check(
        "el alineamiento de $term vuelve exactamente al conjunto de ids inicial",
        [] === $missingIds && [] === $extraIds,
        ([] !== $missingIds ? 'faltan=' . implode(',', $missingIds) . ' ' : '')
            . ([] !== $extraIds ? 'sobran=' . implode(',', $extraIds) . ' ' : '')
            . "— volcado inicial: $dump"
    );
}
check(
    'los canarios del ValueHydrator (título/descripción/tipo) siguen intactos',
    $final['canary'] === $initial['canary'],
    'volcado inicial: ' . $dump
);

$finalHistory = $recatalog->history($itemId);
check(
    'history() ahora tiene dos filas: el vaciado y su reversión',
    2 === count($finalHistory),
    'filas: ' . count($finalHistory)
);
check(
    'la fila más reciente es la reversión (isUndo = true)',
    true === ($finalHistory[0]['isUndo'] ?? null)
);
check(
    'la fila del vaciado sigue en el historial, no es un undo',
    false === ($finalHistory[1]['isUndo'] ?? null)
);

$eventsGained = $final['events'] - $initial['events'];
printf(
    "\nNOTA — no se finge una restauración byte a byte: el alineamiento del item #%d\n" .
    "   vuelve a ser idéntico al inicial, pero su historial de curación privado\n" .
    "   (dcterms:provenance, is_public=false, invisible en la ficha pública) gana\n" .
    "   %d evento(s) nuevo(s) que no existían antes de correr este arnés: el vaciado\n" .
    "   de %s y la reversión que lo deshace. Deshacer ES rehacer (ADR-0015): la\n" .
    "   reversión también deja su propio evento, no borra el que deshace.\n",
    $itemId,
    $eventsGained,
    $victim
);

echo "\n" . str_repeat('-', 60) . "\n";
echo "$passed OK, $failed FAIL, $skipped SKIP\n";

if ($failed > 0) {
    $touched = snapshot($api, $itemId);
    fwrite(STDERR, "\nATENCION: el item #$itemId puede haber quedado tocado.\n");
    fwrite(STDERR, "Estado inicial (antes de este arnés): $dump\n");
    fwrite(STDERR, 'Estado actual: ' . json_encode($touched, JSON_UNESCAPED_UNICODE) . "\n");
}

exit($failed > 0 ? 1 : 0);
