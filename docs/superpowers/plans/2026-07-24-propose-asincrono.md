# Propose asíncrono como Omeka Job — Plan de implementación (TASK-020)

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Ejecutar «Proponer con IA» como Omeka Job en 2º plano con polling, progreso por fases, cancelación y recuperación, para que el propose no agote el timeout del proxy (504, NFR-010).

**Architecture:** El controlador despacha un `AiProposeJob` (estrategia PhpCli) y devuelve `{jobId}` al instante. El Job corre el propose existente vía un `ProposeRunner`, reportando fase a fase e informando el resultado en un fichero JSON privado (`ProposalStore`). El navegador sondea un endpoint de estado que cruza el estado nativo del Job con el fichero. El clasificador y la calidad de la propuesta NO cambian; el Job nunca escribe en el catálogo.

**Tech Stack:** PHP 8.4, Omeka-S 4.2 (`Omeka\Job\AbstractJob`, `Dispatcher`, PhpCli strategy), Laminas MVC/JsonModel, PHPUnit 11.5, jQuery (admin JS).

## Global Constraints

- **PHP 8.4**, sin sintaxis de 8.5. Omeka-S `^4.2.0`.
- **Extender el core, NUNCA parchearlo**: solo `attachListeners`/`module.config.php`/servicios. Hook bloquea escrituras fuera del repo.
- **Sin tablas Doctrine propias** (NFR-002): el canal de resultado es un fichero privado, no una tabla.
- Lint **PSR-12** (`make lint`); autofix `make fix`. Stop hook exige `make lint` + `make test` en verde.
- Namespace/carpeta **`OERManager`**; vistas en `view/oer-manager/`.
- **El Job solo PROPONE, nunca escribe** en el catálogo (ADR-0007). Escritura = «Aplicar» manual, sin cambios.
- **Sin secretos** en el fichero de resultado ni en logs (nunca la clave API).
- Fichero de resultado en **directorio privado** (`sys_get_temp_dir()`), NUNCA en `files/` público.
- TDD real en host donde el núcleo es puro; glue que toca el core se **verifica en contenedor** con `test/container/`.
- Comandos de test: host `vendor/bin/phpunit -c test/phpunit.xml`; contenedor `docker exec omeka-s-moduletemplate-omekas-1 sh -c '...'`.

---

## Task 1: Des-riesgar PhpCli en contenedor (spike)

**Por qué primero:** todo el diseño asume que un Job PhpCli arranca en 2º plano en este contenedor (`phpcli_path` está en auto-detección). Si no arranca, hay que resolver eso antes de construir nada. Es una verificación, no código de producción.

**Files:**
- Create (temporal, se borra al acabar la tarea): `test/container/spike-job.php`

**Interfaces:**
- Produces: confirmación empírica de que `Omeka\Job\Dispatcher::dispatch()` con la estrategia por defecto ejecuta un Job en 2º plano y que su `perform()` corre.

- [ ] **Step 1: Escribir un Job trivial de prueba y un lanzador**

Create `test/container/spike-job.php`:

```php
<?php
// Spike de des-riesgo de PhpCli (TASK-020, Task 1). SOLO LECTURA / diagnóstico.
// Despacha un Job trivial que escribe un fichero-testigo, y comprueba que el
// proceso web vuelve al instante y el Job corre en 2º plano.
chdir('/var/www/html');
require 'bootstrap.php';
$app = \Omeka\Mvc\Application::init(require 'application/config/application.config.php');
$services = $app->getServiceManager();

$witness = sys_get_temp_dir() . '/oer-spike-witness.txt';
@unlink($witness);

// Job anónimo definido en un fichero cargado por el runner: en su lugar usamos
// un job del core que sabemos que existe para no registrar una clase. Aquí
// medimos solo el arranque en 2º plano con un job real del módulo en Task 7.
$dispatcher = $services->get('Omeka\Job\Dispatcher');
$t0 = microtime(true);
$job = $dispatcher->dispatch(\OERManager\Job\SpikeJob::class, ['witness' => $witness]);
$elapsed = round((microtime(true) - $t0) * 1000);
printf("dispatch devolvió jobId=%d en %d ms (debe ser <1000)\n", $job->getId(), $elapsed);
echo "esperando al witness (hasta 20s)...\n";
for ($i = 0; $i < 40; $i++) {
    if (is_file($witness)) {
        printf("WITNESS escrito por el Job en 2º plano: %s\n", trim(file_get_contents($witness)));
        exit(0);
    }
    usleep(500000);
}
fwrite(STDERR, "el Job NO escribió el witness: PhpCli no arrancó (revisar phpcli_path)\n");
exit(1);
```

Create `src/Job/SpikeJob.php` (temporal, se borra en el commit final de esta tarea si el spike va bien; su único fin es probar el arranque):

```php
<?php

namespace OERManager\Job;

use Omeka\Job\AbstractJob;

/** Spike de des-riesgo de PhpCli (TASK-020). Se elimina tras verificar. */
class SpikeJob extends AbstractJob
{
    public function perform(): void
    {
        $witness = (string) $this->getArg('witness', '');
        if ('' !== $witness) {
            file_put_contents($witness, 'jobId=' . $this->job->getId() . ' ok ' . date('c'));
        }
    }
}
```

- [ ] **Step 2: Ejecutar el spike en el contenedor**

Run:
```bash
docker exec omeka-s-moduletemplate-omekas-1 sh -c 'cd /var/www/html && php modules/OERManager/test/container/spike-job.php'
```
Expected: `dispatch devolvió jobId=… en <1000 ms` y `WITNESS escrito por el Job en 2º plano: …`.

- [ ] **Step 3: Interpretar el resultado**

- Si el witness aparece → PhpCli arranca; se puede construir el resto. Borra `src/Job/SpikeJob.php` y `test/container/spike-job.php`.
- Si NO aparece → PhpCli no arranca por auto-detección. Antes de seguir: fijar `phpcli_path` en la config de Omeka del contenedor (infra del propietario, fuera del módulo) o documentar el bloqueo. **No continuar el plan hasta resolverlo.**

- [ ] **Step 4: Commit (limpieza del spike)**

```bash
git rm src/Job/SpikeJob.php test/container/spike-job.php
git commit -m "chore(ai): spike PhpCli verificado, retirado (TASK-020 Task 1)"
```
(Si prefieres no dejar rastro del spike en git, ejecútalo sin commitearlo y borra los ficheros antes del primer commit del plan.)

---

## Task 2: `ProposalStore` — canal de estado por fichero (host TDD)

**Files:**
- Create: `src/Service/Ai/ProposalStore.php`
- Test: `test/Service/Ai/ProposalStoreTest.php`

**Interfaces:**
- Produces:
  - `new ProposalStore(string $baseDir)`
  - `write(int $jobId, array $state): void` — escritura atómica (temp + rename).
  - `read(int $jobId): ?array` — `null` si no existe; idempotente (no borra).
  - `sweepOld(int $ttlSeconds): void` — borra ficheros con mtime más viejo que el TTL.

- [ ] **Step 1: Escribir el test que falla**

Create `test/Service/Ai/ProposalStoreTest.php`:

```php
<?php

declare(strict_types=1);

namespace OERManager\Test\Service\Ai;

use OERManager\Service\Ai\ProposalStore;
use PHPUnit\Framework\TestCase;

final class ProposalStoreTest extends TestCase
{
    private string $dir = '';

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/oer_store_' . bin2hex(random_bytes(6));
    }

    protected function tearDown(): void
    {
        foreach (glob($this->dir . '/*') ?: [] as $f) {
            @unlink($f);
        }
        @rmdir($this->dir);
    }

    public function testWriteThenReadRoundTrips(): void
    {
        $store = new ProposalStore($this->dir);
        $store->write(42, ['status' => 'completed', 'payload' => ['alignment' => ['schema:about' => [7]]]]);

        $this->assertSame(
            ['status' => 'completed', 'payload' => ['alignment' => ['schema:about' => [7]]]],
            $store->read(42)
        );
    }

    public function testReadMissingReturnsNull(): void
    {
        $this->assertNull((new ProposalStore($this->dir))->read(999));
    }

    public function testReadIsIdempotentDoesNotDelete(): void
    {
        $store = new ProposalStore($this->dir);
        $store->write(1, ['status' => 'in_progress', 'step' => 'Destilando', 'done' => 2, 'total' => 5]);
        $store->read(1);
        $this->assertNotNull($store->read(1), 'read no debe borrar: la recuperación tras abandono depende de ello');
    }

    public function testWriteOverwritesPreviousState(): void
    {
        $store = new ProposalStore($this->dir);
        $store->write(1, ['status' => 'in_progress', 'done' => 1, 'total' => 5]);
        $store->write(1, ['status' => 'completed', 'payload' => []]);
        $this->assertSame('completed', $store->read(1)['status']);
    }

    public function testSweepOldDeletesOnlyExpired(): void
    {
        $store = new ProposalStore($this->dir);
        $store->write(1, ['status' => 'completed']);
        // Envejecer el fichero del job 1 a 2 horas atrás.
        touch($this->dir . '/1.json', time() - 7200);
        $store->write(2, ['status' => 'completed']);

        $store->sweepOld(3600); // TTL 1 hora

        $this->assertNull($store->read(1), 'el viejo se barre');
        $this->assertNotNull($store->read(2), 'el reciente se conserva');
    }
}
```

- [ ] **Step 2: Ejecutar para verificar que falla**

Run: `vendor/bin/phpunit -c test/phpunit.xml --filter ProposalStore`
Expected: FAIL — `Class "OERManager\Service\Ai\ProposalStore" not found`.

- [ ] **Step 3: Implementación mínima**

Create `src/Service/Ai/ProposalStore.php`:

```php
<?php

namespace OERManager\Service\Ai;

/**
 * Canal de estado/resultado entre el AiProposeJob (proceso CLI en 2º plano) y el
 * polling del navegador (proceso web): dos procesos PHP distintos que no comparten
 * memoria (TASK-020). Fichero JSON por jobId en un directorio PRIVADO (nunca en
 * files/ público). Escritura atómica (temp + rename) para que el lector nunca vea
 * un fichero a medias. `read` no borra: la recuperación tras abandono lo necesita;
 * la limpieza es por TTL (`sweepOld`).
 */
final class ProposalStore
{
    public function __construct(private string $baseDir)
    {
    }

    /** @param array<string,mixed> $state */
    public function write(int $jobId, array $state): void
    {
        if (!is_dir($this->baseDir)) {
            @mkdir($this->baseDir, 0700, true);
        }
        $json = json_encode($state, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $tmp = $this->baseDir . '/.' . $jobId . '.' . bin2hex(random_bytes(4)) . '.tmp';
        file_put_contents($tmp, (string) $json);
        rename($tmp, $this->path($jobId)); // atómico en el mismo sistema de ficheros
    }

    /** @return array<string,mixed>|null */
    public function read(int $jobId): ?array
    {
        $path = $this->path($jobId);
        if (!is_file($path)) {
            return null;
        }
        $raw = file_get_contents($path);
        if (false === $raw || '' === $raw) {
            return null;
        }
        $data = json_decode($raw, true);
        return is_array($data) ? $data : null;
    }

    public function sweepOld(int $ttlSeconds): void
    {
        $cutoff = time() - $ttlSeconds;
        foreach (glob($this->baseDir . '/*.json') ?: [] as $file) {
            if (@filemtime($file) < $cutoff) {
                @unlink($file);
            }
        }
    }

    private function path(int $jobId): string
    {
        return $this->baseDir . '/' . $jobId . '.json';
    }
}
```

- [ ] **Step 4: Ejecutar para verificar que pasa**

Run: `vendor/bin/phpunit -c test/phpunit.xml --filter ProposalStore`
Expected: PASS (5 tests).

- [ ] **Step 5: Commit**

```bash
git add src/Service/Ai/ProposalStore.php test/Service/Ai/ProposalStoreTest.php
git commit -m "feat(ai): ProposalStore, canal de estado por fichero privado (TASK-020)"
```

---

## Task 3: `ProgressReporter` (interfaz) + `NullProgressReporter` + `JobStoppedException`

**Files:**
- Create: `src/Service/Ai/ProgressReporter.php`
- Create: `src/Service/Ai/NullProgressReporter.php`
- Create: `src/Service/Ai/JobStoppedException.php`
- Test: `test/Service/Ai/NullProgressReporterTest.php`

**Interfaces:**
- Produces:
  - `interface ProgressReporter { public function report(string $step, int $done, int $total): void; public function shouldStop(): bool; }`
  - `NullProgressReporter` — `report` no-op, `shouldStop()` siempre `false`.
  - `class JobStoppedException extends \RuntimeException`.

- [ ] **Step 1: Escribir el test que falla**

Create `test/Service/Ai/NullProgressReporterTest.php`:

```php
<?php

declare(strict_types=1);

namespace OERManager\Test\Service\Ai;

use OERManager\Service\Ai\NullProgressReporter;
use PHPUnit\Framework\TestCase;

final class NullProgressReporterTest extends TestCase
{
    public function testNeverStopsAndReportIsNoop(): void
    {
        $reporter = new NullProgressReporter();
        $reporter->report('cualquier fase', 1, 5); // no debe lanzar
        $this->assertFalse($reporter->shouldStop());
    }
}
```

- [ ] **Step 2: Ejecutar para verificar que falla**

Run: `vendor/bin/phpunit -c test/phpunit.xml --filter NullProgressReporter`
Expected: FAIL — clase no encontrada.

- [ ] **Step 3: Implementación mínima**

Create `src/Service/Ai/ProgressReporter.php`:

```php
<?php

namespace OERManager\Service\Ai;

/**
 * Cómo la tubería larga del propose (AiCataloguer) informa de la fase en curso y
 * consulta si debe pararse (TASK-020). Opcional: el default es el null object, así
 * el propose síncrono y los tests no cambian de comportamiento.
 */
interface ProgressReporter
{
    /** Informa la fase actual (etiqueta legible) y el avance aproximado. */
    public function report(string $step, int $done, int $total): void;

    /** ¿El curador pidió cancelar? Se comprueba en los límites de fase. */
    public function shouldStop(): bool;
}
```

Create `src/Service/Ai/NullProgressReporter.php`:

```php
<?php

namespace OERManager\Service\Ai;

/** Reporter no-op: default cuando no hay Job (propose síncrono, tests). */
final class NullProgressReporter implements ProgressReporter
{
    public function report(string $step, int $done, int $total): void
    {
    }

    public function shouldStop(): bool
    {
        return false;
    }
}
```

Create `src/Service/Ai/JobStoppedException.php`:

```php
<?php

namespace OERManager\Service\Ai;

/**
 * La lanza AiCataloguer cuando shouldStop() es cierto en un límite de fase; el
 * AiProposeJob la captura y marca el estado `stopped` (TASK-020). No se produce
 * ninguna propuesta parcial.
 */
final class JobStoppedException extends \RuntimeException
{
}
```

- [ ] **Step 4: Ejecutar para verificar que pasa**

Run: `vendor/bin/phpunit -c test/phpunit.xml --filter NullProgressReporter`
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add src/Service/Ai/ProgressReporter.php src/Service/Ai/NullProgressReporter.php src/Service/Ai/JobStoppedException.php test/Service/Ai/NullProgressReporterTest.php
git commit -m "feat(ai): ProgressReporter + NullProgressReporter + JobStoppedException (TASK-020)"
```

---

## Task 4: Cableado de progreso/parada en `AiCataloguer::propose` (host TDD)

**Files:**
- Modify: `src/Service/Ai/AiCataloguer.php` (añadir `?ProgressReporter $progress` y reportes/checks por fase)
- Test: `test/Service/Ai/AiCataloguerTest.php` (añadir casos); reusa `FakeLlmClient`, `FakeClassifier`

**Interfaces:**
- Consumes: `ProgressReporter`, `JobStoppedException` (Task 3).
- Produces: `AiCataloguer::propose(string $metadataText, array $files, array $images = [], string $largePdfDecision = 'ask', ?ProgressReporter $progress = null): array`. Fases reportadas en orden: `'Extrayendo contenido'`, `'Analizando imágenes y PDF'` (solo si hay candidatos de visión), `'Destilando ficha'`, `'Clasificación curricular'`, `'Ejes temáticos'`. Lanza `JobStoppedException` si `shouldStop()` en un límite de fase.

- [ ] **Step 1: Escribir el test que falla — fases reportadas**

Add to `test/Service/Ai/AiCataloguerTest.php` (dentro de la clase). Primero un fake reporter reutilizable al final del fichero, antes del cierre de clase:

```php
    public function testReportsPhasesToProgressReporter(): void
    {
        $reporter = new RecordingProgressReporter();
        $cataloguer = new AiCataloguer(
            new ContentExtractor(),
            $this->vision(),
            $this->distiller('Ficha'),
            new FakeClassifier(['schema:about' => [20]]),
            new FakeClassifier([])
        );
        $file = $this->dir . '/nota.txt';
        file_put_contents($file, 'Contenido sobre álgebra.');

        $cataloguer->propose('Meta', [['path' => $file, 'name' => 'nota.txt']], [], 'ask', $reporter);

        $this->assertContains('Extrayendo contenido', $reporter->steps);
        $this->assertContains('Destilando ficha', $reporter->steps);
        $this->assertContains('Clasificación curricular', $reporter->steps);
        $this->assertContains('Ejes temáticos', $reporter->steps);
    }

    public function testStopBetweenPhasesThrowsAndProducesNoProposal(): void
    {
        // Parar tras el primer report(): el propose debe abortar sin clasificar.
        $reporter = new RecordingProgressReporter(stopAfter: 1);
        $curricular = new FakeClassifier(['schema:about' => [20]]);
        $cataloguer = new AiCataloguer(
            new ContentExtractor(),
            $this->vision(),
            $this->distiller('Ficha'),
            $curricular,
            new FakeClassifier([])
        );
        $file = $this->dir . '/nota.txt';
        file_put_contents($file, 'Contenido.');

        $this->expectException(\OERManager\Service\Ai\JobStoppedException::class);
        $cataloguer->propose('Meta', [['path' => $file, 'name' => 'nota.txt']], [], 'ask', $reporter);
    }
```

Add the recording reporter as a separate test-support class at the end of the file (después de la clase `AiCataloguerTest`):

```php
final class RecordingProgressReporter implements \OERManager\Service\Ai\ProgressReporter
{
    /** @var string[] */
    public array $steps = [];

    public function __construct(private int $stopAfter = PHP_INT_MAX)
    {
    }

    public function report(string $step, int $done, int $total): void
    {
        $this->steps[] = $step;
    }

    public function shouldStop(): bool
    {
        return count($this->steps) >= $this->stopAfter;
    }
}
```

- [ ] **Step 2: Ejecutar para verificar que falla**

Run: `vendor/bin/phpunit -c test/phpunit.xml --filter 'ReportsPhases|StopBetweenPhases'`
Expected: FAIL — `propose()` no acepta el 5º argumento / no reporta fases.

- [ ] **Step 3: Implementación — añadir el parámetro y los reportes/checks**

En `src/Service/Ai/AiCataloguer.php`, añadir el `use` y modificar `propose`.

Añadir junto a los otros `use`:
```php
use OERManager\Service\Ai\JobStoppedException;
use OERManager\Service\Ai\NullProgressReporter;
use OERManager\Service\Ai\ProgressReporter;
```

Cambiar la firma y el cuerpo. La firma:
```php
    public function propose(
        string $metadataText,
        array $files,
        array $images = [],
        string $largePdfDecision = 'ask',
        ?ProgressReporter $progress = null
    ): array {
        $progress ??= new NullProgressReporter();
```

Justo después de `$this->vision->clearTrace();` y antes de `$media = $this->extractor->extract(...)`:
```php
        $total = 5;
        $progress->report('Extrayendo contenido', 1, $total);
```

Tras calcular `$oversize` y ANTES del bloque de `needs_confirmation`, no hace falta check (el corte de confirmación es instantáneo). Antes de la visión (`$this->vision->describe(...)`), insertar el check y el report solo si hay candidatos:
```php
        $this->stopIfRequested($progress);
        if ([] !== $images || [] !== $rescuable) {
            $progress->report('Analizando imágenes y PDF', 2, $total);
        }
```
(Nota: `$rescuable` ya se calcula en la línea siguiente hoy; reordenar para calcular `$rescuable` antes de este report. Es decir, mover `$rescuable = $this->rescuablePdfs(...)` por encima del report.)

Antes de la destilación (`$ficha = $this->distiller->distill($context);`):
```php
        $this->stopIfRequested($progress);
        $progress->report('Destilando ficha', 3, $total);
```

Sustituir la fusión de clasificadores por dos fases separadas con checks:
```php
        $alignment = [];
        if (!$context->isEmpty()) {
            $this->stopIfRequested($progress);
            $progress->report('Clasificación curricular', 4, $total);
            $curricular = $this->curricular->classify($context);

            $this->stopIfRequested($progress);
            $progress->report('Ejes temáticos', 5, $total);
            $tags = $this->tags->classify($context);

            $alignment = $curricular + $tags;
        }
```

Añadir el helper privado al final de la clase:
```php
    private function stopIfRequested(ProgressReporter $progress): void
    {
        if ($progress->shouldStop()) {
            throw new JobStoppedException();
        }
    }
```

- [ ] **Step 4: Ejecutar para verificar que pasa (y no hay regresión)**

Run: `vendor/bin/phpunit -c test/phpunit.xml`
Expected: PASS — los 177 previos + los 2 nuevos (179). El null-object garantiza que los tests que llaman a `propose` sin reporter siguen igual.

- [ ] **Step 5: Lint y commit**

Run: `make lint` (Expected: exit 0).
```bash
git add src/Service/Ai/AiCataloguer.php test/Service/Ai/AiCataloguerTest.php
git commit -m "feat(ai): AiCataloguer reporta fases y aborta al cancelar (TASK-020)"
```

---

## Task 5: `ProposeRunner` — itemId+decisión → payload del navegador (glue)

**Files:**
- Create: `src/Service/Ai/ProposeRunner.php`
- Modify: `config/module.config.php` (factoría del runner)

**Interfaces:**
- Consumes: `Omeka\ApiManager`, `AiCataloguer` (Task 4), `MediaSourceInterface`, `ProgressReporter`.
- Produces: `ProposeRunner::run(int $itemId, string $largePdfDecision, ?ProgressReporter $progress = null): array` — payload idéntico al de `aiProposeAction` hoy: `{alignment: enriquecido, justifications, content, debug}` o `{needs_confirmation, content}`.

- [ ] **Step 1: Crear el servicio**

Create `src/Service/Ai/ProposeRunner.php`:

```php
<?php

namespace OERManager\Service\Ai;

use OERManager\Service\Content\MediaSourceInterface;
use Omeka\Api\Manager as ApiManager;
use Omeka\Api\Representation\ItemRepresentation;

/**
 * Ensambla el payload de propuesta para el navegador a partir de un itemId: lee el
 * item, construye el texto de metadatos, obtiene medios, llama al propose y
 * enriquece el alineamiento a chips {id,title}. Centraliza lo que antes estaba en
 * IndexController para que el AiProposeJob (2º plano) lo reutilice (TASK-020). No
 * escribe nada en el catálogo.
 */
final class ProposeRunner
{
    public function __construct(
        private ApiManager $api,
        private AiCataloguer $cataloguer,
        private MediaSourceInterface $mediaSource
    ) {
    }

    /** @return array<string,mixed> */
    public function run(int $itemId, string $largePdfDecision, ?ProgressReporter $progress = null): array
    {
        $item = $this->api->read('items', $itemId)->getContent();
        $proposal = $this->cataloguer->propose(
            $this->itemMetadataText($item),
            $this->mediaSource->filesFor($itemId),
            $this->mediaSource->imagesFor($itemId),
            $largePdfDecision,
            $progress
        );

        if (isset($proposal['needs_confirmation'])) {
            return [
                'needs_confirmation' => $proposal['needs_confirmation'],
                'content' => $proposal['content'],
            ];
        }

        return [
            'alignment' => $this->enrichLabels($proposal['alignment']),
            'justifications' => $proposal['justifications'] ?? [],
            'content' => $proposal['content'],
            'debug' => $proposal['debug'],
        ];
    }

    private function itemMetadataText(ItemRepresentation $item): string
    {
        $parts = [];
        $title = trim((string) $item->displayTitle(''));
        if ('' !== $title) {
            $parts[] = $title;
        }
        foreach ($item->values() as $info) {
            foreach ($info['values'] as $value) {
                if ('literal' !== $value->type()) {
                    continue;
                }
                $text = trim((string) $value->value());
                if ('' !== $text) {
                    $parts[] = $text;
                }
            }
        }
        return implode("\n", array_values(array_unique($parts)));
    }

    /**
     * @param array<string,int[]> $alignment
     * @return array<string,array<int,array{id:int,title:string}>>
     */
    private function enrichLabels(array $alignment): array
    {
        $out = [];
        foreach ($alignment as $term => $ids) {
            $list = [];
            foreach ($ids as $id) {
                try {
                    $title = (string) $this->api->read('items', (int) $id)->getContent()->displayTitle();
                } catch (\Exception $e) {
                    continue;
                }
                $list[] = ['id' => (int) $id, 'title' => $title];
            }
            if ($list) {
                $out[$term] = $list;
            }
        }
        return $out;
    }
}
```

- [ ] **Step 2: Registrar la factoría**

En `config/module.config.php`, dentro de `service_manager.factories`, junto a `AiCataloguer::class`:

```php
            Service\Ai\ProposeRunner::class => function ($container) {
                return new Service\Ai\ProposeRunner(
                    $container->get('Omeka\ApiManager'),
                    $container->get(Service\Ai\AiCataloguer::class),
                    $container->get(Service\Content\MediaSourceInterface::class)
                );
            },
```

- [ ] **Step 3: Lint**

Run: `make lint`
Expected: exit 0.

- [ ] **Step 4: Verificar en contenedor que el runner produce el payload esperado**

Extender `test/container/propose-harness.php` no es necesario; usar un one-off:
```bash
docker exec omeka-s-moduletemplate-omekas-1 sh -c 'cd /var/www/html && php -r "
require \"bootstrap.php\";
\$a=\Omeka\Mvc\Application::init(require \"application/config/application.config.php\");
\$r=\$a->getServiceManager()->get(\"OERManager\\\\Service\\\\Ai\\\\ProposeRunner\");
\$out=\$r->run(3181, \"skip\");
echo isset(\$out[\"alignment\"]) ? \"OK alignment dims: \".implode(\",\",array_keys(\$out[\"alignment\"])).\"\n\" : \"sin alignment\n\";
"' 2>&1 | grep -v "^PHP Warning" | tail -3
```
Expected: `OK alignment dims: …` (las dimensiones propuestas para #3181).

- [ ] **Step 5: Commit**

```bash
git add src/Service/Ai/ProposeRunner.php config/module.config.php
git commit -m "feat(ai): ProposeRunner centraliza itemId->payload para el Job (TASK-020)"
```

---

## Task 6: `JobProgressReporter` — progreso al store + shouldStop del Job (glue)

**Files:**
- Create: `src/Service/Ai/JobProgressReporter.php`

**Interfaces:**
- Consumes: `ProposalStore` (Task 2), `ProgressReporter` (Task 3), `Omeka\Job\AbstractJob`.
- Produces: `new JobProgressReporter(ProposalStore $store, \Omeka\Job\AbstractJob $job)` implementando `ProgressReporter`; `report` escribe `{status:'in_progress', step, done, total}` en el store; `shouldStop` delega en `$job->shouldStop()`.

- [ ] **Step 1: Crear el servicio**

Create `src/Service/Ai/JobProgressReporter.php`:

```php
<?php

namespace OERManager\Service\Ai;

use Omeka\Job\AbstractJob;

/**
 * ProgressReporter de producción (TASK-020): publica el progreso en el ProposalStore
 * para que el polling lo lea, y consulta la parada por la señal nativa del Job
 * (AbstractJob::shouldStop() relee el estado de BD, así que ve una cancelación
 * puesta por el proceso web).
 */
final class JobProgressReporter implements ProgressReporter
{
    public function __construct(
        private ProposalStore $store,
        private AbstractJob $job,
        private int $jobId
    ) {
    }

    public function report(string $step, int $done, int $total): void
    {
        $this->store->write($this->jobId, [
            'status' => 'in_progress',
            'step' => $step,
            'done' => $done,
            'total' => $total,
        ]);
    }

    public function shouldStop(): bool
    {
        return $this->job->shouldStop();
    }
}
```

El `jobId` se pasa explícito (el Task 7 ya tiene `$this->job->getId()` a mano) para
no depender de accesores no públicos de `AbstractJob`.

- [ ] **Step 2: Lint**

Run: `make lint`
Expected: exit 0. (No hay test de host: depende de `AbstractJob` del core → se verifica en la Task 8 en contenedor.)

- [ ] **Step 3: Commit**

```bash
git add src/Service/Ai/JobProgressReporter.php
git commit -m "feat(ai): JobProgressReporter publica progreso y consulta parada (TASK-020)"
```

---

## Task 7: `AiProposeJob` — el Job en 2º plano (glue)

**Files:**
- Create: `src/Job/AiProposeJob.php`

**Interfaces:**
- Consumes: `Omeka\Job\AbstractJob`, `ProposeRunner` (Task 5), `ProposalStore` (Task 2), `JobProgressReporter` (Task 6), `JobStoppedException` (Task 3), `LlmException`.
- Produces: `AiProposeJob::perform()` que lee args `item` (int) y `large_pdf` (string), corre el runner con un `JobProgressReporter`, y escribe en el store `{status:'completed', payload}` | `{status:'stopped'}` | `{status:'error', code}`.

- [ ] **Step 1: Crear el Job**

Create `src/Job/AiProposeJob.php`:

```php
<?php

namespace OERManager\Job;

use OERManager\Service\Ai\JobProgressReporter;
use OERManager\Service\Ai\JobStoppedException;
use OERManager\Service\Ai\ProposalStore;
use OERManager\Service\Ai\ProposeRunner;
use OERManager\Service\Llm\LlmException;
use Omeka\Job\AbstractJob;

/**
 * Ejecuta el «Proponer con IA» de un item en 2º plano (TASK-020): corre el
 * ProposeRunner reportando fase a fase y deja el resultado en el ProposalStore para
 * que el polling lo recoja. NUNCA escribe en el catálogo (ADR-0007): solo propone.
 * Impersona a su owner (identidad nativa del Job) → la ACL del propose se preserva.
 */
class AiProposeJob extends AbstractJob
{
    public function perform(): void
    {
        $services = $this->getServiceLocator();
        /** @var ProposeRunner $runner */
        $runner = $services->get(ProposeRunner::class);
        /** @var ProposalStore $store */
        $store = $services->get(ProposalStore::class);

        $jobId = (int) $this->job->getId();
        $itemId = (int) $this->getArg('item', 0);
        $largePdf = (string) $this->getArg('large_pdf', 'ask');

        $progress = new JobProgressReporter($store, $this, $jobId);

        try {
            $payload = $runner->run($itemId, $largePdf, $progress);
            $store->write($jobId, ['status' => 'completed', 'payload' => $payload]);
        } catch (JobStoppedException $e) {
            $store->write($jobId, ['status' => 'stopped']);
        } catch (LlmException $e) {
            $services->get('Omeka\Logger')->err('OERManager ai propose job item ' . $itemId . ': ' . $e->getMessage());
            $store->write($jobId, ['status' => 'error', 'code' => 'llm']);
        } catch (\Throwable $e) {
            $services->get('Omeka\Logger')->err('OERManager ai propose job item ' . $itemId . ': ' . $e->getMessage());
            $store->write($jobId, ['status' => 'error', 'code' => 'unexpected']);
        }
    }
}
```

- [ ] **Step 2: Registrar `ProposalStore` en el service manager**

En `config/module.config.php`, `service_manager.factories`, añadir la factoría del store con el directorio privado:

```php
            Service\Ai\ProposalStore::class => function ($container) {
                return new Service\Ai\ProposalStore(sys_get_temp_dir() . '/oer-manager-proposals');
            },
```

- [ ] **Step 3: Lint**

Run: `make lint`
Expected: exit 0.

- [ ] **Step 4: Verificar en contenedor que el Job corre en 2º plano y escribe el resultado**

```bash
docker exec omeka-s-moduletemplate-omekas-1 sh -c 'cd /var/www/html && php -r "
require \"bootstrap.php\";
\$a=\Omeka\Mvc\Application::init(require \"application/config/application.config.php\");
\$s=\$a->getServiceManager();
\$d=\$s->get(\"Omeka\\\\Job\\\\Dispatcher\");
\$job=\$d->dispatch(\"OERManager\\\\Job\\\\AiProposeJob\", [\"item\"=>3181, \"large_pdf\"=>\"skip\"]);
echo \"jobId=\".\$job->getId().\"\n\";
\$store=\$s->get(\"OERManager\\\\Service\\\\Ai\\\\ProposalStore\");
for(\$i=0;\$i<120;\$i++){ \$st=\$store->read(\$job->getId()); if(\$st){ echo \$i.\"s status=\".\$st[\"status\"].(isset(\$st[\"step\"])?\" step=\".\$st[\"step\"]:\"\").\"\n\"; if(in_array(\$st[\"status\"],[\"completed\",\"error\",\"stopped\"]))break; } sleep(1); }
"' 2>&1 | grep -v "^PHP Warning" | tail -15
```
Expected: `jobId=…`, luego varias líneas `status=in_progress step=…` y finalmente `status=completed`.

- [ ] **Step 5: Commit**

```bash
git add src/Job/AiProposeJob.php config/module.config.php
git commit -m "feat(ai): AiProposeJob ejecuta el propose en 2º plano (TASK-020)"
```

---

## Task 8: Acciones del controlador — despachar, sondear, cancelar (glue)

**Files:**
- Modify: `src/Controller/Admin/IndexController.php`
- Modify: `config/module.config.php` (añadir `ProposeRunner` y `ProposalStore` a las deps del controlador)

**Interfaces:**
- Consumes: `Omeka\Job\Dispatcher`, `ProposalStore` (Task 2), API de `jobs`, CSRF.
- Produces: `aiProposeAction` devuelve `{jobId}`; `aiProposeStatusAction` devuelve el estado cruzado; `aiProposeCancelAction` para el Job. Sin rutas nuevas (el segment `[/:action]` ya mapea `ai-propose-status`/`ai-propose-cancel`).

- [ ] **Step 1: Añadir deps al controlador**

En `config/module.config.php`, en la factoría de `IndexController`, añadir dos argumentos al final:
```php
                    $container->get('Omeka\Settings'),
                    $container->get('Omeka\Job\Dispatcher'),
                    $container->get(Service\Ai\ProposalStore::class)
```
Y en `src/Controller/Admin/IndexController.php`, añadir las propiedades y parámetros del constructor:
```php
    private \Omeka\Job\Dispatcher $jobDispatcher;
    private \OERManager\Service\Ai\ProposalStore $proposalStore;
```
(añadir al final de la lista del constructor y asignarlas; añadir los `use` correspondientes: `use Omeka\Job\Dispatcher;` y `use OERManager\Service\Ai\ProposalStore;`).

- [ ] **Step 2: Reemplazar `aiProposeAction` por el despacho**

Sustituir el cuerpo posterior a las validaciones (CSRF/enabled/id) de `aiProposeAction`. Mantener las validaciones síncronas; cambiar la parte que hoy llama a `propose`:

```php
        $largePdf = (string) $this->params()->fromPost('large_pdf', 'ask');
        $this->proposalStore->sweepOld(3600); // limpia huérfanos oportunistamente

        try {
            $job = $this->jobDispatcher->dispatch(\OERManager\Job\AiProposeJob::class, [
                'item' => $id,
                'large_pdf' => $largePdf,
            ]);
        } catch (\Exception $e) {
            $this->logger->err('OERManager ai propose dispatch item ' . $id . ': ' . $e->getMessage());
            return new JsonModel(['error' => 'dispatch']);
        }

        return new JsonModel(['jobId' => (int) $job->getId()]);
```
(Eliminar del método el bloque `try { $proposal = $this->aiCataloguer->propose(...) } catch ...` y el `return new JsonModel([...])` final que enriquecía; ahora eso vive en el Job/Runner.)

- [ ] **Step 3: Añadir `aiProposeStatusAction`**

```php
    public function aiProposeStatusAction()
    {
        if (!$this->getRequest()->isPost()) {
            return new JsonModel(['error' => 'method']);
        }
        if (!$this->csrfValidator()->isValid((string) $this->params()->fromPost('csrf'))) {
            return new JsonModel(['error' => 'csrf']);
        }
        $jobId = (int) $this->params()->fromPost('jobId');
        if ($jobId <= 0) {
            return new JsonModel(['error' => 'id']);
        }
        // Control de acceso PRIMERO: la API de jobs acota por ACL (dueño/admin).
        try {
            $job = $this->api()->read('jobs', $jobId)->getContent();
        } catch (\Exception $e) {
            return new JsonModel(['error' => 'not_found']);
        }

        $state = $this->proposalStore->read($jobId);
        if (null !== $state) {
            return new JsonModel($state); // in_progress / completed / error / stopped
        }
        // Sin fichero: cruzar con el estado nativo para no colgar el polling.
        $native = (string) $job->status();
        if (in_array($native, ['error', 'stopped'], true)) {
            return new JsonModel(['status' => 'error', 'code' => 'job_' . $native]);
        }
        return new JsonModel(['status' => 'in_progress', 'step' => 'Iniciando…', 'done' => 0, 'total' => 5]);
    }
```

- [ ] **Step 4: Añadir `aiProposeCancelAction`**

```php
    public function aiProposeCancelAction()
    {
        if (!$this->getRequest()->isPost()) {
            return new JsonModel(['error' => 'method']);
        }
        if (!$this->csrfValidator()->isValid((string) $this->params()->fromPost('csrf'))) {
            return new JsonModel(['error' => 'csrf']);
        }
        $jobId = (int) $this->params()->fromPost('jobId');
        if ($jobId <= 0) {
            return new JsonModel(['error' => 'id']);
        }
        try {
            $this->api()->read('jobs', $jobId); // valida propiedad por ACL
            $this->jobDispatcher->stop($jobId);
        } catch (\Exception $e) {
            return new JsonModel(['error' => 'not_found']);
        }
        return new JsonModel(['stopped' => true]);
    }
```

- [ ] **Step 5: Lint**

Run: `make lint`
Expected: exit 0.

- [ ] **Step 6: Verificar en contenedor el ciclo HTTP completo por CLI**

Reusar el patrón de dispatch de Task 7 pero a través del controlador es complejo por CSRF; en su lugar verificar que las acciones existen y responden. Verificación funcional real vía navegador se hace en Task 10. Aquí solo lint + que la config carga:
```bash
docker exec omeka-s-moduletemplate-omekas-1 sh -c 'cd /var/www/html && php -r "require \"bootstrap.php\"; \$a=\Omeka\Mvc\Application::init(require \"application/config/application.config.php\"); echo \"config OK\n\";"' 2>&1 | grep -v "^PHP Warning" | tail -2
```
Expected: `config OK` (sin fatal de factoría del controlador).

- [ ] **Step 7: Commit**

```bash
git add src/Controller/Admin/IndexController.php config/module.config.php
git commit -m "feat(ai): controlador despacha/sondea/cancela el propose asíncrono (TASK-020)"
```

---

## Task 9: JS — arrancar, sondear con progreso, cancelar, reenganchar

**Files:**
- Modify: `asset/js/oer-master-view.js` (reescribir `runProposal` y el handler del botón)

**Interfaces:**
- Consumes: endpoints `ai-propose`, `ai-propose-status`, `ai-propose-cancel`; `data-ai-propose-url` + análogas del `#oer-master-view-table`.
- Produces: flujo de sondeo con `localStorage['oer-ai-job-'+itemId]`, progreso en el panel, botón Cancelar, guardia de doble arranque, reenganche al abrir.

- [ ] **Step 1: Añadir las URLs de status/cancel a la vista**

En `view/oer-manager/index/index.phtml` (o donde se define `data-ai-propose-url` en `#oer-master-view-table`), añadir dos data-attrs análogos apuntando a las rutas `admin/oer-manager` con `action` `ai-propose-status` y `ai-propose-cancel`. Buscar la línea con `ai-propose-url` y replicar el patrón `$this->url('admin/oer-manager', ['action' => 'ai-propose-status'])`.

- [ ] **Step 2: Reescribir el bloque de propose en `asset/js/oer-master-view.js`**

Reemplazar `runProposal` (y añadir sondeo/cancelación) con:

```javascript
    var POLL_MS = 3000;
    var POLL_MAX = 240; // ~12 min de techo de sondeo

    function jobKey(itemId) { return 'oer-ai-job-' + itemId; }

    function pollStatus($panel, $button, $diff, jobId, attempt) {
        if (attempt > POLL_MAX) {
            $diff.text(Omeka.jsTranslate('La propuesta tarda demasiado. Reintenta más tarde.'));
            $button.prop('disabled', false);
            return;
        }
        $.post($('#oer-master-view-table').data('ai-propose-status-url'), {
            jobId: jobId,
            csrf: $('#oer-master-view-table').data('recatalog-csrf')
        }).done(function (r) {
            if (r.status === 'in_progress') {
                $diff.text(Omeka.jsTranslate('Analizando… ') + (r.step || '') +
                    (r.total ? ' (' + r.done + '/' + r.total + ')' : ''));
                setTimeout(function () { pollStatus($panel, $button, $diff, jobId, attempt + 1); }, POLL_MS);
                return;
            }
            localStorage.removeItem(jobKey($panel.data('item-id')));
            $button.prop('disabled', false);
            if (r.status === 'stopped') { $diff.text(Omeka.jsTranslate('Propuesta cancelada.')); return; }
            if (r.status === 'error' || r.error) {
                $diff.text(Omeka.jsTranslate('El proveedor de IA falló. Revisa el log de Omeka.'));
                return;
            }
            // completed
            handleProposalPayload($panel, $button, $diff, r.payload);
        }).fail(function () {
            setTimeout(function () { pollStatus($panel, $button, $diff, jobId, attempt + 1); }, POLL_MS);
        });
    }

    function handleProposalPayload($panel, $button, $diff, response) {
        if (response.needs_confirmation) {
            confirmLargePdf($panel, $button, $diff, response.needs_confirmation);
            return;
        }
        var added = applyAiProposal($panel, response.alignment, response.justifications);
        var note = added
            ? Omeka.jsTranslate('Propuesta de IA añadida: revísala y previsualiza antes de confirmar.')
            : Omeka.jsTranslate('La IA no propuso cambios nuevos.');
        if (response.content && response.content.empty) {
            note = Omeka.jsTranslate('Sin contenido textual que clasificar (metadatos/medios vacíos).');
        }
        var tooLarge = (response.content && response.content.too_large_pdfs) || [];
        if (tooLarge.length) {
            note += ' ' + Omeka.jsTranslate('PDF omitido por exceder el tope de visión: ') +
                tooLarge.map(function (p) { return p.name + ' (' + humanBytes(p.size) + ')'; }).join(', ') + '.';
        }
        $diff.text(note);
        if (response.debug) {
            logAiDebug($panel.data('item-id'), response.debug, response.content);
            $panel.find('.oer-ai-debug').remove();
            $panel.find('.oer-recatalog-diff').after(buildAiDebugPanel(response.debug, response.content));
        }
    }

    function startProposal($panel, $button, $diff, largePdf) {
        var itemId = $panel.data('item-id');
        $diff.text(Omeka.jsTranslate('Enviando…'));
        $button.prop('disabled', true);
        $.post($('#oer-master-view-table').data('ai-propose-url'), {
            id: itemId,
            csrf: $('#oer-master-view-table').data('recatalog-csrf'),
            large_pdf: largePdf || 'ask'
        }).done(function (r) {
            if (r.error) {
                $diff.text(Omeka.jsTranslate('No se pudo iniciar el análisis (') + r.error + ').');
                $button.prop('disabled', false);
                return;
            }
            localStorage.setItem(jobKey(itemId), r.jobId);
            pollStatus($panel, $button, $diff, r.jobId, 0);
        }).fail(function () {
            $diff.text(Omeka.jsTranslate('No se pudo consultar a la IA.'));
            $button.prop('disabled', false);
        });
    }
```

Actualizar `confirmLargePdf` para que su re-despacho llame a `startProposal` (no al viejo `runProposal`):
```javascript
        var decision = window.confirm(msg) ? 'include' : 'skip';
        startProposal($panel, $button, $diff, decision);
```

Reemplazar el handler del botón:
```javascript
    $(document).on('click', '.oer-recatalog-ai', function () {
        var $button = $(this);
        var $panel = $button.closest('.oer-recatalog');
        var $diff = $panel.find('.oer-recatalog-diff');
        var itemId = $panel.data('item-id');
        // Guardia de doble arranque: si ya hay un job pendiente, reengancha en vez de duplicar.
        var pending = localStorage.getItem(jobKey(itemId));
        if (pending) {
            pollStatus($panel, $button, $diff, pending, 0);
            return;
        }
        startProposal($panel, $button, $diff, 'ask');
    });
```

- [ ] **Step 3: Verificar sintaxis JS**

Run: `node --check asset/js/oer-master-view.js`
Expected: sin salida (OK).

- [ ] **Step 4: Commit**

```bash
git add asset/js/oer-master-view.js view/oer-manager/index/index.phtml
git commit -m "feat(ai): JS de sondeo con progreso, cancelación y reenganche (TASK-020)"
```

---

## Task 10: Verificación funcional en contenedor + cierre de gobierno

**Files:**
- Modify: `docs/backlog.md`, `docs/traceability.md`, `docs/project-memory.md`

- [ ] **Step 1: Verificar el flujo completo en el navegador del contenedor**

Con el módulo desplegado (`localhost:8080/admin`), sobre un item lento (p. ej. #4362 con `large_pdf` include, o cualquier item):
- Clic «Proponer con IA» → el panel muestra «Analizando… <fase> (n/5)» y el botón se deshabilita; la petición inicial vuelve al instante (sin 504).
- El progreso avanza por fases.
- Al completar, el panel se rellena con los chips (igual que antes).
- Probar «Cancelar» a media ejecución → «Propuesta cancelada».
- Cerrar el panel/pestaña a media ejecución y reabrir el item → reengancha y recupera progreso/resultado.

Registrar los resultados observados (tiempos, que no hay 504, que el progreso se ve).

- [ ] **Step 2: Verificar que un Job que no arranca no cuelga el polling**

Forzar el caso (p. ej. despachar con un item inexistente que haga fallar el runner temprano) y comprobar que el status devuelve `error` reintentable, no `in_progress` eterno.

- [ ] **Step 3: Lint + tests de host finales**

Run: `make lint && make test`
Expected: lint exit 0; PASS (179 tests).

- [ ] **Step 4: Cerrar gobierno**

Actualizar en `docs/backlog.md` la fila TASK-020 a hecha con el resumen de verificación; añadir la traza en `docs/traceability.md` (NFR-010) y la entrada en `docs/project-memory.md`.

- [ ] **Step 5: Commit**

```bash
git add docs/
git commit -m "docs(ai): cierre TASK-020 (propose asíncrono verificado en contenedor)"
```

---

## Self-review (cobertura del spec)

- §3 Flujo → Tasks 7 (Job), 8 (dispatch/status), 9 (JS). ✅
- §4.1 Progreso por fases → Task 4 (reportes) + Task 6 (publica) + Task 9 (pinta). ✅
- §4.2 Seguir trabajando/cerrar panel + §4.3 recuperación → Task 9 (localStorage + reenganche) + Task 2 (`read` no borra) + TTL. ✅
- §4.4 Cancelar → Task 4 (JobStoppedException) + Task 8 (cancel action) + Task 9 (botón). ✅
- §4.5 Robustez/huérfanos → Task 2 (atómica + sweepOld) + Task 8 (sweep oportunista). ✅
- §5.1 ProposalStore → Task 2. §5.2 ProgressReporter → Tasks 3, 4, 6. §5.3 ProposeRunner → Task 5. §5.4 AiProposeJob → Task 7. §5.5 Controller → Task 8. §5.6 JS → Task 9. ✅
- §6 Seguridad (dir privado, ACL por API, CSRF, atómica) → Tasks 2, 7 (dir), 8 (ACL/CSRF). ✅
- §7 Riesgos: PhpCli → Task 1 (des-riesgo) + Task 10 §2 (cuelgue). ✅
- §8 Pruebas → host Tasks 2, 3, 4; contenedor Tasks 1, 5, 7, 8, 10. ✅

Sin placeholders. Firmas consistentes entre tareas (`propose(...,?ProgressReporter)`, `ProposalStore::read/write/sweepOld`, `ProposeRunner::run`, `JobProgressReporter(store,job,jobId)`).
