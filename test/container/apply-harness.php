<?php

/**
 * Arnés de verificación del `apply` en contenedor (TASK-023).
 *
 * Reproduce la rama de escritura de IndexController::recatalogApplyAction() sin
 * la capa HTTP: toma la salida de `propose-harness.php` (alineamiento +
 * justificaciones) y la aplica con RecatalogService::apply(), para comprobar que
 * la justificación se persiste como `dcterms:description` dentro del
 * `@annotation` de lrmi:teaches / lrmi:assesses.
 *
 * ⚠️ ESCRIBE EN EL CATÁLOGO. Exige el flag literal --write para ejecutarse.
 * La reversibilidad automática no existe todavía (TASK-007): tener snapshot.
 *
 * Uso: php apply-harness.php <propuesta.json> <contribuidor> <emailUsuario> --write
 */

chdir('/var/www/html');
require 'bootstrap.php';

$application = \Omeka\Mvc\Application::init(require 'application/config/application.config.php');
$services = $application->getServiceManager();
$recatalog = $services->get(\OERManager\Service\RecatalogService::class);

$file = (string) ($argv[1] ?? '');
$contributor = (string) ($argv[2] ?? '');
$email = (string) ($argv[3] ?? '');
$confirm = ($argv[4] ?? '') === '--write';

if ('' === $file || '' === $contributor || '' === $email || !$confirm) {
    fwrite(STDERR, "uso: php apply-harness.php <propuesta.json> <contribuidor> <emailUsuario> --write\n");
    exit(2);
}

// La ACL de Omeka exige identidad: en el flujo real el `apply` corre bajo un
// editor autenticado. Sin esto el ApiManager lanza PermissionDeniedException.
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
if (!is_file($file)) {
    fwrite(STDERR, "no existe: $file\n");
    exit(2);
}

$proposal = json_decode((string) file_get_contents($file), true, 512, JSON_THROW_ON_ERROR);
$itemId = (int) $proposal['item'];
$alignment = $proposal['alignment'] ?? [];

// Las claves de id llegan como string tras el round-trip JSON; el flujo real las
// recibe igual desde el POST. Se normalizan a int como hace collectJustifications().
$justifications = [];
foreach (($proposal['justifications'] ?? []) as $term => $map) {
    foreach ($map as $id => $why) {
        $justifications[$term][(int) $id] = (string) $why;
    }
}

printf("APLICANDO sobre item %d como '%s'\n", $itemId, $contributor);
foreach ($alignment as $term => $ids) {
    printf("  %-24s %d valor(es)  justificaciones=%d\n", $term, count($ids), count($justifications[$term] ?? []));
}

$result = $recatalog->apply($itemId, $alignment, $contributor, $justifications);
echo "\nresultado apply(): " . json_encode($result, JSON_UNESCAPED_UNICODE) . "\n";
