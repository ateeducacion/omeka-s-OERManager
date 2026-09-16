<?php

declare(strict_types=1);

namespace OERManager\Test;

use OERManager\Service\Governance\GovernanceColumns;
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

    /**
     * TASK-028 rebanada 3b: IntegrityChecker pasó de invokable a factory al
     * ganar una dependencia (VocabEntries\Licence, para license_not_in_vocab).
     */
    public function testIntegrityCheckerServiceIsRegistered(): void
    {
        $factories = $this->config['service_manager']['factories'] ?? [];
        $this->assertArrayHasKey(\OERManager\Service\IntegrityChecker::class, $factories);
    }

    public function testAlignmentStatusColumnTypeIsRegistered(): void
    {
        $invokables = $this->config['column_types']['invokables'] ?? [];
        $this->assertSame(
            \OERManager\ColumnType\AlignmentStatus::class,
            $invokables['oerAlignmentStatus'] ?? null
        );
    }

    public function testOerColumnTypesAreRegistered(): void
    {
        $invokables = $this->config['column_types']['invokables'] ?? [];
        $expected = [
            'oerIsPublic' => \OERManager\ColumnType\IsPublic::class,
            'oerModified' => \OERManager\ColumnType\Modified::class,
            'oerId' => \OERManager\ColumnType\Id::class,
            'oerResourceTemplate' => \OERManager\ColumnType\ResourceTemplate::class,
        ];
        foreach ($expected as $name => $class) {
            $this->assertSame($class, $invokables[$name] ?? null, "Falta el column type '$name'");
        }
        $this->assertArrayHasKey(
            'oerValue',
            $this->config['column_types']['factories'] ?? [],
            'oerValue necesita factory: el Value del core lleva dependencias'
        );
    }

    /**
     * Las columnas por defecto deben referirse a tipos realmente registrados;
     * un tipo desconocido lo salta el core en silencio y la columna desaparece
     * de la vista sin avisar.
     */
    public function testColumnDefaultsUseRegisteredTypes(): void
    {
        $columns = $this->config['column_defaults']['admin']['oer_items'] ?? null;
        $this->assertIsArray($columns, 'Faltan las columnas por defecto de oer_items');
        $this->assertCount(6, $columns, 'Anclaje y Curricular se funden en una sola columna');
        $registered = array_merge(
            array_keys($this->config['column_types']['invokables'] ?? []),
            array_keys($this->config['column_types']['factories'] ?? [])
        );
        foreach ($columns as $column) {
            $this->assertContains($column['type'], $registered, "Tipo sin registrar: {$column['type']}");
        }
    }

    public function testGovernanceColumnTypesAreRegistered(): void
    {
        $config = include __DIR__ . '/../config/module.config.php';
        $types = array_merge(
            array_keys($config['column_types']['invokables'] ?? []),
            array_keys($config['column_types']['factories'] ?? [])
        );

        // `oerGovernanceValue` sigue registrado aunque ya no esté en los
        // defaults: las selecciones de columnas ya guardadas lo usan, y el core
        // salta en silencio un tipo desconocido (TASK-042).
        foreach (['oerIntegrity', 'oerCurricular', 'oerGovernanceValue', 'oerLicence', 'oerResourceType'] as $type) {
            $this->assertContains($type, $types);
        }
    }

    /**
     * Ocho columnas: el tope que TASK-027 §3 fijó (selección + 8). El Título no
     * cuenta aquí porque lo pinta la plantilla, no el mecanismo de columnas.
     */
    public function testDefaultColumnsAreTheGovernanceSet(): void
    {
        $config = include __DIR__ . '/../config/module.config.php';
        $defaults = $config['column_defaults']['admin']['oer_items'] ?? [];

        // Seis: `oerAlignmentStatus` sale del juego por defecto porque
        // `oerCurricular` absorbe su señal («Anclaje curricular»). Sigue
        // registrada y su statusFor() alimenta el filtro, que no cambia.
        $this->assertCount(6, $defaults);
        $this->assertSame([
            'oerIntegrity',
            'oerCurricular',
            GovernanceColumns::RESOURCE_TYPE,
            GovernanceColumns::LICENCE,
            'oerIsPublic',
            'oerModified',
        ], array_column($defaults, 'type'));
    }

    /**
     * TASK-042: la property va con el tipo, no con la configuración. Si los
     * defaults volvieran a fijar `property_term`, un usuario que quite la
     * columna no podría reañadirla igual desde su selector.
     */
    public function testGovernanceDefaultColumnsDoNotCarryTheirProperty(): void
    {
        $config = include __DIR__ . '/../config/module.config.php';
        $defaults = $config['column_defaults']['admin']['oer_items'] ?? [];

        $this->assertSame([], array_values(array_filter(array_column($defaults, 'property_term'))));
    }

    /**
     * El rótulo pasa a «Anclaje» (decisión del propietario, 2026-08-03).
     *
     * No se instancia AlignmentStatus: implementa Omeka\ColumnType\ColumnTypeInterface,
     * ausente del vendor/ del módulo en el host, y ese autoload produce un fatal aquí
     * (ya ocurrió en una tarea anterior). Se lee el literal del fuente en su lugar.
     */
    public function testAlignmentColumnIsLabelledAnclaje(): void
    {
        $source = file_get_contents(self::MODULE_ROOT . '/src/ColumnType/AlignmentStatus.php');
        $this->assertIsString($source, 'No se pudo leer AlignmentStatus.php');

        $start = strpos($source, 'function getLabel(): string');
        $this->assertIsInt($start, 'No se encuentra el método getLabel() en AlignmentStatus');
        $end = strpos($source, 'function getResourceTypes', $start);
        $this->assertIsInt($end, 'No se encuentra el método getResourceTypes() en AlignmentStatus');

        $body = substr($source, $start, $end - $start);
        $this->assertStringContainsString("return 'Anclaje'; // @translate", $body);
    }

    public function testBrowseDefaultsAreRegisteredForOerItems(): void
    {
        $defaults = $this->config['browse_defaults']['admin']['oer_items'] ?? null;
        $this->assertIsArray($defaults);
        $this->assertSame('modified', $defaults['sort_by'] ?? null);
        $this->assertSame('desc', $defaults['sort_order'] ?? null);
    }

    public function testAiInvokableServicesAreRegistered(): void
    {
        $invokables = $this->config['service_manager']['invokables'] ?? [];
        foreach ([
            \OERManager\Service\Ai\PromptBuilder::class,
            \OERManager\Service\Ai\ResponseParser::class,
            \OERManager\Service\Ai\EvaluationScorer::class,
        ] as $service) {
            $this->assertArrayHasKey($service, $invokables, "Falta invokable IA $service");
        }
    }

    public function testAiFactoryServicesAreRegistered(): void
    {
        $factories = $this->config['service_manager']['factories'] ?? [];
        foreach ([
            \OERManager\Service\Llm\HttpTransportInterface::class,
            \OERManager\Service\Llm\LlmClientInterface::class,
            \OERManager\Service\Ai\TermResolverInterface::class,
            \OERManager\Service\Content\MediaSourceInterface::class,
            \OERManager\Service\Content\ContentExtractor::class,
            \OERManager\Service\Content\MediaVisionExtractor::class,
            \OERManager\Service\Ai\CurricularClassifier::class,
            \OERManager\Service\Ai\TagClassifier::class,
            \OERManager\Service\Ai\AiCataloguer::class,
        ] as $service) {
            $this->assertArrayHasKey($service, $factories, "Falta factory IA $service");
        }
    }

    public function testModuleIniDeclaresOmeka42Constraint(): void
    {
        $ini = parse_ini_file(self::MODULE_ROOT . '/config/module.ini', true);
        $this->assertSame('^4.2.0', $ini['info']['omeka_version_constraint']);
        $this->assertArrayHasKey('name', $ini['info']);
        $this->assertArrayHasKey('version', $ini['info']);
    }
}
