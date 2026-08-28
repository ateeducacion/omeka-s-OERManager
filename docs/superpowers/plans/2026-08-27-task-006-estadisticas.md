# TASK-006 — Estadísticas visuales del catálogo — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Construir la página "Estadísticas" del admin (conteo simple por dimensión, cruce genérico de 2 dimensiones, % de completitud) con export CSV, sin librería de gráficos de terceros.

**Architecture:** `StatsController` nuevo (ruta/nav/ACL propias) orquesta cinco piezas en `Service\Stats\`: `CatalogSnapshot` (trae el catálogo, toca el core), `DimensionFacts` (extrae hechos planos por item, toca el core — frontera puerto/adaptador), y tres agregadores **puros** (`DimensionCounter`, `DimensionCrosser`, `CompletenessAggregator`) más un formateador **puro** (`CsvExport`). El controlador relabela los ids de término a título (una sola llamada batch) y sirve un JSON pequeño que un módulo JS puro (`asset/js/stats/charts.js`) pinta como SVG/tabla, sin dependencia externa.

**Tech Stack:** PHP 8.4 / Omeka-S 4.2 (Laminas MVC, `Omeka\Api\Manager`), PHPUnit 11.5, JS ES modules sin build step, `node --test`.

**Spec:** `docs/superpowers/specs/2026-08-27-task-006-estadisticas-design.md`

## Global Constraints

- PHP 8.4, sin sintaxis de 8.5; PSR-12 (`make lint` en verde antes de cada commit).
- Sin tablas Doctrine propias (NFR-002) — nada de esto persiste nada nuevo.
- Sin librería de gráficos de terceros ni dependencia npm nueva (spec §5).
- ACL por privilegio, nunca por controlador entero — `editor`+`site_admin` para `index`/`export` de `StatsController` (spec §2.1, §3).
- Tope de catálogo: `ComputedFilter::HARD_CAP` (2000), reusado tal cual, con aviso de "resultado acotado" si se supera (spec §4).
- Cardinalidad múltiple: un item con varios valores en una dimensión cuenta **una vez por cada valor** (PEND-007/TASK-036, spec §4).
- Alcance: **todo el catálogo, siempre** — sin acoplamiento con los filtros de la vista maestra (spec §2.3).
- Fuera de alcance: export PDF, caché de agregación, drill-down a la vista maestra (spec §8).
- Clases que tipan `ItemRepresentation`/`Omeka\Api\Manager` en un parámetro invocado no son testeables en host (limitación documentada en `project-memory.md`) — se verifican en el arnés de contenedor.

---

### Task 1: `Stats\DimensionCounter` (puro)

**Files:**
- Create: `src/Service/Stats/DimensionCounter.php`
- Test: `test/Service/Stats/DimensionCounterTest.php`

**Interfaces:**
- Consumes: nada (recibe directamente el array de "hechos", forma `array<int, array<string, int[]|string|null>>`).
- Produces: `DimensionCounter::count(array $facts, string $dimension): array<int|string, int>` — usado por `StatsController` (Task 8) y por los tests.

- [ ] **Step 1: Write the failing test**

```php
<?php

declare(strict_types=1);

namespace OERManager\Test\Service\Stats;

use OERManager\Service\Stats\DimensionCounter;
use PHPUnit\Framework\TestCase;

/**
 * Pieza pura (RF-007, TASK-006): agrega conteos por dimensión sobre los
 * "hechos" ya extraídos de los items (ver DimensionFacts, Task 5). No toca el
 * core, así que es TDD real en host.
 */
final class DimensionCounterTest extends TestCase
{
    public function testCatalogoVacioDaConteoVacio(): void
    {
        $counter = new DimensionCounter();
        $this->assertSame([], $counter->count([], 'materia'));
    }

    public function testCuentaUnaVezPorItemConValorUnico(): void
    {
        $facts = [
            1 => ['materia' => [10]],
            2 => ['materia' => [10]],
            3 => ['materia' => [20]],
        ];
        $counter = new DimensionCounter();
        $this->assertSame([10 => 2, 20 => 1], $counter->count($facts, 'materia'));
    }

    /** PEND-007/TASK-036: cardinalidad múltiple — un item cuenta en CADA valor que tiene. */
    public function testItemConDosValoresCuentaEnAmbos(): void
    {
        $facts = [
            1 => ['etapa' => [100, 200]],
        ];
        $counter = new DimensionCounter();
        $this->assertSame([100 => 1, 200 => 1], $counter->count($facts, 'etapa'));
    }

    public function testDimensionLiteralComoLicencia(): void
    {
        $facts = [
            1 => ['licencia' => 'ccbysa'],
            2 => ['licencia' => 'ccbysa'],
            3 => ['licencia' => null],
        ];
        $counter = new DimensionCounter();
        $this->assertSame(['ccbysa' => 2], $counter->count($facts, 'licencia'));
    }

    public function testDimensionAusenteEnUnaFilaSeIgnora(): void
    {
        $facts = [
            1 => ['materia' => [10]],
            2 => [],
        ];
        $counter = new DimensionCounter();
        $this->assertSame([10 => 1], $counter->count($facts, 'materia'));
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `vendor/bin/phpunit -c test/phpunit.xml --filter DimensionCounterTest`
Expected: FAIL — `Class "OERManager\Service\Stats\DimensionCounter" not found`

- [ ] **Step 3: Write minimal implementation**

```php
<?php

declare(strict_types=1);

namespace OERManager\Service\Stats;

/**
 * Conteo simple por dimensión (RF-007, TASK-006). Puro: recibe los "hechos"
 * ya extraídos por DimensionFacts (Task 5), nunca un ItemRepresentation.
 *
 * Semántica de cardinalidad múltiple (PEND-007, confirmada en TASK-036): un
 * item con varios valores en una dimensión cuenta UNA VEZ POR CADA VALOR, como
 * un facetado por etiquetas — la suma de conteos puede superar el número de
 * items.
 */
final class DimensionCounter
{
    /**
     * @param array<int, array<string, int[]|string|null>> $facts
     * @return array<int|string, int>
     */
    public function count(array $facts, string $dimension): array
    {
        $counts = [];
        foreach ($facts as $row) {
            $value = $row[$dimension] ?? null;
            foreach ($this->normalize($value) as $single) {
                $counts[$single] = ($counts[$single] ?? 0) + 1;
            }
        }
        return $counts;
    }

    /** @return list<int|string> */
    private function normalize(int|string|array|null $value): array
    {
        if (is_array($value)) {
            return $value;
        }
        return null !== $value ? [$value] : [];
    }
}
```

- [ ] **Step 4: Run test to verify it passes**

Run: `vendor/bin/phpunit -c test/phpunit.xml --filter DimensionCounterTest`
Expected: PASS — 5 tests

- [ ] **Step 5: Commit**

```bash
git add src/Service/Stats/DimensionCounter.php test/Service/Stats/DimensionCounterTest.php
git commit -m "feat(stats): agregador puro de conteo por dimensión (TASK-006)"
```

---

### Task 2: `Stats\DimensionCrosser` (puro)

**Files:**
- Create: `src/Service/Stats/DimensionCrosser.php`
- Test: `test/Service/Stats/DimensionCrosserTest.php`

**Interfaces:**
- Consumes: mismo array de "hechos" que `DimensionCounter` (Task 1).
- Produces: `DimensionCrosser::cross(array $facts, string $dimensionA, string $dimensionB): array<int|string, array<int|string, int>>` — usado por `StatsController` (Task 8/9).

- [ ] **Step 1: Write the failing test**

```php
<?php

declare(strict_types=1);

namespace OERManager\Test\Service\Stats;

use OERManager\Service\Stats\DimensionCrosser;
use PHPUnit\Framework\TestCase;

final class DimensionCrosserTest extends TestCase
{
    public function testCatalogoVacioDaTablaVacia(): void
    {
        $crosser = new DimensionCrosser();
        $this->assertSame([], $crosser->cross([], 'materia', 'etapa'));
    }

    public function testCruceSimpleUnValorPorDimension(): void
    {
        $facts = [
            1 => ['materia' => [10], 'etapa' => [100]],
            2 => ['materia' => [10], 'etapa' => [100]],
            3 => ['materia' => [20], 'etapa' => [200]],
        ];
        $crosser = new DimensionCrosser();
        $this->assertSame(
            [10 => [100 => 2], 20 => [200 => 1]],
            $crosser->cross($facts, 'materia', 'etapa')
        );
    }

    /** PEND-007/TASK-036: un item con 2 cursos cruza con CADA curso. */
    public function testItemConVariosValoresCruzaConCadaUno(): void
    {
        $facts = [
            1 => ['materia' => [10], 'etapa' => [100, 200]],
        ];
        $crosser = new DimensionCrosser();
        $this->assertSame(
            [10 => [100 => 1, 200 => 1]],
            $crosser->cross($facts, 'materia', 'etapa')
        );
    }

    public function testDimensionLiteralEnElCruce(): void
    {
        $facts = [
            1 => ['materia' => [10], 'licencia' => 'ccbysa'],
        ];
        $crosser = new DimensionCrosser();
        $this->assertSame(
            [10 => ['ccbysa' => 1]],
            $crosser->cross($facts, 'materia', 'licencia')
        );
    }

    public function testValorAusenteEnUnaDimensionNoAporta(): void
    {
        $facts = [
            1 => ['materia' => [10], 'etapa' => []],
        ];
        $crosser = new DimensionCrosser();
        $this->assertSame([], $crosser->cross($facts, 'materia', 'etapa'));
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `vendor/bin/phpunit -c test/phpunit.xml --filter DimensionCrosserTest`
Expected: FAIL — `Class "OERManager\Service\Stats\DimensionCrosser" not found`

- [ ] **Step 3: Write minimal implementation**

```php
<?php

declare(strict_types=1);

namespace OERManager\Service\Stats;

/**
 * Cruce de 2 dimensiones (RF-007, TASK-006). Puro, mismo array de "hechos"
 * que DimensionCounter. Misma semántica de cardinalidad múltiple: un item con
 * varios valores en una dimensión aporta a CADA combinación (a, b) posible
 * entre sus valores de A y sus valores de B.
 */
final class DimensionCrosser
{
    /**
     * @param array<int, array<string, int[]|string|null>> $facts
     * @return array<int|string, array<int|string, int>>
     */
    public function cross(array $facts, string $dimensionA, string $dimensionB): array
    {
        $table = [];
        foreach ($facts as $row) {
            $valuesA = $this->normalize($row[$dimensionA] ?? null);
            $valuesB = $this->normalize($row[$dimensionB] ?? null);
            foreach ($valuesA as $a) {
                foreach ($valuesB as $b) {
                    $table[$a][$b] = ($table[$a][$b] ?? 0) + 1;
                }
            }
        }
        return $table;
    }

    /** @return list<int|string> */
    private function normalize(int|string|array|null $value): array
    {
        if (is_array($value)) {
            return $value;
        }
        return null !== $value ? [$value] : [];
    }
}
```

- [ ] **Step 4: Run test to verify it passes**

Run: `vendor/bin/phpunit -c test/phpunit.xml --filter DimensionCrosserTest`
Expected: PASS — 5 tests

- [ ] **Step 5: Commit**

```bash
git add src/Service/Stats/DimensionCrosser.php test/Service/Stats/DimensionCrosserTest.php
git commit -m "feat(stats): agregador puro de cruce de 2 dimensiones (TASK-006)"
```

---

### Task 3: `Stats\CompletenessAggregator` (puro)

**Files:**
- Create: `src/Service/Stats/CompletenessAggregator.php`
- Test: `test/Service/Stats/CompletenessAggregatorTest.php`

**Interfaces:**
- Consumes: `list<string>` de valores `IntegrityResult::getStatus()` ('ok'|'warning'|'error'), ya calculados por el controlador con `IntegrityChecker` existente.
- Produces: `CompletenessAggregator::aggregate(array $statuses): array{ok:int,warning:int,error:int,total:int,okPercent:float}` — usado por `StatsController` (Task 8/9).

- [ ] **Step 1: Write the failing test**

```php
<?php

declare(strict_types=1);

namespace OERManager\Test\Service\Stats;

use OERManager\Service\Stats\CompletenessAggregator;
use PHPUnit\Framework\TestCase;

/** % de completitud (RF-006/RF-007, TASK-006), sobre los status ya calculados por IntegrityChecker. */
final class CompletenessAggregatorTest extends TestCase
{
    public function testCatalogoVacio(): void
    {
        $aggregator = new CompletenessAggregator();
        $this->assertSame(
            ['ok' => 0, 'warning' => 0, 'error' => 0, 'total' => 0, 'okPercent' => 0.0],
            $aggregator->aggregate([])
        );
    }

    public function testCuentaLosTresEstados(): void
    {
        $aggregator = new CompletenessAggregator();
        $result = $aggregator->aggregate(['ok', 'ok', 'warning', 'error']);
        $this->assertSame(2, $result['ok']);
        $this->assertSame(1, $result['warning']);
        $this->assertSame(1, $result['error']);
        $this->assertSame(4, $result['total']);
    }

    public function testOkPercentRedondeaAUnDecimal(): void
    {
        $aggregator = new CompletenessAggregator();
        // 1 ok de 3 = 33.333...% -> 33.3
        $result = $aggregator->aggregate(['ok', 'warning', 'error']);
        $this->assertSame(33.3, $result['okPercent']);
    }

    public function testTodosOkEsCienPorCiento(): void
    {
        $aggregator = new CompletenessAggregator();
        $result = $aggregator->aggregate(['ok', 'ok']);
        $this->assertSame(100.0, $result['okPercent']);
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `vendor/bin/phpunit -c test/phpunit.xml --filter CompletenessAggregatorTest`
Expected: FAIL — `Class "OERManager\Service\Stats\CompletenessAggregator" not found`

- [ ] **Step 3: Write minimal implementation**

```php
<?php

declare(strict_types=1);

namespace OERManager\Service\Stats;

use OERManager\Service\IntegrityResult;

/**
 * % de completitud del catálogo (RF-006/RF-007, TASK-006). Puro: agrega los
 * status ya calculados por IntegrityChecker::check()->getStatus() — no vuelve
 * a leer el item.
 */
final class CompletenessAggregator
{
    /**
     * @param list<string> $statuses Cada elemento: IntegrityResult::STATUS_OK/WARNING/ERROR
     * @return array{ok:int,warning:int,error:int,total:int,okPercent:float}
     */
    public function aggregate(array $statuses): array
    {
        $counts = [
            IntegrityResult::STATUS_OK => 0,
            IntegrityResult::STATUS_WARNING => 0,
            IntegrityResult::STATUS_ERROR => 0,
        ];
        foreach ($statuses as $status) {
            if (isset($counts[$status])) {
                $counts[$status]++;
            }
        }
        $total = count($statuses);
        return [
            'ok' => $counts[IntegrityResult::STATUS_OK],
            'warning' => $counts[IntegrityResult::STATUS_WARNING],
            'error' => $counts[IntegrityResult::STATUS_ERROR],
            'total' => $total,
            'okPercent' => $total > 0
                ? round($counts[IntegrityResult::STATUS_OK] / $total * 100, 1)
                : 0.0,
        ];
    }
}
```

- [ ] **Step 4: Run test to verify it passes**

Run: `vendor/bin/phpunit -c test/phpunit.xml --filter CompletenessAggregatorTest`
Expected: PASS — 4 tests

- [ ] **Step 5: Commit**

```bash
git add src/Service/Stats/CompletenessAggregator.php test/Service/Stats/CompletenessAggregatorTest.php
git commit -m "feat(stats): agregador puro de % de completitud (TASK-006)"
```

---

### Task 4: `Stats\CsvExport` (puro)

**Files:**
- Create: `src/Service/Stats/CsvExport.php`
- Test: `test/Service/Stats/CsvExportTest.php`

**Interfaces:**
- Consumes: nada (recibe cabeceras y filas ya formateadas por el controlador).
- Produces: `CsvExport::toCsv(array $headers, array $rows): string` — usado por `StatsController::exportAction()` (Task 9).

- [ ] **Step 1: Write the failing test**

```php
<?php

declare(strict_types=1);

namespace OERManager\Test\Service\Stats;

use OERManager\Service\Stats\CsvExport;
use PHPUnit\Framework\TestCase;

/** Formateador CSV puro (RF-007, TASK-006): mismos datos que pintó el gráfico, sin cálculo aparte. */
final class CsvExportTest extends TestCase
{
    public function testCabeceraYFilas(): void
    {
        $csv = (new CsvExport())->toCsv(['materia', 'conteo'], [
            ['Matemáticas', 3],
            ['Lengua', 1],
        ]);
        $lines = preg_split('/\r\n|\n/', trim($csv));
        $this->assertSame('materia,conteo', $lines[0]);
        $this->assertSame('Matemáticas,3', $lines[1]);
        $this->assertSame('Lengua,1', $lines[2]);
    }

    public function testSoloCabeceraSinFilas(): void
    {
        $csv = (new CsvExport())->toCsv(['estado', 'conteo'], []);
        $this->assertSame('estado,conteo', trim($csv));
    }

    public function testEscapaComasYComillas(): void
    {
        $csv = (new CsvExport())->toCsv(['a', 'b'], [['uno, dos', 'con "comillas"']]);
        $this->assertStringContainsString('"uno, dos"', $csv);
        $this->assertStringContainsString('"con ""comillas"""', $csv);
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `vendor/bin/phpunit -c test/phpunit.xml --filter CsvExportTest`
Expected: FAIL — `Class "OERManager\Service\Stats\CsvExport" not found`

- [ ] **Step 3: Write minimal implementation**

```php
<?php

declare(strict_types=1);

namespace OERManager\Service\Stats;

/**
 * Export CSV de los datos agregados (RF-007, TASK-006). Puro: recibe
 * cabeceras y filas ya formateadas — los mismos números que pintó el
 * gráfico, no un cálculo aparte.
 */
final class CsvExport
{
    /**
     * @param list<string> $headers
     * @param list<list<int|string>> $rows
     */
    public function toCsv(array $headers, array $rows): string
    {
        $handle = fopen('php://temp', 'r+');
        fputcsv($handle, $headers);
        foreach ($rows as $row) {
            fputcsv($handle, $row);
        }
        rewind($handle);
        $csv = (string) stream_get_contents($handle);
        fclose($handle);
        return $csv;
    }
}
```

- [ ] **Step 4: Run test to verify it passes**

Run: `vendor/bin/phpunit -c test/phpunit.xml --filter CsvExportTest`
Expected: PASS — 3 tests

- [ ] **Step 5: Commit**

```bash
git add src/Service/Stats/CsvExport.php test/Service/Stats/CsvExportTest.php
git commit -m "feat(stats): formateador CSV puro (TASK-006)"
```

---

### Task 5: `Stats\DimensionFacts` (toca el core — sin test de host)

**Files:**
- Create: `src/Service/Stats/DimensionFacts.php`

**Interfaces:**
- Consumes: `Omeka\Api\Representation\ItemRepresentation[]` (de `CatalogSnapshot::fetch()['items']`, Task 6).
- Produces:
  - `DimensionFacts::DIMENSION_TERMS` — `array<string,string>` (clave de dimensión => property RDF), usado por `StatsController` para iterar dimensiones.
  - `DimensionFacts::extract(array $items): array<int, array{etapa:int[],materia:int[],eje:int[],proyecto:int[],licencia:?string}>` — la entrada de `DimensionCounter`/`DimensionCrosser` (Tasks 1-2).
  - `DimensionFacts::distinctResourceIds(array $facts): int[]` — ids a resolver en una sola llamada batch (Task 8).

**Nota de testing:** esta clase tipa `ItemRepresentation` en un parámetro que `extract()` invoca de verdad (llama a `$item->value(...)`), así que instanciar el caso de prueba dispara el fatal de autoload del core documentado en `project-memory.md` («Limitación conocida del arnés»). **No lleva test de host** — se verifica en el arnés de contenedor del Task 11, igual que `CurriculumSearch::searchDimension()`.

- [ ] **Step 1: Escribir la clase directamente (sin test de host, ver nota arriba)**

```php
<?php

declare(strict_types=1);

namespace OERManager\Service\Stats;

use Omeka\Api\Representation\ItemRepresentation;

/**
 * Extrae los "hechos" de las 5 dimensiones de RF-007/ADR-0004 de un item, en
 * la forma plana que consumen DimensionCounter/DimensionCrosser (puros). Es
 * la frontera puerto/adaptador: la única pieza de Stats que toca
 * ItemRepresentation — todo lo que sigue trabaja sobre arrays.
 */
final class DimensionFacts
{
    /** Dimensiones resource:item (ADR-0004). La licencia es literal y se trata aparte. */
    public const DIMENSION_TERMS = [
        'etapa' => 'lrmi:educationalLevel',
        'materia' => 'schema:about',
        'eje' => 'dcterms:relation',
        'proyecto' => 'schema:isPartOf',
    ];

    public const LICENCE_TERM = 'dcterms:rights';

    /**
     * @param ItemRepresentation[] $items
     * @return array<int, array{etapa:int[],materia:int[],eje:int[],proyecto:int[],licencia:?string}>
     */
    public function extract(array $items): array
    {
        $facts = [];
        foreach ($items as $item) {
            $row = [];
            foreach (self::DIMENSION_TERMS as $key => $term) {
                $ids = [];
                foreach ($item->value($term, ['all' => true, 'default' => []]) as $value) {
                    $resource = $value->valueResource();
                    // Un literal en una property de enlace (D-3, ADR-0016) no
                    // resuelve a término: se omite del conteo en vez de romper.
                    if (null !== $resource) {
                        $ids[] = (int) $resource->id();
                    }
                }
                $row[$key] = $ids;
            }
            $licenceValue = $item->value(self::LICENCE_TERM);
            $row['licencia'] = null !== $licenceValue ? trim((string) $licenceValue->value()) : null;
            if ('' === $row['licencia']) {
                $row['licencia'] = null;
            }
            $facts[(int) $item->id()] = $row;
        }
        return $facts;
    }

    /**
     * Ids de término distintos usados en los hechos (etapa/materia/eje/proyecto),
     * para resolver sus títulos en UNA sola llamada batch (spec §4).
     *
     * @param array<int, array<string, int[]|string|null>> $facts
     * @return int[]
     */
    public function distinctResourceIds(array $facts): array
    {
        $ids = [];
        foreach ($facts as $row) {
            foreach (array_keys(self::DIMENSION_TERMS) as $key) {
                foreach ((array) ($row[$key] ?? []) as $id) {
                    $ids[(int) $id] = true;
                }
            }
        }
        return array_map('intval', array_keys($ids));
    }
}
```

- [ ] **Step 2: `make lint`**

Run: `make lint`
Expected: sin violaciones PSR-12

- [ ] **Step 3: Commit**

```bash
git add src/Service/Stats/DimensionFacts.php
git commit -m "feat(stats): extractor de hechos por dimensión (TASK-006, toca el core)"
```

---

### Task 6: `Stats\CatalogSnapshot` (toca el core — sin test de host)

**Files:**
- Create: `src/Service/Stats/CatalogSnapshot.php`
- Modify: `config/module.config.php` (factory del servicio)

**Interfaces:**
- Consumes: `Omeka\Api\Manager` (servicio nativo `'Omeka\ApiManager'`).
- Produces: `CatalogSnapshot::fetch(): array{items: ItemRepresentation[], truncated: bool}` — usado por `StatsController` (Task 8/9).

- [ ] **Step 1: Escribir la clase (sin test de host — toca `Omeka\Api\Manager`, mismo motivo que Task 5)**

```php
<?php

declare(strict_types=1);

namespace OERManager\Service\Stats;

use OERManager\Service\ComputedFilter;
use Omeka\Api\Manager as ApiManager;

/**
 * Trae hasta ComputedFilter::HARD_CAP items lrmi:LearningResource en una sola
 * llamada (spec §4) — mismo patrón que la rama computada de
 * IndexController::indexAction(). Sin acoplamiento con MasterViewQuery a
 * propósito (spec §2.3): Estadísticas siempre mira el catálogo completo, no
 * los filtros activos de la vista maestra.
 */
final class CatalogSnapshot
{
    public const LEARNING_RESOURCE_CLASS_TERM = 'lrmi:LearningResource';

    private ApiManager $api;
    private bool $classIdResolved = false;
    private ?int $learningResourceClassId = null;

    public function __construct(ApiManager $api)
    {
        $this->api = $api;
    }

    /** @return array{items: \Omeka\Api\Representation\ItemRepresentation[], truncated: bool} */
    public function fetch(): array
    {
        $response = $this->api->search('items', [
            'resource_class_id' => $this->resolveLearningResourceClassId(),
            'page' => 1,
            'per_page' => ComputedFilter::HARD_CAP,
        ]);
        return [
            'items' => $response->getContent(),
            'truncated' => $response->getTotalResults() > ComputedFilter::HARD_CAP,
        ];
    }

    private function resolveLearningResourceClassId(): ?int
    {
        if (!$this->classIdResolved) {
            $response = $this->api
                ->search('resource_classes', ['term' => self::LEARNING_RESOURCE_CLASS_TERM])
                ->getContent();
            $this->learningResourceClassId = $response ? $response[0]->id() : null;
            $this->classIdResolved = true;
        }
        return $this->learningResourceClassId;
    }
}
```

- [ ] **Step 2: Registrar el servicio en `config/module.config.php`**

Localiza el bloque `'service_manager' => ['factories' => [` (junto a `Service\MasterViewQuery::class`) y añade, e igualmente añade los 4 agregadores puros como `invokables` junto a `Service\IntegrityChecker::class`:

```php
// dentro de 'service_manager' => ['invokables' => [ ... ]]
Service\Stats\DimensionFacts::class => Service\Stats\DimensionFacts::class,
Service\Stats\DimensionCounter::class => Service\Stats\DimensionCounter::class,
Service\Stats\DimensionCrosser::class => Service\Stats\DimensionCrosser::class,
Service\Stats\CompletenessAggregator::class => Service\Stats\CompletenessAggregator::class,
Service\Stats\CsvExport::class => Service\Stats\CsvExport::class,
```

```php
// dentro de 'service_manager' => ['factories' => [ ... ]], junto a MasterViewQuery
Service\Stats\CatalogSnapshot::class => function ($container) {
    return new Service\Stats\CatalogSnapshot($container->get('Omeka\ApiManager'));
},
```

- [ ] **Step 3: `make lint`**

Run: `make lint`
Expected: sin violaciones PSR-12

- [ ] **Step 4: Commit**

```bash
git add src/Service/Stats/CatalogSnapshot.php config/module.config.php
git commit -m "feat(stats): CatalogSnapshot + registro de servicios de Stats (TASK-006)"
```

---

### Task 7: Ruta, navegación y ACL de `StatsController`

**Files:**
- Modify: `config/module.config.php` (router, navigation)
- Modify: `Module.php` (ACL)

**Interfaces:**
- Consumes: `Controller\Admin\StatsController::class` (el FQCN, aún sin implementar — se crea en Task 8; PHP no resuelve la clase hasta que se instancia, así que declarar la ruta/ACL primero es seguro).
- Produces: ruta con nombre `admin/oer-manager-stats` (acciones `index`/`export`), entrada de navegación "Estadísticas", privilegios ACL `index`/`export` para `editor`/`site_admin`.

- [ ] **Step 1: Añadir la ruta en `config/module.config.php`**

Dentro de `'router' => ['routes' => ['admin' => ['child_routes' => [` , como hermana de `'oer-manager'`:

```php
'oer-manager-stats' => [
    'type' => Segment::class,
    'options' => [
        'route' => '/oer-manager/stats[/:action]',
        'constraints' => [
            'action' => '[a-zA-Z][a-zA-Z0-9_-]*',
        ],
        'defaults' => [
            '__NAMESPACE__' => 'OERManager\Controller\Admin',
            'controller' => Controller\Admin\StatsController::class,
            'action' => 'index',
        ],
    ],
    'may_terminate' => true,
],
```

- [ ] **Step 2: Añadir la entrada de navegación**

Dentro de `'navigation' => ['AdminModule' => [[ ... 'pages' => [` (junto a la de "Configuración"):

```php
[
    'label' => 'Estadísticas', // @translate
    'route' => 'admin/oer-manager-stats',
    'resource' => Controller\Admin\StatsController::class,
    'privilege' => 'index',
],
```

- [ ] **Step 3: Registrar el controlador (factory vacía por ahora, se completa en Task 8)**

Dentro de `'controllers' => ['factories' => [` (junto a `IndexController`):

```php
Controller\Admin\StatsController::class => function ($container) {
    return new Controller\Admin\StatsController(
        $container->get(Service\Stats\CatalogSnapshot::class),
        $container->get(Service\Stats\DimensionFacts::class),
        $container->get(Service\Stats\DimensionCounter::class),
        $container->get(Service\Stats\DimensionCrosser::class),
        $container->get(Service\Stats\CompletenessAggregator::class),
        $container->get(Service\Stats\CsvExport::class),
        $container->get(Service\IntegrityChecker::class),
        $container->get('Omeka\ApiManager')
    );
},
```

- [ ] **Step 4: ACL en `Module.php`**

En `onBootstrap()`, junto al bloque de ACL existente:

```php
// Estadísticas: mismo nivel que la curación (spec TASK-006 §2.1). Datos
// agregados de solo lectura, no gobernanza sensible como `config`.
$acl->allow(
    ['editor', 'site_admin'],
    [Controller\Admin\StatsController::class],
    ['index', 'export']
);
```

- [ ] **Step 5: `make lint`**

Run: `make lint`
Expected: sin violaciones PSR-12 (la factory referencia una clase que aún no existe — PHP no la resuelve hasta ejecutar, así que el lint no falla por eso; sí fallaría `php -l` al cargar Module en el contenedor, por eso el Task 8 crea la clase inmediatamente después, en el mismo ciclo de commits antes de verificar en contenedor)

- [ ] **Step 6: Commit**

```bash
git add config/module.config.php Module.php
git commit -m "feat(stats): ruta, navegación y ACL de Estadísticas (TASK-006)"
```

---

### Task 8: `StatsController::indexAction` + vista

**Files:**
- Create: `src/Controller/Admin/StatsController.php`
- Create: `view/oer-manager/admin/stats/index.phtml`

**Interfaces:**
- Consumes: `CatalogSnapshot::fetch()` (Task 6), `DimensionFacts::DIMENSION_TERMS`/`extract()`/`distinctResourceIds()` (Task 5), `DimensionCounter::count()` (Task 1), `DimensionCrosser::cross()` (Task 2), `CompletenessAggregator::aggregate()` (Task 3), `IntegrityChecker::check(ItemRepresentation $item, bool $checkLinks): IntegrityResult` (existente).
- Produces: acción `indexAction()` que sirve `oer-manager/admin/stats/index` con las variables `counts`, `cross`, `completeness`, `truncated`, `statsData` (JSON para el JS del Task 10); método privado `relabelCounts()`/`relabelCrossTable()`/`transpose()` reutilizados por `exportAction()` (Task 9).

- [ ] **Step 1: Crear el controlador**

```php
<?php

declare(strict_types=1);

namespace OERManager\Controller\Admin;

use Laminas\Mvc\Controller\AbstractActionController;
use Laminas\View\Model\ViewModel;
use OERManager\Service\IntegrityChecker;
use OERManager\Service\Stats\CatalogSnapshot;
use OERManager\Service\Stats\CompletenessAggregator;
use OERManager\Service\Stats\CsvExport;
use OERManager\Service\Stats\DimensionCounter;
use OERManager\Service\Stats\DimensionCrosser;
use OERManager\Service\Stats\DimensionFacts;
use Omeka\Api\Manager as ApiManager;

/**
 * Estadísticas visuales del catálogo (RF-007, ADR-0004, TASK-006). Página de
 * solo lectura, independiente de los filtros de la vista maestra (spec §2.3):
 * siempre agrega sobre el catálogo completo, hasta ComputedFilter::HARD_CAP.
 */
class StatsController extends AbstractActionController
{
    /** Las 5 dimensiones de ADR-0004, en el orden en que se pintan. */
    private const DIMENSIONS = ['etapa', 'materia', 'eje', 'proyecto', 'licencia'];

    private CatalogSnapshot $catalogSnapshot;
    private DimensionFacts $dimensionFacts;
    private DimensionCounter $dimensionCounter;
    private DimensionCrosser $dimensionCrosser;
    private CompletenessAggregator $completenessAggregator;
    private CsvExport $csvExport;
    private IntegrityChecker $integrityChecker;
    private ApiManager $api;

    public function __construct(
        CatalogSnapshot $catalogSnapshot,
        DimensionFacts $dimensionFacts,
        DimensionCounter $dimensionCounter,
        DimensionCrosser $dimensionCrosser,
        CompletenessAggregator $completenessAggregator,
        CsvExport $csvExport,
        IntegrityChecker $integrityChecker,
        ApiManager $api
    ) {
        $this->catalogSnapshot = $catalogSnapshot;
        $this->dimensionFacts = $dimensionFacts;
        $this->dimensionCounter = $dimensionCounter;
        $this->dimensionCrosser = $dimensionCrosser;
        $this->completenessAggregator = $completenessAggregator;
        $this->csvExport = $csvExport;
        $this->integrityChecker = $integrityChecker;
        $this->api = $api;
    }

    public function indexAction()
    {
        $snapshot = $this->catalogSnapshot->fetch();
        $facts = $this->dimensionFacts->extract($snapshot['items']);
        $titles = $this->resolveTitles($facts);

        $counts = [];
        foreach (self::DIMENSIONS as $dimension) {
            $counts[$dimension] = $this->relabelCounts(
                $this->dimensionCounter->count($facts, $dimension),
                $dimension,
                $titles
            );
        }

        $cross = [];
        foreach ($this->dimensionPairs() as [$dimA, $dimB]) {
            $table = $this->dimensionCrosser->cross($facts, $dimA, $dimB);
            $labeled = $this->relabelCrossTable($table, $dimA, $dimB, $titles);
            $cross["$dimA/$dimB"] = $labeled;
            $cross["$dimB/$dimA"] = $this->transpose($labeled);
        }

        $statuses = [];
        foreach ($snapshot['items'] as $item) {
            $statuses[] = $this->integrityChecker->check($item, false)->getStatus();
        }
        $completeness = $this->completenessAggregator->aggregate($statuses);

        $view = new ViewModel();
        $view->setTemplate('oer-manager/admin/stats/index');
        $view->setVariable('dimensions', self::DIMENSIONS);
        $view->setVariable('counts', $counts);
        $view->setVariable('completeness', $completeness);
        $view->setVariable('truncated', $snapshot['truncated']);
        $view->setVariable('statsData', ['counts' => $counts, 'cross' => $cross, 'completeness' => $completeness]);
        return $view;
    }

    /** @return list<array{0:string,1:string}> Los 10 pares únicos entre las 5 dimensiones. */
    private function dimensionPairs(): array
    {
        $pairs = [];
        foreach (self::DIMENSIONS as $i => $dimA) {
            foreach (self::DIMENSIONS as $j => $dimB) {
                if ($j > $i) {
                    $pairs[] = [$dimA, $dimB];
                }
            }
        }
        return $pairs;
    }

    /**
     * Ids de término distintos de los hechos, resueltos a título en UNA sola
     * llamada batch (spec §4) — nunca N lecturas.
     *
     * @param array<int, array<string, int[]|string|null>> $facts
     * @return array<int, string>
     */
    private function resolveTitles(array $facts): array
    {
        $ids = $this->dimensionFacts->distinctResourceIds($facts);
        if ([] === $ids) {
            return [];
        }
        $response = $this->api->search('items', ['id' => $ids]);
        $titles = [];
        foreach ($response->getContent() as $item) {
            $titles[(int) $item->id()] = (string) $item->displayTitle();
        }
        return $titles;
    }

    /**
     * @param array<int|string, int> $counts
     * @param array<int, string> $titles
     * @return list<array{label:string,count:int}>
     */
    private function relabelCounts(array $counts, string $dimension, array $titles): array
    {
        $rows = [];
        foreach ($counts as $value => $count) {
            $rows[] = ['label' => $this->labelFor($dimension, $value, $titles), 'count' => $count];
        }
        usort($rows, static fn (array $a, array $b): int => $b['count'] <=> $a['count']);
        return $rows;
    }

    /**
     * @param array<int|string, array<int|string, int>> $table
     * @param array<int, string> $titles
     * @return array<string, array<string, int>>
     */
    private function relabelCrossTable(array $table, string $dimA, string $dimB, array $titles): array
    {
        $out = [];
        foreach ($table as $a => $bCounts) {
            $labelA = $this->labelFor($dimA, $a, $titles);
            foreach ($bCounts as $b => $count) {
                $labelB = $this->labelFor($dimB, $b, $titles);
                $out[$labelA][$labelB] = ($out[$labelA][$labelB] ?? 0) + $count;
            }
        }
        return $out;
    }

    /** @param array<int, string> $titles */
    private function labelFor(string $dimension, int|string $value, array $titles): string
    {
        return 'licencia' === $dimension ? (string) $value : ($titles[$value] ?? (string) $value);
    }

    /**
     * @param array<string, array<string, int>> $table
     * @return array<string, array<string, int>>
     */
    private function transpose(array $table): array
    {
        $out = [];
        foreach ($table as $a => $bCounts) {
            foreach ($bCounts as $b => $count) {
                $out[$b][$a] = $count;
            }
        }
        return $out;
    }
}
```

- [ ] **Step 2: Crear la vista**

```php
<?php
/**
 * @var \Laminas\View\Renderer\PhpRenderer $this
 * @var list<string> $dimensions
 * @var array<string, list<array{label:string,count:int}>> $counts
 * @var array{ok:int,warning:int,error:int,total:int,okPercent:float} $completeness
 * @var bool $truncated
 * @var array $statsData
 */

$this->htmlElement('body')->appendAttribute('class', 'oer-stats');
$this->headLink()->appendStylesheet($this->assetUrl('css/oer-master-view.css', 'OERManager'));
$this->headLink()->appendStylesheet($this->assetUrl('css/oer-stats.css', 'OERManager'));
$this->headScript()->appendFile($this->assetUrl('js/statsMain.js', 'OERManager'), 'module');

$dimensionLabels = [
    'etapa' => $this->translate('Etapa'),
    'materia' => $this->translate('Materia'),
    'eje' => $this->translate('Eje temático'),
    'proyecto' => $this->translate('Proyecto'),
    'licencia' => $this->translate('Licencia'),
];
?>

<?php echo $this->pageTitle($this->translate('Estadísticas')); ?>

<?php if ($truncated): ?>
<div class="messages">
    <ul class="warning">
        <li><?php echo $this->translate('El catálogo supera el tope de agregación: las estadísticas se calculan sobre los primeros REA hasta el tope.'); ?></li>
    </ul>
</div>
<?php endif; ?>

<script id="oer-stats-data" type="application/json"><?php echo json_encode($statsData, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG); ?></script>

<section class="oer-stats-section">
    <h2><?php echo $this->translate('Conteo por dimensión'); ?></h2>
    <div class="oer-stats-grid">
        <?php foreach ($dimensions as $dimension): ?>
        <div class="oer-stats-card">
            <h3><?php echo $dimensionLabels[$dimension]; ?></h3>
            <div data-oer-stats-chart="<?php echo $this->escapeHtmlAttr($dimension); ?>"></div>
            <a href="<?php echo $this->url('admin/oer-manager-stats', ['action' => 'export'], ['query' => ['dimension' => $dimension]]); ?>">
                <?php echo $this->translate('Exportar CSV'); ?>
            </a>
        </div>
        <?php endforeach; ?>
    </div>
</section>

<section class="oer-stats-section">
    <h2><?php echo $this->translate('Cruce de 2 dimensiones'); ?></h2>
    <label for="oer-stats-cross-a"><?php echo $this->translate('Dimensión A'); ?></label>
    <select id="oer-stats-cross-a">
        <?php foreach ($dimensions as $dimension): ?>
        <option value="<?php echo $this->escapeHtmlAttr($dimension); ?>" <?php echo 'materia' === $dimension ? 'selected' : ''; ?>>
            <?php echo $dimensionLabels[$dimension]; ?>
        </option>
        <?php endforeach; ?>
    </select>
    <label for="oer-stats-cross-b"><?php echo $this->translate('Dimensión B'); ?></label>
    <select id="oer-stats-cross-b">
        <?php foreach ($dimensions as $dimension): ?>
        <option value="<?php echo $this->escapeHtmlAttr($dimension); ?>" <?php echo 'etapa' === $dimension ? 'selected' : ''; ?>>
            <?php echo $dimensionLabels[$dimension]; ?>
        </option>
        <?php endforeach; ?>
    </select>
    <a id="oer-stats-cross-export" href="#"><?php echo $this->translate('Exportar CSV'); ?></a>
    <div data-oer-stats-chart="cross"></div>
</section>

<section class="oer-stats-section">
    <h2><?php echo $this->translate('Completitud'); ?></h2>
    <p>
        <?php echo sprintf(
            $this->translate('%s%% de los REA sin incidencias de integridad (%d de %d).'),
            $completeness['okPercent'],
            $completeness['ok'],
            $completeness['total']
        ); ?>
    </p>
    <div data-oer-stats-chart="completeness"></div>
    <a href="<?php echo $this->url('admin/oer-manager-stats', ['action' => 'export'], ['query' => ['type' => 'completeness']]); ?>">
        <?php echo $this->translate('Exportar CSV'); ?>
    </a>
</section>
```

- [ ] **Step 3: `make lint`**

Run: `make lint`
Expected: sin violaciones PSR-12

- [ ] **Step 4: Commit**

```bash
git add src/Controller/Admin/StatsController.php view/oer-manager/admin/stats/index.phtml
git commit -m "feat(stats): StatsController::indexAction + vista (TASK-006)"
```

---

### Task 9: `StatsController::exportAction` (CSV)

**Files:**
- Modify: `src/Controller/Admin/StatsController.php`

**Interfaces:**
- Consumes: los mismos métodos privados de relabelado del Task 8 (`relabelCounts`, `relabelCrossTable` ya devuelven datos etiquetados; para CSV se leen directamente de `$counts`/`$cross` recalculados) y `CsvExport::toCsv()` (Task 4).
- Produces: `exportAction()` — respuesta HTTP `text/csv` para `?dimension=X`, `?dimension1=X&dimension2=Y` o `?type=completeness`; `404` + `JsonModel` de error para parámetros inválidos (mismo estilo que el resto del módulo, ver `IndexController::setVisibilityAction`).

- [ ] **Step 1: Añadir la acción al controlador**

Añadir tras `indexAction()`:

```php
public function exportAction()
{
    $type = (string) $this->params()->fromQuery('type', '');
    $snapshot = $this->catalogSnapshot->fetch();
    $facts = $this->dimensionFacts->extract($snapshot['items']);

    if ('completeness' === $type) {
        $statuses = [];
        foreach ($snapshot['items'] as $item) {
            $statuses[] = $this->integrityChecker->check($item, false)->getStatus();
        }
        $result = $this->completenessAggregator->aggregate($statuses);
        $csv = $this->csvExport->toCsv(['estado', 'conteo'], [
            ['ok', $result['ok']],
            ['warning', $result['warning']],
            ['error', $result['error']],
        ]);
        return $this->csvResponse($csv, 'oer-completitud.csv');
    }

    $dimension1 = (string) $this->params()->fromQuery('dimension1', '');
    $dimension2 = (string) $this->params()->fromQuery('dimension2', '');

    if ('' !== $dimension1 && '' !== $dimension2) {
        if (!in_array($dimension1, self::DIMENSIONS, true) || !in_array($dimension2, self::DIMENSIONS, true)) {
            return $this->unknownDimensionResponse();
        }
        $titles = $this->resolveTitles($facts);
        $table = $this->relabelCrossTable(
            $this->dimensionCrosser->cross($facts, $dimension1, $dimension2),
            $dimension1,
            $dimension2,
            $titles
        );
        $rows = [];
        foreach ($table as $labelA => $bCounts) {
            foreach ($bCounts as $labelB => $count) {
                $rows[] = [$labelA, $labelB, $count];
            }
        }
        $csv = $this->csvExport->toCsv([$dimension1, $dimension2, 'conteo'], $rows);
        return $this->csvResponse($csv, "oer-cruce-{$dimension1}-{$dimension2}.csv");
    }

    $dimension = (string) $this->params()->fromQuery('dimension', '');
    if (!in_array($dimension, self::DIMENSIONS, true)) {
        return $this->unknownDimensionResponse();
    }
    $titles = $this->resolveTitles($facts);
    $rows = [];
    foreach ($this->relabelCounts($this->dimensionCounter->count($facts, $dimension), $dimension, $titles) as $row) {
        $rows[] = [$row['label'], $row['count']];
    }
    $csv = $this->csvExport->toCsv([$dimension, 'conteo'], $rows);
    return $this->csvResponse($csv, "oer-{$dimension}.csv");
}

private function csvResponse(string $csv, string $filename): \Laminas\Http\Response
{
    $response = $this->getResponse();
    $response->setContent($csv);
    $headers = $response->getHeaders();
    $headers->addHeaderLine('Content-Type', 'text/csv; charset=utf-8');
    $headers->addHeaderLine('Content-Disposition', 'attachment; filename="' . $filename . '"');
    return $response;
}

private function unknownDimensionResponse(): \Laminas\View\Model\JsonModel
{
    $this->getResponse()->setStatusCode(404);
    return new \Laminas\View\Model\JsonModel(['error' => 'unknown_dimension']);
}
```

- [ ] **Step 2: `make lint`**

Run: `make lint`
Expected: sin violaciones PSR-12

- [ ] **Step 3: Commit**

```bash
git add src/Controller/Admin/StatsController.php
git commit -m "feat(stats): exportAction — CSV de conteo/cruce/completitud (TASK-006)"
```

---

### Task 10: Gráficos JS (puros) + wiring + CSS

**Files:**
- Create: `asset/js/stats/charts.js`
- Create: `asset/js/statsMain.js`
- Create: `asset/css/oer-stats.css`
- Test: `test/js/stats/charts.test.js`

**Interfaces:**
- Consumes: el JSON embebido `#oer-stats-data` con la forma `{counts: {dimension: [{label,count}]}, cross: {"dimA/dimB": {labelA: {labelB: count}}}, completeness: {ok,warning,error,total,okPercent}}` (Task 8, `$statsData`).
- Produces: `renderBarChart(rows)`, `renderCrossTable(table)`, `renderCompletenessBar(completeness)` (puras, exportadas desde `charts.js`, testeadas en host); `initStatsPage()` (wiring DOM, sin test, mismo criterio que `ui/*.js`).

- [ ] **Step 1: Write the failing test**

```js
import { test } from 'node:test';
import assert from 'node:assert/strict';
import { renderBarChart, renderCrossTable, renderCompletenessBar } from '../../../asset/js/stats/charts.js';

test('renderBarChart pinta una barra por fila con su etiqueta y conteo', () => {
    const svg = renderBarChart([{ label: 'Matemáticas', count: 3 }, { label: 'Lengua', count: 1 }]);
    assert.match(svg, /<svg/);
    assert.match(svg, /Matemáticas/);
    assert.match(svg, />3</);
    assert.match(svg, /Lengua/);
});

test('renderBarChart con lista vacía no lanza y da un SVG vacío de barras', () => {
    const svg = renderBarChart([]);
    assert.match(svg, /<svg/);
    assert.doesNotMatch(svg, /<rect/);
});

test('renderBarChart escapa el HTML de la etiqueta', () => {
    const svg = renderBarChart([{ label: '<script>', count: 1 }]);
    assert.doesNotMatch(svg, /<script>/);
    assert.match(svg, /&lt;script&gt;/);
});

test('renderCrossTable pinta una tabla con las celdas del cruce', () => {
    const html = renderCrossTable({ Matemáticas: { '1º ESO': 2, '2º ESO': 1 } });
    assert.match(html, /<table/);
    assert.match(html, /Matemáticas/);
    assert.match(html, /1º ESO/);
    assert.match(html, />2</);
});

test('renderCrossTable con tabla vacía no lanza', () => {
    const html = renderCrossTable({});
    assert.match(html, /<table/);
});

test('renderCompletenessBar pinta los 3 segmentos', () => {
    const svg = renderCompletenessBar({ ok: 3, warning: 1, error: 0, total: 4, okPercent: 75.0 });
    assert.match(svg, /oer-stats-ok/);
    assert.match(svg, /oer-stats-warning/);
    assert.match(svg, /oer-stats-error/);
});

test('renderCompletenessBar con total 0 no lanza', () => {
    const svg = renderCompletenessBar({ ok: 0, warning: 0, error: 0, total: 0, okPercent: 0 });
    assert.match(svg, /<svg/);
});
```

- [ ] **Step 2: Run test to verify it fails**

Run: `node --test 'test/js/stats/*.test.js'`
Expected: FAIL — no se puede resolver `../../../asset/js/stats/charts.js`

- [ ] **Step 3: Write minimal implementation**

```js
/**
 * Gráficos de Estadísticas (RF-007, TASK-006). Puras: dato de entrada → SVG o
 * tabla HTML como string. Sin librería de terceros (spec §5) — el módulo no
 * declara ninguna en package.json y no toca añadir una dependencia nueva solo
 * para esto.
 */

function escapeXml(text) {
    return String(text)
        .replace(/&/g, '&amp;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;')
        .replace(/"/g, '&quot;');
}

/** Barras horizontales de conteo simple. `rows` = [{label, count}], orden ya decidido por el servidor. */
export function renderBarChart(rows) {
    const rowHeight = 28;
    const width = 480;
    const labelWidth = 160;
    const barMaxWidth = width - labelWidth - 48;
    const max = rows.reduce((m, row) => Math.max(m, row.count), 0) || 1;
    const height = (rows.length || 1) * rowHeight;

    const bars = rows.map((row, index) => {
        const y = index * rowHeight;
        const barWidth = Math.round((row.count / max) * barMaxWidth);
        return `<g transform="translate(0, ${y})">`
            + `<text x="${labelWidth - 8}" y="${Math.round(rowHeight / 2) + 4}" text-anchor="end" class="oer-stats-label">${escapeXml(row.label)}</text>`
            + `<rect x="${labelWidth}" y="4" width="${barWidth}" height="${rowHeight - 8}" class="oer-stats-bar"></rect>`
            + `<text x="${labelWidth + barWidth + 8}" y="${Math.round(rowHeight / 2) + 4}" class="oer-stats-count">${row.count}</text>`
            + '</g>';
    }).join('');

    return `<svg viewBox="0 0 ${width} ${height}" role="img" aria-label="Gráfico de barras" xmlns="http://www.w3.org/2000/svg">${bars}</svg>`;
}

/** Rejilla del cruce de 2 dimensiones. `table` = {labelA: {labelB: count}}, ya con las etiquetas resueltas por el servidor. */
export function renderCrossTable(table) {
    const keysA = Object.keys(table);
    const keysB = [...new Set(keysA.flatMap((a) => Object.keys(table[a])))];
    const max = keysA.reduce(
        (m, a) => Math.max(m, ...keysB.map((b) => table[a][b] || 0)),
        0
    ) || 1;

    const header = `<tr><th></th>${keysB.map((b) => `<th>${escapeXml(b)}</th>`).join('')}</tr>`;
    const rows = keysA.map((a) => {
        const cells = keysB.map((b) => {
            const count = table[a][b] || 0;
            const alpha = (count / max).toFixed(2);
            return `<td style="background-color: rgba(29, 122, 95, ${alpha})">${count}</td>`;
        }).join('');
        return `<tr><th>${escapeXml(a)}</th>${cells}</tr>`;
    }).join('');

    return `<table class="oer-stats-cross">${header}${rows}</table>`;
}

/** Barra apilada ok/warning/error (mismos tokens de color que ADR-0014). */
export function renderCompletenessBar(completeness) {
    const width = 480;
    const height = 32;
    const total = completeness.total || 1;
    const segments = [
        { count: completeness.ok, label: 'ok', className: 'oer-stats-ok' },
        { count: completeness.warning, label: 'warning', className: 'oer-stats-warning' },
        { count: completeness.error, label: 'error', className: 'oer-stats-error' }
    ];

    let x = 0;
    const rects = segments.map((segment) => {
        const segWidth = Math.round((segment.count / total) * width);
        const rect = `<rect x="${x}" y="0" width="${segWidth}" height="${height}" class="${segment.className}"><title>${segment.label}: ${segment.count}</title></rect>`;
        x += segWidth;
        return rect;
    }).join('');

    return `<svg viewBox="0 0 ${width} ${height}" role="img" aria-label="Completitud del catálogo" xmlns="http://www.w3.org/2000/svg">${rects}</svg>`;
}
```

- [ ] **Step 4: Run test to verify it passes**

Run: `node --test 'test/js/stats/*.test.js'`
Expected: PASS — 7 tests

- [ ] **Step 5: Wiring (sin test, mismo criterio que `asset/js/ui/*.js`)**

```js
// asset/js/statsMain.js
import { renderBarChart, renderCrossTable, renderCompletenessBar } from './stats/charts.js';

function readStatsData() {
    const el = document.getElementById('oer-stats-data');
    return el ? JSON.parse(el.textContent) : null;
}

function paintCounts(data) {
    for (const dimension of Object.keys(data.counts)) {
        const target = document.querySelector(`[data-oer-stats-chart="${dimension}"]`);
        if (target) {
            target.innerHTML = renderBarChart(data.counts[dimension]);
        }
    }
}

function paintCompleteness(data) {
    const target = document.querySelector('[data-oer-stats-chart="completeness"]');
    if (target) {
        target.innerHTML = renderCompletenessBar(data.completeness);
    }
}

function wireCross(data) {
    const selectA = document.getElementById('oer-stats-cross-a');
    const selectB = document.getElementById('oer-stats-cross-b');
    const target = document.querySelector('[data-oer-stats-chart="cross"]');
    const exportLink = document.getElementById('oer-stats-cross-export');
    if (!selectA || !selectB || !target) {
        return;
    }

    function paint() {
        const key = `${selectA.value}/${selectB.value}`;
        const table = data.cross[key];
        if (table) {
            target.innerHTML = renderCrossTable(table);
        }
        if (exportLink) {
            const url = new URL(exportLink.href, window.location.href);
            url.searchParams.set('dimension1', selectA.value);
            url.searchParams.set('dimension2', selectB.value);
            exportLink.href = url.toString();
        }
    }

    selectA.addEventListener('change', paint);
    selectB.addEventListener('change', paint);
    paint();
}

export function initStatsPage() {
    const data = readStatsData();
    if (!data) {
        return;
    }
    paintCounts(data);
    paintCompleteness(data);
    wireCross(data);
}

document.addEventListener('DOMContentLoaded', initStatsPage);
```

- [ ] **Step 6: CSS**

```css
/* asset/css/oer-stats.css — reusa los tokens de :root definidos en oer-master-view.css */

.oer-stats-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(320px, 1fr));
    gap: 1.5rem;
}

.oer-stats-card {
    border: 1px solid var(--oer-rule);
    border-radius: 4px;
    padding: 1rem;
}

.oer-stats-section {
    margin-bottom: 2rem;
}

.oer-stats-label,
.oer-stats-count {
    font-size: 0.8rem;
    fill: var(--oer-ink);
}

.oer-stats-bar {
    fill: var(--oer-accent);
}

.oer-stats-ok {
    fill: var(--oer-ok);
}

.oer-stats-warning {
    fill: var(--oer-warn);
}

.oer-stats-error {
    fill: var(--oer-bad);
}

.oer-stats-cross {
    border-collapse: collapse;
}

.oer-stats-cross th,
.oer-stats-cross td {
    border: 1px solid var(--oer-rule);
    padding: 0.25rem 0.5rem;
    text-align: center;
}
```

- [ ] **Step 7: Run full JS suite**

Run: `make test-js`
Expected: PASS — todos los tests existentes + los 7 nuevos de `stats/charts.test.js`

- [ ] **Step 8: Commit**

```bash
git add asset/js/stats/charts.js asset/js/statsMain.js asset/css/oer-stats.css test/js/stats/charts.test.js
git commit -m "feat(stats): gráficos SVG puros + wiring de la página (TASK-006)"
```

---

### Task 11: Verificación en contenedor

**Files:**
- Create: `test/container/stats-check.php`

**Interfaces:**
- Consumes: los servicios reales del contenedor (`Service\Stats\CatalogSnapshot`, `Service\Stats\DimensionFacts`, `Service\IntegrityChecker`) vía `Omeka\Mvc\Application`.
- Produces: nada nuevo — arnés de solo lectura que confirma que la cadena completa (`CatalogSnapshot` → `DimensionFacts` → agregadores) funciona contra el catálogo real, mismo patrón que `test/container/materia-multi-curso-check.php`.

- [ ] **Step 1: Escribir el arnés**

```php
<?php

/**
 * Arnés de verificación de TASK-006 (estadísticas). CatalogSnapshot y
 * DimensionFacts dependen del core (Omeka\Api\Manager / ItemRepresentation):
 * no se pueden instanciar en un test de host (ver «Limitación conocida del
 * arnés», project-memory.md). Los agregadores puros (DimensionCounter,
 * DimensionCrosser, CompletenessAggregator, CsvExport) ya tienen TDD real en
 * host — este arnés cubre solo la extracción real desde el catálogo.
 *
 * SOLO LECTURA: ninguna llamada aquí escribe en el catálogo.
 *
 * Uso, desde el contenedor:
 *   php /var/www/html/modules/OERManager/test/container/stats-check.php
 */

require '/var/www/html/bootstrap.php';

use OERManager\Service\Stats\CatalogSnapshot;
use OERManager\Service\Stats\CompletenessAggregator;
use OERManager\Service\Stats\DimensionCounter;
use OERManager\Service\Stats\DimensionCrosser;
use OERManager\Service\Stats\DimensionFacts;
use OERManager\Service\IntegrityChecker;

$application = Omeka\Mvc\Application::init(require '/var/www/html/application/config/application.config.php');
$services = $application->getServiceManager();

/** @var CatalogSnapshot $snapshot */
$snapshot = $services->get(CatalogSnapshot::class);
$facts = $services->get(DimensionFacts::class);
$counter = $services->get(DimensionCounter::class);
$crosser = $services->get(DimensionCrosser::class);
$completeness = $services->get(CompletenessAggregator::class);
/** @var IntegrityChecker $integrityChecker */
$integrityChecker = $services->get(IntegrityChecker::class);

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

$data = $snapshot->fetch();
check('fetch() trae al menos 1 item', count($data['items']) > 0, 'catálogo vacío');
check('fetch() no está truncado con el catálogo real', false === $data['truncated']);

$extracted = $facts->extract($data['items']);
check('extract() devuelve una fila por item', count($extracted) === count($data['items']));

$materiaCounts = $counter->count($extracted, 'materia');
check('DimensionCounter da conteos para materia', array_sum($materiaCounts) > 0);

$cross = $crosser->cross($extracted, 'materia', 'etapa');
check('DimensionCrosser da al menos una combinación materia×etapa', count($cross) > 0);

$statuses = [];
foreach ($data['items'] as $item) {
    $statuses[] = $integrityChecker->check($item, false)->getStatus();
}
$result = $completeness->aggregate($statuses);
check(
    'CompletenessAggregator: ok+warning+error = total',
    $result['ok'] + $result['warning'] + $result['error'] === $result['total']
);
echo "  INFO completitud: {$result['okPercent']}% ok ({$result['ok']}/{$result['total']})\n";

$distinctIds = $facts->distinctResourceIds($extracted);
check('distinctResourceIds() no repite ids', count($distinctIds) === count(array_unique($distinctIds)));

echo "\n$passed OK, $failed FAIL\n";
exit($failed > 0 ? 1 : 0);
```

- [ ] **Step 2: Ejecutar en el contenedor real**

Run: `docker exec omeka-s-moduletemplate-omekas-1 php /var/www/html/modules/OERManager/test/container/stats-check.php`
Expected: todas las líneas `OK`, `0 FAIL`

Si el catálogo real no coincide con alguna expectativa (p. ej. 0 items con materia), ajustar la comprobación al dato real observado antes de continuar — no forzar el resultado.

- [ ] **Step 3: Verificar la página en el admin real (navegador)**

Manual, por el propietario o en sesión con credenciales: entrar a `admin/oer-manager-stats`, comprobar que los 5 gráficos de conteo pintan, que cambiar los selectores de cruce repinta la tabla al vuelo (sin recargar), que el CSV de cada bloque descarga con las mismas cifras que el gráfico, y que la entrada "Estadísticas" solo es visible para `editor`+. Documentar como TASK-030 el patrón ya usado en TASK-032/033/034 si no se puede verificar en esta sesión (sin credenciales de admin real).

- [ ] **Step 4: Commit**

```bash
git add test/container/stats-check.php
git commit -m "test(stats): arnés de verificación en contenedor (TASK-006)"
```

---

### Task 12: Cierre de gobierno — backlog, trazabilidad, memoria

**Files:**
- Modify: `docs/backlog.md`
- Modify: `docs/traceability.md`
- Modify: `docs/project-memory.md`

**Interfaces:** ninguna — solo documentación.

- [ ] **Step 1: Actualizar `docs/backlog.md`**

Cambiar la fila de `TASK-006` de `pendiente` a `hecha`, con un resumen de lo entregado (StatsController, agregadores puros en `Service\Stats\`, gráficos SVG sin librería, export CSV, verificado en contenedor con `stats-check.php`; residuo de verificación en navegador real si el Task 11 Step 3 no pudo completarse en esta sesión).

- [ ] **Step 2: Actualizar `docs/traceability.md`**

En la fila de RF-007 (línea con "Estadísticas visuales + export"), enlazar TASK-006 con el criterio de aceptación cumplido (conteo simple, cruce genérico de las 5 dimensiones, % completitud, export CSV) y la nota de qué quedó fuera (PDF, caché, drill-down — spec §8).

- [ ] **Step 3: Actualizar `docs/project-memory.md`**

Añadir una entrada breve con la lección de esta tarea: la separación agregador-puro / extractor-de-hechos (frontera puerto/adaptador) permitió TDD real en host para toda la lógica de negocio de Stats pese a que el dato de origen (`ItemRepresentation`) solo existe en el contenedor — mismo patrón que `CurationEvent` (TASK-007) y los agregadores de TASK-010.

- [ ] **Step 4: `make lint` y `make test` completos**

Run: `make lint && make test && make test-js`
Expected: todo en verde

- [ ] **Step 5: Commit**

```bash
git add docs/backlog.md docs/traceability.md docs/project-memory.md
git commit -m "docs: cierre de TASK-006 (estadísticas) en backlog/traceability/project-memory"
```

---

## Self-Review

**1. Cobertura del spec:** §1 (dimensiones/catálogo de gráficos) → Tasks 1-3, 8. §2 (ACL, cruce genérico, alcance) → Task 7 (ACL), Task 8 (10 pares), Task 6 (`CatalogSnapshot` sin acoplar a `MasterViewQuery`). §3 (rutas/nav/ACL) → Task 7. §4 (agregación, cardinalidad múltiple, resolución batch de títulos, completitud) → Tasks 1, 2, 5, 6, 8. §5 (composición de página, renderizado sin librería) → Tasks 8, 10. §6 (export CSV) → Tasks 4, 9. §7 (testing) → Tasks 1-4 (host), 5-6 (sin host, documentado), 10 (JS), 11 (contenedor). §8 (fuera de alcance) → no genera tareas, correcto.

**2. Placeholders:** ninguno — cada paso trae código completo o el comando exacto a ejecutar.

**3. Consistencia de tipos:** `array<int, array<string, int[]|string|null>>` para "hechos" se usa igual en `DimensionCounter`, `DimensionCrosser` y `DimensionFacts::extract()`. `DimensionFacts::DIMENSION_TERMS` (Task 5) es la única fuente de las 4 dimensiones resource:item; `StatsController::DIMENSIONS` (Task 8) añade `'licencia'` y es la lista que consumen la vista, `exportAction` y el JS — no hay una tercera lista divergente. La forma del JSON `statsData` (Task 8: `counts`/`cross`/`completeness`) coincide exactamente con lo que `statsMain.js` (Task 10) lee (`data.counts`, `data.cross[key]`, `data.completeness`). La clave del cruce (`"dimA/dimB"`) se genera igual en el controlador (Task 8) y se consume igual en el JS (Task 10, `` `${selectA.value}/${selectB.value}` ``).
