<?php
// CLI only: these harnesses bootstrap Omeka and some write to the catalogue,
// so they must never run over HTTP (security review of TASK-067).
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

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
use OERManager\Controller\Admin\StatsController;

$application = \Omeka\Mvc\Application::init(require 'application/config/application.config.php');
$services = $application->getServiceManager();
/** @var \Omeka\Permissions\Acl $acl */
$acl = $services->get('Omeka\Acl');

/** Rol => [privilegio => ¿debe entrar?] */
// TASK-067 (ADR-0023): OERManager grants only to the REA roles and global_admin.
// The full matrix is pinned on the host (ReaAclTest) and over HTTP with real
// users in rea-roles-check.php; this is the quick read-only check of the real ACL.
$none = ['index' => false, 'recatalog-apply' => false, 'recatalog-undo' => false, 'config' => false,
    'curriculum-tree' => false, 'propose' => false];
$expectedIndex = [
    'global_admin' => ['index' => true, 'recatalog-apply' => true, 'recatalog-undo' => true, 'config' => true,
        'curriculum-tree' => true, 'propose' => true],
    'rea_curador' => ['index' => true, 'recatalog-apply' => true, 'recatalog-undo' => true, 'config' => false,
        'curriculum-tree' => true, 'propose' => true],
    'rea_proponente' => ['propose' => true] + $none,
    'site_admin' => $none,
    'editor' => $none,
    // site_editor (IsolatedSites, parent editor) gets nothing from OERManager.
    'site_editor' => $none,
    'reviewer' => $none,
    'researcher' => $none,
    'author' => $none,
];

$stats = ['index' => true, 'export' => true, 'layout' => true];
$noStats = ['index' => false, 'export' => false, 'layout' => false];
$expectedStats = [
    'global_admin' => $stats,
    'rea_curador' => $stats,
    'rea_proponente' => $noStats,
    'site_admin' => $noStats,
    'editor' => $noStats,
    'site_editor' => $noStats,
    'reviewer' => $noStats,
    'researcher' => $noStats,
    'author' => $noStats,
];

$failures = 0;
$checks = 0;
foreach ([[IndexController::class, $expectedIndex], [StatsController::class, $expectedStats]] as [$resource, $expected]) {
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
                "  [%s] %-6s %-13s %-18s espera=%s obtiene=%s\n",
                $ok ? ' OK ' : 'FALLA',
                IndexController::class === $resource ? 'index' : 'stats',
                $role,
                $privilege,
                $shouldPass ? 'entra' : 'NO entra',
                $actual ? 'entra' : 'NO entra'
            );
        }
    }
}

// TASK-067: the REA roles hang from IsolatedSites' site_editor (load order:
// the core ksorts module ids, so IsolatedSites bootstraps first).
foreach (['rea_proponente', 'rea_curador'] as $role) {
    $ok = $acl->hasRole($role) && $acl->hasRole('site_editor') && $acl->inheritsRole($role, 'site_editor', true);
    $checks++;
    if (!$ok) {
        $failures++;
    }
    printf("  [%s] %s hereda de site_editor\n", $ok ? ' OK ' : 'FALLA', $role);
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
