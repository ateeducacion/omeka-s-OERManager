<?php

/**
 * Arnés de verificación de la reversibilidad real en contenedor (TASK-007).
 *
 * Es la ÚNICA cobertura real de RecatalogService::apply()/undo(): el arnés del
 * host no puede instanciar el servicio (depende del core de Omeka), así que un
 * test de PHPUnit pasaría sin ejercitar producción. Lo que sí se prueba en el
 * host es el formato del evento (CurationEventTest), que es la pieza pura.
 *
 * ⚠️ ESCRIBE EN EL CATÁLOGO. Exige el flag literal --write.
 *
 * Se autorrestaura: la última fase deshace lo hecho y compara el estado final
 * con el inicial, que además se vuelca a un fichero por si algo se tuerce. Lo
 * que ejercita es un VACIADO de dimensión, que es justo el caso que ninguna
 * value annotation puede registrar —al borrarse el valor se borra su traza— y
 * la razón de ser de ADR-0015.
 *
 * Uso: php undo-harness.php <itemId> <emailUsuario> --write
 */

chdir('/var/www/html');
require 'bootstrap.php';

use OERManager\Service\CurationEvent;
use OERManager\Service\RecatalogService;

$application = \Omeka\Mvc\Application::init(require 'application/config/application.config.php');
$services = $application->getServiceManager();
$api = $services->get('Omeka\ApiManager');
$recatalog = $services->get(RecatalogService::class);

$itemId = (int) ($argv[1] ?? 0);
$email = (string) ($argv[2] ?? '');
$confirm = ($argv[3] ?? '') === '--write';

if ($itemId <= 0 || '' === $email || !$confirm) {
    fwrite(STDERR, "uso: php undo-harness.php <itemId> <emailUsuario> --write\n");
    exit(2);
}

$entityManager = $services->get('Omeka\EntityManager');
$user = $entityManager->getRepository(\Omeka\Entity\User::class)->findOneBy(['email' => $email]);
if (null === $user) {
    fwrite(STDERR, "usuario no encontrado: $email\n");
    exit(2);
}
$services->get('Omeka\AuthenticationService')->getStorage()->write($user);
$contributor = $user->getName();
printf("autenticado como %s (%s)\n\n", $user->getEmail(), $user->getRole());

$failures = 0;
$checks = 0;

function check(string $label, bool $ok): void
{
    global $failures, $checks;
    $checks++;
    if (!$ok) {
        $failures++;
    }
    printf("  [%s] %s\n", $ok ? ' OK ' : 'FALLA', $label);
}

/** Estado observable del item: alineamiento por dimensión, porqués y canarios. */
function snapshot($api, int $itemId): array
{
    $item = $api->read('items', $itemId)->getContent();
    $state = ['terms' => [], 'events' => 0];
    foreach (RecatalogService::ALIGNMENT_TERMS as $term) {
        $ids = [];
        $why = [];
        foreach ($item->value($term, ['all' => true, 'default' => []]) as $value) {
            $resource = $value->valueResource();
            if (!$resource) {
                continue;
            }
            $id = (int) $resource->id();
            $ids[] = $id;
            $annotation = $value->valueAnnotation();
            $reason = $annotation ? $annotation->value('dcterms:description') : null;
            if ($reason) {
                $why[$id] = trim((string) $reason);
            }
        }
        sort($ids);
        $state['terms'][$term] = ['ids' => $ids, 'why' => $why];
    }
    // Canarios de la trampa crítica del ValueHydrator: si el partial vuelve a
    // recorrer la colección plana, esto es lo primero que desaparece.
    $state['canary'] = [
        'dcterms:title' => (string) $item->value('dcterms:title'),
        'dcterms:description' => (string) $item->value('dcterms:description'),
        'lrmi:learningResourceType' => (string) $item->value('lrmi:learningResourceType'),
    ];
    $state['stamps'] = [];
    foreach ($item->value('dcterms:provenance', ['all' => true, 'default' => []]) as $value) {
        $annotation = $value->valueAnnotation();
        $marker = $annotation ? $annotation->value('dcterms:provenance') : null;
        if ($marker && CurationEvent::MARKER === trim((string) $marker)) {
            $state['events']++;
            $state['lastEventPublic'] = $value->isPublic();
            $state['stamps'][] = trim((string) $annotation->value('dcterms:modified'));
        }
    }
    return $state;
}

/**
 * Escritura DIRECTA por la API, saltándose el servicio: simula que alguien tocó
 * el REA por otra vía (el formulario nativo de Omeka) y por tanto sin dejar
 * evento. Es lo que debe disparar la guarda de obsolescencia del undo.
 */
function writeBehindTheModule($api, int $itemId, string $term, array $ids): void
{
    $property = $api->search('properties', ['term' => $term])->getContent()[0];
    $values = [];
    foreach ($ids as $id) {
        $values[] = [
            'type' => 'resource:item',
            'property_id' => $property->id(),
            'value_resource_id' => $id,
        ];
    }
    $api->update(
        'items',
        $itemId,
        [$term => $values, 'clear_property_values' => [$property->id()]],
        [],
        ['isPartial' => true, 'collectionAction' => 'append']
    );
}

$initial = snapshot($api, $itemId);
$dump = sys_get_temp_dir() . '/oer-undo-harness-' . $itemId . '-' . time() . '.json';
file_put_contents($dump, json_encode($initial, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
printf("estado inicial volcado en %s\n", $dump);

// Se elige la dimensión con más valores, y a igualdad una que pueda llevar
// justificación de la IA, para ejercitar también su restauración.
$victim = null;
foreach (['lrmi:teaches', 'lrmi:assesses', 'schema:about', 'dcterms:relation', 'lrmi:educationalLevel'] as $term) {
    if ($initial['terms'][$term]['ids']) {
        $victim = $term;
        break;
    }
}
if (null === $victim) {
    fwrite(STDERR, "el item $itemId no tiene alineamiento: no hay nada que vaciar ni restaurar\n");
    exit(2);
}
printf(
    "dimensión de prueba: %s (%d valores, %d con porqué)\n\n",
    $victim,
    count($initial['terms'][$victim]['ids']),
    count($initial['terms'][$victim]['why'])
);

// --- Fase A: vaciar la dimensión ------------------------------------------
echo "A · vaciar $victim\n";
$result = $recatalog->apply($itemId, [$victim => []], $contributor);
$afterEmpty = snapshot($api, $itemId);
check('el apply se escribe', true === ($result['updated'] ?? false));
check('la dimensión queda vacía', [] === $afterEmpty['terms'][$victim]['ids']);
check('el evento queda registrado', $afterEmpty['events'] === $initial['events'] + 1);
check('el evento es privado', false === ($afterEmpty['lastEventPublic'] ?? true));
check('los canarios del ValueHydrator siguen ahí', $afterEmpty['canary'] === $initial['canary']);
foreach (RecatalogService::ALIGNMENT_TERMS as $term) {
    if ($term !== $victim) {
        check(
            "no se tocó $term",
            $afterEmpty['terms'][$term]['ids'] === $initial['terms'][$term]['ids']
        );
    }
}

// --- Fase B: el evento se puede leer de vuelta -----------------------------
echo "\nB · leer el evento\n";
$event = $recatalog->lastEvent($itemId);
check('lastEvent() lo encuentra', null !== $event);
check('registra quién', ($event['contributor'] ?? '') === $contributor);
check(
    'guarda el estado previo, que es lo que la anotación no puede',
    ($event['payload']['terms'][$victim]['before'] ?? null) === $initial['terms'][$victim]['ids']
);
check('guarda el estado resultante', ($event['payload']['terms'][$victim]['after'] ?? null) === []);
check(
    'guarda los porqués de la IA',
    count($event['payload']['terms'][$victim]['why'] ?? []) === count($initial['terms'][$victim]['why'])
);
printf("     resumen legible: %s\n", $event['summary'] ?? '(ninguno)');

// --- Fase C: deshacer restaura ---------------------------------------------
echo "\nC · deshacer\n";
$undone = $recatalog->undo($itemId, $contributor);
$afterUndo = snapshot($api, $itemId);
check('el undo se escribe', true === ($undone['updated'] ?? false));
check('ningún destino se perdió por el camino', [] === ($undone['dropped'] ?? null));
check('los ids vuelven exactamente', $afterUndo['terms'][$victim]['ids'] === $initial['terms'][$victim]['ids']);
check('los porqués de la IA vuelven', $afterUndo['terms'][$victim]['why'] === $initial['terms'][$victim]['why']);
check('los canarios siguen ahí', $afterUndo['canary'] === $initial['canary']);
check('la reversión también se audita', $afterUndo['events'] === $initial['events'] + 2);

// --- Fase D: deshacer un deshacer es rehacer -------------------------------
echo "\nD · deshacer el deshacer (LIFO)\n";
$recatalog->undo($itemId, $contributor);
$afterRedo = snapshot($api, $itemId);
check('vuelve al estado vaciado', [] === $afterRedo['terms'][$victim]['ids']);

// --- Fase E: confirmar sin cambios no es un evento -------------------------
echo "\nE · confirmar sin cambios\n";
$noop = $recatalog->apply($itemId, [$victim => []], $contributor);
$afterNoop = snapshot($api, $itemId);
check('no se escribe', false === ($noop['updated'] ?? true));
check('se dice que no había cambios', true === ($noop['unchanged'] ?? false));
check('no ensucia el registro con un evento vacío', $afterNoop['events'] === $afterRedo['events']);

// --- Fase F: alguien toca el REA por otra vía ------------------------------
echo "\nF · el REA cambia fuera del módulo\n";
$intruder = [reset($initial['terms'][$victim]['ids'])];
writeBehindTheModule($api, $itemId, $victim, $intruder);
$stale = $recatalog->undo($itemId, $contributor);
check('el undo se niega en vez de tirar el trabajo ajeno', 'stale' === ($stale['error'] ?? ''));
check('dice qué dimensión cambió', [$victim] === ($stale['terms'] ?? []));
check('y no ha escrito nada', snapshot($api, $itemId)['terms'][$victim]['ids'] === $intruder);

// --- Fase G: dejar el REA como estaba --------------------------------------
echo "\nG · restaurar el REA (con confirmación explícita)\n";
$forced = $recatalog->undo($itemId, $contributor, true);
check('forzado, el undo sí escribe', true === ($forced['updated'] ?? false));
$final = snapshot($api, $itemId);
check('el alineamiento final es el inicial', $final['terms'] === $initial['terms']);
check('los canarios finales son los iniciales', $final['canary'] === $initial['canary']);
// Se cuentan solo los sellos NUEVOS: un REA puede arrastrar eventos anteriores.
check(
    'cada evento nuevo tiene un sello único (si no, lastEvent desempata a ciegas)',
    count(array_unique($final['stamps'])) - count(array_unique($initial['stamps']))
        === $final['events'] - $initial['events']
);

printf("\n%d comprobaciones, %d fallos\n", $checks, $failures);
printf("eventos de curación acumulados en el item: %d (eran %d)\n", $final['events'], $initial['events']);
if ($failures) {
    fwrite(STDERR, "REVISAR: el volcado del estado inicial está en $dump\n");
}
exit($failures ? 1 : 0);
