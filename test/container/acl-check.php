<?php

/**
 * Comprobación de la ACL del módulo en contenedor (TASK-029).
 *
 * SOLO LECTURA: no escribe nada, solo interroga a la ACL ya construida.
 *
 * Es la única cobertura real de `Module::onBootstrap()`: la ACL se arma con el
 * `AclFactory` del core y con los roles que añaden otros módulos —IsolatedSites
 * registra `site_editor` heredando de `editor`—, así que un test de host no
 * puede decir nada útil sobre quién entra a dónde.
 *
 * Lo que verifica es la razón de ser de la tarea: que la configuración quedó
 * reservada a Supervisor (site_admin) y superior, y que **el editor conserva la
 * curación** — un allow por privilegio mal escrito rompe el módulo entero sin
 * que ningún test del host se entere.
 *
 * Uso (dentro del contenedor):
 *   php modules/OERManager/test/container/acl-check.php
 */

chdir('/var/www/html');
require 'bootstrap.php';

use OERManager\Controller\Admin\IndexController;

$application = \Omeka\Mvc\Application::init(require 'application/config/application.config.php');
$services = $application->getServiceManager();
/** @var \Omeka\Permissions\Acl $acl */
$acl = $services->get('Omeka\Acl');

$resource = IndexController::class;

/** Rol => [privilegio => ¿debe entrar?] */
$expected = [
    'global_admin' => ['index' => true, 'recatalog-apply' => true, 'recatalog-undo' => true, 'config' => true],
    'site_admin' => ['index' => true, 'recatalog-apply' => true, 'recatalog-undo' => true, 'config' => true],
    // El editor cura pero NO configura: la clave API del LLM y los vocabularios
    // del catálogo no son suyos.
    'editor' => ['index' => true, 'recatalog-apply' => true, 'recatalog-undo' => true, 'config' => false],
    // site_editor lo añade IsolatedSites heredando de editor: hereda la curación
    // y, con el allow por privilegio, NO hereda la configuración.
    'site_editor' => ['index' => true, 'recatalog-apply' => true, 'recatalog-undo' => true, 'config' => false],
    'researcher' => ['index' => false, 'recatalog-apply' => false, 'recatalog-undo' => false, 'config' => false],
    'author' => ['index' => false, 'recatalog-apply' => false, 'recatalog-undo' => false, 'config' => false],
];

$failures = 0;
$checks = 0;
foreach ($expected as $role => $privileges) {
    if (!$acl->hasRole($role)) {
        printf("  [SALTA] el rol %s no existe en esta instalación\n", $role);
        continue;
    }
    foreach ($privileges as $privilege => $shouldPass) {
        $actual = $acl->isAllowed($role, $resource, $privilege);
        $ok = $actual === $shouldPass;
        $checks++;
        if (!$ok) {
            $failures++;
        }
        printf(
            "  [%s] %-13s %-18s espera=%s obtiene=%s\n",
            $ok ? ' OK ' : 'FALLA',
            $role,
            $privilege,
            $shouldPass ? 'entra' : 'NO entra',
            $actual ? 'entra' : 'NO entra'
        );
    }
}

// El módulo ya no se configura desde el listado de Módulos: una sola puerta.
$ini = parse_ini_file(__DIR__ . '/../../config/module.ini', true);
$configurable = (bool) ($ini['info']['configurable'] ?? false);
$checks++;
if ($configurable) {
    $failures++;
}
printf(
    "  [%s] module.ini configurable = %s (se espera falso: una sola puerta)\n",
    $configurable ? 'FALLA' : ' OK ',
    var_export($configurable, true)
);

printf("\n%d comprobaciones, %d fallos\n", $checks, $failures);
exit($failures ? 1 : 0);
