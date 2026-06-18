<?php

declare(strict_types=1);

namespace OERManager\Test;

use PHPUnit\Framework\TestCase;

/**
 * Smoke test del contrato del scaffold (FASE 1, TASK-002/008).
 *
 * Valida config/module.config.php y config/module.ini sin arrancar Omeka:
 * el array de config se obtiene con `include` y `::class` se resuelve en
 * tiempo de compilación, así que no requiere el core en el host. Detecta
 * regresiones en rutas, navegación y registro de controladores.
 */
final class ModuleConfigTest extends TestCase
{
    private const MODULE_ROOT = __DIR__ . '/..';

    private array $config;

    protected function setUp(): void
    {
        $this->config = include self::MODULE_ROOT . '/config/module.config.php';
    }

    public function testConfigIsArrayWithExpectedKeys(): void
    {
        $this->assertIsArray($this->config);
        foreach (['controllers', 'router', 'navigation', 'view_manager', 'form_elements'] as $key) {
            $this->assertArrayHasKey($key, $this->config, "Falta la clave de config '$key'");
        }
    }

    public function testAdminChildRouteIsRegistered(): void
    {
        $route = $this->config['router']['routes']['admin']['child_routes']['oer-manager'] ?? null;
        $this->assertIsArray($route, 'La ruta admin hija oer-manager no está registrada');
        $this->assertSame('/oer-manager[/:action]', $route['options']['route']);
        $this->assertSame('OERManager\Controller\Admin', $route['options']['defaults']['__NAMESPACE__']);
        $this->assertSame('index', $route['options']['defaults']['action']);
    }

    public function testNavigationPointsToAdminRoute(): void
    {
        $entries = $this->config['navigation']['AdminModule'] ?? [];
        $routes = array_column($entries, 'route');
        $this->assertContains('admin/oer-manager', $routes);
    }

    public function testIndexControllerIsRegistered(): void
    {
        $factories = $this->config['controllers']['factories'] ?? [];
        $this->assertArrayHasKey(\OERManager\Controller\Admin\IndexController::class, $factories);
    }

    public function testMasterViewQueryServiceIsRegistered(): void
    {
        $factories = $this->config['service_manager']['factories'] ?? [];
        $this->assertArrayHasKey(\OERManager\Service\MasterViewQuery::class, $factories);
    }

    public function testIntegrityCheckerServiceIsRegistered(): void
    {
        $invokables = $this->config['service_manager']['invokables'] ?? [];
        $this->assertArrayHasKey(\OERManager\Service\IntegrityChecker::class, $invokables);
    }

    public function testAlignmentStatusColumnTypeIsRegistered(): void
    {
        $invokables = $this->config['column_types']['invokables'] ?? [];
        $this->assertSame(
            \OERManager\ColumnType\AlignmentStatus::class,
            $invokables['oerAlignmentStatus'] ?? null
        );
    }

    public function testModuleIniDeclaresOmeka42Constraint(): void
    {
        $ini = parse_ini_file(self::MODULE_ROOT . '/config/module.ini', true);
        $this->assertSame('^4.2.0', $ini['info']['omeka_version_constraint']);
        $this->assertArrayHasKey('name', $ini['info']);
        $this->assertArrayHasKey('version', $ini['info']);
    }
}
