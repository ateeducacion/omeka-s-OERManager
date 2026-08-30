<?php

/**
 * Arnés de verificación de RF-016 (flujo autor→curador). `WorkflowService`
 * depende del core (`Omeka\Api\Manager`/`ItemRepresentation`): no se puede
 * instanciar en un test de host (ver «Limitación conocida del arnés»,
 * project-memory.md). `WorkflowStatus` ya tiene TDD real en host — este
 * arnés cubre solo la escritura/lectura real contra el catálogo.
 *
 * ESCRIBE Y DESHACE: usa un item real del catálogo, hace el ciclo completo
 * propose→reject→propose→publish, y al final restaura el item a su estado
 * original (curation:status borrado, is_public a su valor previo) para no
 * dejar residuo. Si el arnés falla a mitad, puede dejar el item marcado —
 * revisar manualmente el item usado si el exit code es distinto de 0.
 *
 * Uso, desde el contenedor:
 *   php /var/www/html/modules/OERManager/test/container/workflow-check.php <emailUsuario> [item_id]
 *
 * El item debe ser un REA real (`lrmi:LearningResource`) sobre el que se
 * pueda escribir. Si no se pasa id, prueba con el primer REA que encuentre.
 *
 * ⚠️ DESVIACIÓN respecto al Step 1 del plan (necesaria, no cosmética): el
 * texto del plan no autenticaba ningún usuario antes de escribir. Ejecutado
 * tal cual contra el contenedor real, `$api->update()` lanza
 * `PermissionDeniedException` — la ACL de Omeka exige identidad para
 * escribir en items, igual que ya documentan `apply-harness.php` y
 * `undo-harness.php` en este mismo directorio. Se añade aquí el mismo
 * patrón ya establecido (buscar el usuario por email y escribirlo en
 * `Omeka\AuthenticationService`), con el mismo usuario mínimo autorizado
 * (`editor@example.com`, NFR-003) que TASK-023 usó para verificar en
 * contenedor sobre este mismo item #3181 (ver project-memory.md). El resto
 * del arnés —checks, ciclo de transiciones, restauración— es exactamente el
 * del plan.
 */

require '/var/www/html/bootstrap.php';

use OERManager\Service\MasterViewQuery;
use OERManager\Service\Workflow\WorkflowService;
use OERManager\Service\Workflow\WorkflowStatus;

$application = Omeka\Mvc\Application::init(require '/var/www/html/application/config/application.config.php');
$services = $application->getServiceManager();
$api = $services->get('Omeka\ApiManager');
/** @var WorkflowService $workflow */
$workflow = $services->get(WorkflowService::class);

$email = (string) ($argv[1] ?? '');
if ('' === $email) {
    fwrite(STDERR, "uso: php workflow-check.php <emailUsuario> [item_id]\n");
    exit(2);
}
$entityManager = $services->get('Omeka\EntityManager');
$user = $entityManager->getRepository(\Omeka\Entity\User::class)->findOneBy(['email' => $email]);
if (null === $user) {
    fwrite(STDERR, "usuario no encontrado: $email\nCandidatos:\n");
    foreach ($entityManager->getRepository(\Omeka\Entity\User::class)->findAll() as $u) {
        fwrite(STDERR, sprintf("  %s (%s)\n", $u->getEmail(), $u->getRole()));
    }
    exit(2);
}
$services->get('Omeka\AuthenticationService')->getStorage()->write($user);
printf("autenticado como %s (%s)\n", $user->getEmail(), $user->getRole());

$itemId = isset($argv[2]) ? (int) $argv[2] : null;
if (null === $itemId) {
    $classes = $api->search('resource_classes', ['term' => MasterViewQuery::LEARNING_RESOURCE_CLASS_TERM])->getContent();
    if (!$classes) {
        fwrite(STDERR, "No existe la clase lrmi:LearningResource en esta instalación.\n");
        exit(1);
    }
    $items = $api->search('items', ['resource_class_id' => $classes[0]->id(), 'per_page' => 1])->getContent();
    if (!$items) {
        fwrite(STDERR, "No hay ningún REA en el catálogo para probar.\n");
        exit(1);
    }
    $itemId = (int) $items[0]->id();
}

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

echo "Item de prueba: #$itemId\n";
$originalItem = $api->read('items', $itemId)->getContent();
$originalIsPublic = $originalItem->isPublic();
$originalStatus = $workflow->statusOf($originalItem);
$originalNoteValue = $originalItem->value(WorkflowStatus::NOTE_TERM);
$originalNote = null;
if (null !== $originalNoteValue) {
    $trimmed = trim((string) $originalNoteValue->value());
    $originalNote = '' === $trimmed ? null : $trimmed;
}
check(
    'El estado inicial leído es válido (null, Propuesto o Rechazado)',
    null === $originalStatus || WorkflowStatus::PROPOSED === $originalStatus || WorkflowStatus::REJECTED === $originalStatus
);

// 1. Proponer.
$item = $api->read('items', $itemId)->getContent();
$result = $workflow->propose($item);
check('propose() desde el estado inicial da updated=true', true === ($result['updated'] ?? false), json_encode($result));
$item = $api->read('items', $itemId)->getContent();
check('El item queda en Propuesto', WorkflowStatus::PROPOSED === $workflow->statusOf($item));

// 2. Rechazar con motivo.
$result = $workflow->reject($item, 'Falta la licencia');
check('reject() desde Propuesto da updated=true', true === ($result['updated'] ?? false), json_encode($result));
$item = $api->read('items', $itemId)->getContent();
check('El item queda en Rechazado', WorkflowStatus::REJECTED === $workflow->statusOf($item));
$noteValue = $item->value(WorkflowStatus::NOTE_TERM);
check('El motivo del rechazo se guardó', null !== $noteValue && 'Falta la licencia' === (string) $noteValue->value());

// 3. Re-proponer: el motivo debe desaparecer.
$result = $workflow->propose($item);
check('propose() desde Rechazado da updated=true', true === ($result['updated'] ?? false), json_encode($result));
$item = $api->read('items', $itemId)->getContent();
check('El item vuelve a Propuesto', WorkflowStatus::PROPOSED === $workflow->statusOf($item));
check('El motivo del rechazo anterior se limpió', null === $item->value(WorkflowStatus::NOTE_TERM));

// 4. Publicar (sin pasar por IntegrityChecker aquí — eso lo prueba el
// controlador; este arnés solo prueba la escritura de WorkflowService).
$result = $workflow->publish($item);
check('publish() desde Propuesto da updated=true', true === ($result['updated'] ?? false), json_encode($result));
$item = $api->read('items', $itemId)->getContent();
check('El item queda público', $item->isPublic());
check('curation:status se elimina al publicar', null === $workflow->statusOf($item));

// 5. Transiciones inválidas.
$result = $workflow->reject($item, 'no debería aplicar');
check('reject() sobre un item ya publicado (sin estado) da updated=false', false === ($result['updated'] ?? true));
$result = $workflow->publish($item);
check('publish() sobre un item ya publicado (sin estado) da updated=false', false === ($result['updated'] ?? true));

// Restaurar el item a su estado original.
//
// ⚠️ MISMA TRAMPA que WorkflowService (skill `recatalogador`, incidente
// 2026-06-25): un update con valores sin `clear_property_values` +
// `collectionAction=append` borraría TODO lo demás del item (título,
// descripción, alineamiento...), no solo el estado. Este arnés existe para
// verificar de forma segura — restaurar de forma insegura sería peor que no
// restaurar.
//
// Restaura TANTO curation:status COMO curation:note: si el item de partida
// ya estaba Rechazado con un motivo, ese motivo también debe sobrevivir a
// la restauración, no solo el estado.
$statusProperties = $api->search('properties', ['term' => WorkflowStatus::STATUS_TERM])->getContent();
if (!$statusProperties) {
    fwrite(STDERR, "No existe la property " . WorkflowStatus::STATUS_TERM . " en esta instalación — no se puede restaurar.\n");
    exit(1);
}
$statusPropertyId = $statusProperties[0]->id();
$noteProperties = $api->search('properties', ['term' => WorkflowStatus::NOTE_TERM])->getContent();
if (!$noteProperties) {
    fwrite(STDERR, "No existe la property " . WorkflowStatus::NOTE_TERM . " en esta instalación — no se puede restaurar.\n");
    exit(1);
}
$notePropertyId = $noteProperties[0]->id();
$api->update('items', $itemId, [
    'o:is_public' => $originalIsPublic,
    WorkflowStatus::STATUS_TERM => $originalStatus ? [[
        'type' => 'literal',
        'property_id' => $statusPropertyId,
        '@value' => $originalStatus,
    ]] : [],
    WorkflowStatus::NOTE_TERM => $originalNote ? [[
        'type' => 'literal',
        'property_id' => $notePropertyId,
        '@value' => $originalNote,
    ]] : [],
    'clear_property_values' => [$statusPropertyId, $notePropertyId],
], [], ['isPartial' => true, 'collectionAction' => 'append']);
echo "Item #$itemId restaurado a su estado original (is_public=" . ($originalIsPublic ? '1' : '0') . ").\n";

echo "\n$passed OK, $failed FAIL\n";
exit($failed > 0 ? 1 : 0);
