# TASK-028 rebanada 3a — superficies de lectura del drawer

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** El drawer deja de ser nueve campos fijos y estrena las dos señales que el módulo lleva calculando en silencio: las incidencias de integridad y el historial de curación.

**Architecture:** Una sola acción de servidor (`drawer-details`) devuelve integridad e historial ya resueltos; el drawer los pide en paralelo al item. La decisión vive en clases **puras probadas en host** (`CurationHistory` en PHP, dos modelos en JS); el glue del core es delgado y se verifica con un arnés de contenedor.

**Tech Stack:** PHP 8.4, Omeka-S 4.2, PHPUnit 11.5, `node --test` (sin dependencias ni bundler), PHPCS PSR-12.

**Spec:** `docs/superpowers/specs/2026-08-11-task-028-rebanada-3a-design.md`

## Global Constraints

- **PHP 8.4.** No usar sintaxis de PHP 8.5 aunque el host la acepte.
- **PSR-12.** `make lint` en verde; ninguna línea sobre 120 caracteres. El lint **no** cubre `.phtml` ni `.js`: para plantillas, `php -l`.
- **Esta rebanada no escribe nada.** Ni formularios, ni acciones con CSRF, ni properties nuevas. Si un paso te pide escribir en el catálogo, es un error del plan: para y repórtalo. **Única excepción:** el arnés de contenedor de la Task 7, que fabrica un evento y **restaura el estado**.
- **Sin tablas Doctrine propias** (NFR-002) y **extender el core, nunca parchearlo** (NFR-001).
- Cadenas de UI traducibles: `// @translate` **en la misma línea que el literal** en PHP; `Omeka.jsTranslate(...)` en JS. En línea aparte rompe la extracción del repo.
- **Todo lo interpolado en HTML/DOM, escapado.** En JS usar `textContent`, nunca `innerHTML` con dato del catálogo.
- **ACL por privilegio, nunca por controlador** (lección de TASK-029: conceder el controlador entero deja cualquier acción nueva al alcance de `editor` por herencia).
- Namespaces: código en `OERManager\` → `src/`; tests en `OERManager\Test\` → `test/`.
- Comandos: `make lint`, `make test`, `make test-js`. Desde la raíz, **sin** `docker compose exec`.
- Contenedor: `omeka-s-moduletemplate-omekas-1`, con el módulo montado por volumen. Se invoca con `docker exec`, **no** con `docker compose exec`.
- **Línea base:** 299 tests PHP (686 aserciones), 52 tests JS, lint limpio. No debes romper ninguno.
- **Punto de partida:** rama `task-030-alcance-y-task-028-rebanada-3`, con el spec ya commiteado.

## Estructura de ficheros

**Se crean:**

| Fichero | Responsabilidad |
| --- | --- |
| `src/Service/Governance/CurationHistory.php` | De payloads de evento al modelo de presentación. Pura |
| `test/Service/Governance/CurationHistoryTest.php` | |
| `asset/js/core/historyModel.js` | Del JSON de la acción a filas pintables, con el estado vacío. Puro |
| `asset/js/core/integrityModel.js` | Agrupa incidencias por severidad. Puro |
| `test/js/historyModel.test.js` · `test/js/integrityModel.test.js` | |
| `asset/js/ui/drawerDetails.js` | Pinta las secciones nuevas. Capa de UI |
| `test/container/drawer-details-check.php` | Arnés de verificación |

**Se modifican:**

| Fichero | Cambio |
| --- | --- |
| `src/Service/RecatalogService.php` | `eventsOf()` extraído de `lastEventOf()`; `history()` público; resolución de títulos por id |
| `src/Controller/Admin/IndexController.php` | Acción `drawerDetailsAction()` |
| `Module.php` | Privilegio `drawer-details` en la lista ACL de curación |
| `asset/js/ui/drawer.js` | Enlace al editor nativo y lista de medios |
| `asset/js/config.js` | Clave `drawerDetailsUrl` — las URL no se leen del `dataset` en el punto de uso |
| `asset/js/main.js` | Arranca `initDrawerDetails(config)` |
| `asset/js/core/drawerModel.js` | Exporta el mapa término→etiqueta para reusarlo en el historial |
| `view/oer-manager/admin/index/index.phtml` | `data-drawer-details-url` en la tabla |
| `asset/css/oer-master-view.css` | Estilos de las secciones nuevas |
| `docs/` | Cierre de gobierno (Task 8) |

---

## Task 1: `CurationHistory` — el modelo de presentación, puro

Es el corazón de la rebanada. Se escribe primero porque todo lo demás lo consume.

**Files:**
- Create: `src/Service/Governance/CurationHistory.php`
- Test: `test/Service/Governance/CurationHistoryTest.php`

**Interfaces:**
- Consumes: nada.
- Produces: `CurationHistory::rows(array $events, array $titles): array`.
  - `$events`: `list<array{when:string, contributor:string, summary:string, payload:array}>`, tal cual los produce `RecatalogService`, **ya ordenados de más reciente a más antiguo**.
  - `$titles`: `array<int,string>` id → título resuelto. Un id ausente del mapa se rinde como `#<id>`.
  - Retorno: `list<array{when:string, contributor:string, summary:string, isUndo:bool, changes:list<array{term:string, added:list<string>, removed:list<array{title:string,reason:string}>, emptied:bool}>}>`.

**Nota semántica que hay que respetar** (fácil de invertir): en el payload, `why` guarda la justificación de los valores **previos** (`before`), porque el deshacer los restaura. Por tanto el «porqué» que el historial puede mostrar es el de los valores **retirados**, no el de los añadidos — la justificación de los valores actuales vive en la anotación del propio valor, que esta rebanada no lee (E-1 del spec).

- [ ] **Step 1: Escribir el test que falla**

Crear `test/Service/Governance/CurationHistoryTest.php`:

```php
<?php

declare(strict_types=1);

namespace OERManager\Test\Service\Governance;

use OERManager\Service\Governance\CurationHistory;
use PHPUnit\Framework\TestCase;

/**
 * Modelo de presentación del historial de curación (ADR-0015, rebanada 3a).
 *
 * El historial sale de los EVENTOS, no de las value annotations: una anotación
 * vive en el valor, así que al vaciar una dimensión desaparece con ella. El
 * evento sí registra el vaciado, y ese es el caso que más importa probar.
 */
final class CurationHistoryTest extends TestCase
{
    private function event(array $terms, string $when = '2026-08-11T10:00:00+00:00', string $op = 'recatalog'): array
    {
        return [
            'when' => $when,
            'contributor' => 'fmatdia',
            'summary' => 'Re-catalogación · lrmi:teaches +1',
            'payload' => ['v' => 1, 'op' => $op, 'undoOf' => null, 'terms' => $terms],
        ];
    }

    public function testEmptyHistoryIsEmptyNotAnError(): void
    {
        $this->assertSame([], CurationHistory::rows([], []));
    }

    public function testCarriesTheReadableSummaryAndWho(): void
    {
        $rows = CurationHistory::rows(
            [$this->event(['lrmi:teaches' => ['before' => [], 'after' => [7]]])],
            [7 => 'Números enteros (1º ESO)']
        );

        $this->assertCount(1, $rows);
        $this->assertSame('fmatdia', $rows[0]['contributor']);
        $this->assertSame('Re-catalogación · lrmi:teaches +1', $rows[0]['summary']);
        $this->assertSame('2026-08-11T10:00:00+00:00', $rows[0]['when']);
        $this->assertFalse($rows[0]['isUndo']);
    }

    public function testAddedValuesAreResolvedToTitles(): void
    {
        $rows = CurationHistory::rows(
            [$this->event(['lrmi:teaches' => ['before' => [], 'after' => [7, 8]]])],
            [7 => 'Números enteros (1º ESO)', 8 => 'Fracciones (2º ESO)']
        );

        $this->assertSame(['lrmi:teaches'], array_column($rows[0]['changes'], 'term'));
        $this->assertSame(
            ['Números enteros (1º ESO)', 'Fracciones (2º ESO)'],
            $rows[0]['changes'][0]['added']
        );
        $this->assertSame([], $rows[0]['changes'][0]['removed']);
    }

    /** El caso que las anotaciones NO pueden representar: la dimensión se vacía. */
    public function testAnEmptiedDimensionIsRecordedWithItsRemovedValues(): void
    {
        $rows = CurationHistory::rows(
            [$this->event(['lrmi:teaches' => ['before' => [7, 8], 'after' => []]])],
            [7 => 'Números enteros (1º ESO)', 8 => 'Fracciones (2º ESO)']
        );

        $change = $rows[0]['changes'][0];
        $this->assertTrue($change['emptied']);
        $this->assertSame([], $change['added']);
        $this->assertSame(
            ['Números enteros (1º ESO)', 'Fracciones (2º ESO)'],
            array_column($change['removed'], 'title')
        );
    }

    public function testAValueThatStayedIsNeitherAddedNorRemoved(): void
    {
        $rows = CurationHistory::rows(
            [$this->event(['lrmi:teaches' => ['before' => [7, 8], 'after' => [7, 9]]])],
            [7 => 'Se queda', 8 => 'Se va', 9 => 'Llega']
        );

        $change = $rows[0]['changes'][0];
        $this->assertSame(['Llega'], $change['added']);
        $this->assertSame(['Se va'], array_column($change['removed'], 'title'));
        $this->assertFalse($change['emptied']);
    }

    /** La justificación de la IA acompaña a los valores RETIRADOS (ver nota del plan). */
    public function testTheReasonTravelsWithTheRemovedValue(): void
    {
        $rows = CurationHistory::rows(
            [$this->event([
                'lrmi:teaches' => [
                    'before' => [7, 8],
                    'after' => [],
                    'why' => [7 => 'El recurso trabaja la recta numérica'],
                ],
            ])],
            [7 => 'Números enteros', 8 => 'Fracciones']
        );

        $removed = $rows[0]['changes'][0]['removed'];
        $this->assertSame('Números enteros', $removed[0]['title']);
        $this->assertSame('El recurso trabaja la recta numérica', $removed[0]['reason']);
        $this->assertSame('', $removed[1]['reason'], 'sin porqué se rinde cadena vacía, no null');
    }

    public function testAnUndoIsMarkedAsSuch(): void
    {
        $rows = CurationHistory::rows(
            [$this->event(['lrmi:teaches' => ['before' => [], 'after' => [7]]], '2026-08-11T10:00:00+00:00', 'undo')],
            [7 => 'Números enteros']
        );

        $this->assertTrue($rows[0]['isUndo']);
    }

    /** Un destino borrado del currículo no puede reventar el historial. */
    public function testAnUnresolvedIdFallsBackToItsNumber(): void
    {
        $rows = CurationHistory::rows(
            [$this->event(['schema:about' => ['before' => [], 'after' => [404]]])],
            []
        );

        $this->assertSame(['#404'], $rows[0]['changes'][0]['added']);
    }

    /** Una dimensión sin cambio real no debería estar en el payload, pero si llega, se omite. */
    public function testATermWithNoChangeIsOmitted(): void
    {
        $rows = CurationHistory::rows(
            [$this->event([
                'lrmi:teaches' => ['before' => [7], 'after' => [7]],
                'schema:about' => ['before' => [], 'after' => [9]],
            ])],
            [7 => 'Igual', 9 => 'Nuevo']
        );

        $this->assertSame(['schema:about'], array_column($rows[0]['changes'], 'term'));
    }

    public function testTheOrderGivenIsPreserved(): void
    {
        $rows = CurationHistory::rows([
            $this->event(['lrmi:teaches' => ['before' => [], 'after' => [7]]], '2026-08-11T12:00:00+00:00'),
            $this->event(['lrmi:teaches' => ['before' => [], 'after' => [8]]], '2026-08-11T09:00:00+00:00'),
        ], [7 => 'A', 8 => 'B']);

        $this->assertSame(
            ['2026-08-11T12:00:00+00:00', '2026-08-11T09:00:00+00:00'],
            array_column($rows, 'when')
        );
    }

    /** Ids que hay que resolver: los de todos los eventos, sin repetir. */
    public function testReferencedIdsCollectsBeforeAndAfterWithoutDuplicates(): void
    {
        $ids = CurationHistory::referencedIds([
            $this->event(['lrmi:teaches' => ['before' => [7, 8], 'after' => [8, 9]]]),
            $this->event(['schema:about' => ['before' => [], 'after' => [7]]]),
        ]);

        sort($ids);
        $this->assertSame([7, 8, 9], $ids);
    }

    public function testReferencedIdsOfNothingIsEmpty(): void
    {
        $this->assertSame([], CurationHistory::referencedIds([]));
    }
}
```

- [ ] **Step 2: Ejecutar y verificar que falla**

Run: `make test`
Expected: FAIL — `Class "OERManager\Service\Governance\CurationHistory" not found`.

- [ ] **Step 3: Implementación mínima**

Crear `src/Service/Governance/CurationHistory.php`:

```php
<?php

declare(strict_types=1);

namespace OERManager\Service\Governance;

/**
 * Modelo de presentación del historial de curación (ADR-0015).
 *
 * El historial de la rebanada 3a sale de los EVENTOS `dcterms:provenance`, no
 * de las value annotations: una anotación vive en el valor, así que al vaciar
 * una dimensión desaparece con ella y el vaciado —el cambio más destructivo que
 * el curador puede hacer— sería justo el que no se ve. El evento sí lo registra.
 *
 * Pura a propósito: la lectura de los valores y la resolución de títulos las
 * hace `RecatalogService`, que sí depende del core.
 *
 * **Semántica del porqué, fácil de invertir:** en el payload, `why` guarda la
 * justificación de los valores PREVIOS, porque el deshacer los restaura. El
 * historial la muestra por tanto junto a los valores RETIRADOS. La del valor
 * actual vive en la anotación de ese valor, que esta rebanada no lee.
 */
final class CurationHistory
{
    /** Marca de un id cuyo destino ya no se puede resolver. */
    public const UNRESOLVED_PREFIX = '#';

    /**
     * @param list<array{when:string,contributor:string,summary:string,payload:array}> $events
     *        Ya ordenados de más reciente a más antiguo.
     * @param array<int,string> $titles id → título resuelto
     * @return list<array{when:string,contributor:string,summary:string,isUndo:bool,changes:list<array{
     *     term:string, added:list<string>, removed:list<array{title:string,reason:string}>, emptied:bool
     * }>}>
     */
    public static function rows(array $events, array $titles): array
    {
        $rows = [];
        foreach ($events as $event) {
            $payload = $event['payload'];
            $changes = [];

            foreach ($payload['terms'] ?? [] as $term => $entry) {
                $before = array_map('intval', $entry['before'] ?? []);
                $after = array_map('intval', $entry['after'] ?? []);
                $addedIds = array_values(array_diff($after, $before));
                $removedIds = array_values(array_diff($before, $after));

                if ([] === $addedIds && [] === $removedIds) {
                    continue;
                }

                $why = [];
                foreach ($entry['why'] ?? [] as $id => $reason) {
                    $why[(int) $id] = (string) $reason;
                }

                $removed = [];
                foreach ($removedIds as $id) {
                    $removed[] = [
                        'title' => self::title($id, $titles),
                        'reason' => $why[$id] ?? '',
                    ];
                }

                $changes[] = [
                    'term' => (string) $term,
                    'added' => array_map(static fn (int $id): string => self::title($id, $titles), $addedIds),
                    'removed' => $removed,
                    'emptied' => [] === $after && [] !== $before,
                ];
            }

            $rows[] = [
                'when' => (string) $event['when'],
                'contributor' => (string) $event['contributor'],
                'summary' => (string) $event['summary'],
                'isUndo' => 'undo' === ($payload['op'] ?? ''),
                'changes' => $changes,
            ];
        }

        return $rows;
    }

    /**
     * Ids de item que el historial necesita resolver a título, sin repetir.
     *
     * @param list<array{payload:array}> $events
     * @return list<int>
     */
    public static function referencedIds(array $events): array
    {
        $ids = [];
        foreach ($events as $event) {
            foreach ($event['payload']['terms'] ?? [] as $entry) {
                foreach ([...($entry['before'] ?? []), ...($entry['after'] ?? [])] as $id) {
                    $ids[(int) $id] = true;
                }
            }
        }
        return array_map('intval', array_keys($ids));
    }

    /** @param array<int,string> $titles */
    private static function title(int $id, array $titles): string
    {
        $title = trim((string) ($titles[$id] ?? ''));
        return '' === $title ? self::UNRESOLVED_PREFIX . $id : $title;
    }
}
```

- [ ] **Step 4: Ejecutar y verificar que pasa**

Run: `make test && make lint`
Expected: PASS, 12 tests nuevos.

- [ ] **Step 5: Commit**

```bash
git add src/Service/Governance/CurationHistory.php test/Service/Governance/CurationHistoryTest.php
git commit -m "feat(historial): modelo de presentacion puro del registro de curacion"
```

---

## Task 2: `RecatalogService::history()` y la resolución de títulos

**Files:**
- Modify: `src/Service/RecatalogService.php` (extraer `eventsOf()` de `lastEventOf()`, añadir `history()` y `titlesFor()`)

**Interfaces:**
- Consumes: `CurationHistory::rows()` y `CurationHistory::referencedIds()` de Task 1.
- Produces: `RecatalogService::history(int $itemId): array` — devuelve directamente el modelo de `CurationHistory::rows()`, con los títulos ya resueltos.

**Cuidado con el desempate, que ya mordió una vez.** `lastEventOf()` elige el máximo con `>=`, o sea que **a igualdad de instante gana el último recorrido**. TASK-007 tuvo un defecto justo aquí (sellos a precisión de segundo y orden de colección no garantizado por Omeka) y se resolvió pasando los sellos a microsegundos. Al ordenar la lista completa hay que **reproducir ese desempate**: descendente por `when` y, a igualdad, por índice de recorrido descendente. Si `eventsOf()[0]` no coincidiera con el `lastEventOf()` de antes, el deshacer restauraría el estado equivocado.

- [ ] **Step 1: Extraer `eventsOf()` conservando el desempate**

En `src/Service/RecatalogService.php`, sustituir el método privado `lastEventOf()` por estos dos:

```php
    /**
     * Todos los eventos de curación legibles del item, del más reciente al más
     * antiguo.
     *
     * El desempate replica el de la versión anterior de `lastEventOf()`, que
     * usaba `>=`: a igualdad de instante gana el ÚLTIMO recorrido. No es un
     * detalle cosmético — TASK-007 tuvo aquí un defecto real (sellos a
     * precisión de segundo y orden de colección que Omeka no garantiza) que
     * restauraba el estado equivocado, y se cerró pasando los sellos a
     * microsegundos. Cambiar este orden reabre aquello.
     *
     * @return list<array{when:string,contributor:string,summary:string,payload:array<string,mixed>}>
     */
    private function eventsOf(ItemRepresentation $item): array
    {
        $found = [];
        $index = 0;
        foreach ($item->value('dcterms:provenance', ['all' => true, 'default' => []]) as $value) {
            $annotation = $value->valueAnnotation();
            if (null === $annotation) {
                continue;
            }
            // El marcador, no el resumen: el resumen es traducible y cambiaría.
            if (CurationEvent::MARKER !== $this->annotationText($annotation, 'dcterms:provenance')) {
                continue;
            }
            $payload = CurationEvent::decode($this->annotationText($annotation, 'dcterms:replaces'));
            if (null === $payload) {
                continue;
            }
            $found[] = [
                'index' => $index++,
                'event' => [
                    'when' => $this->annotationText($annotation, 'dcterms:modified'),
                    'contributor' => $this->annotationText($annotation, 'dcterms:contributor'),
                    'summary' => trim((string) $value->value()),
                    'payload' => $payload,
                ],
            ];
        }

        // ISO-8601 con offset fijo: el orden lexicográfico es el cronológico.
        usort($found, static function (array $a, array $b): int {
            return [$b['event']['when'], $b['index']] <=> [$a['event']['when'], $a['index']];
        });

        return array_column($found, 'event');
    }

    /**
     * Último evento de curación del item, o null si no tiene ninguno legible.
     *
     * @return array{when:string,contributor:string,summary:string,payload:array<string,mixed>}|null
     */
    private function lastEventOf(ItemRepresentation $item): ?array
    {
        return $this->eventsOf($item)[0] ?? null;
    }
```

- [ ] **Step 2: Añadir `history()` y la resolución de títulos**

En la misma clase, justo después del método público `lastEvent()`:

```php
    /**
     * Historial completo de curación del item, ya resuelto a títulos y listo
     * para pintar (rebanada 3a de TASK-028).
     *
     * Los títulos se cualifican con su curso ancestro porque el currículo repite
     * el mismo nombre en varios cursos —«Matemáticas» aparece en cuatro— y un
     * historial que liste solo títulos mostraría líneas idénticas que el curador
     * no puede distinguir. Es el mismo motivo por el que el diff del preview lo
     * hace desde TASK-028 rebanada 1.
     *
     * Coste: una lectura por id referenciado. Es por item y bajo demanda, no por
     * fila de tabla.
     *
     * @return list<array<string,mixed>> Ver CurationHistory::rows()
     */
    public function history(int $itemId): array
    {
        $item = $this->api->read('items', $itemId)->getContent();
        $events = $this->eventsOf($item);
        if ([] === $events) {
            return [];
        }
        return CurationHistory::rows($events, $this->titlesFor(CurationHistory::referencedIds($events)));
    }

    /**
     * Títulos cualificados de los ids dados. Un destino que ya no existe se
     * omite del mapa; `CurationHistory` lo rinde como `#<id>` en vez de romper.
     *
     * @param list<int> $ids
     * @return array<int,string>
     */
    private function titlesFor(array $ids): array
    {
        $titles = [];
        foreach ($ids as $id) {
            try {
                $target = $this->api->read('items', $id)->getContent();
            } catch (\Exception $e) {
                continue;
            }
            $titles[$id] = $this->qualifiedTitle($target, 'lrmi:teaches');
        }
        return $titles;
    }
```

- [ ] **Step 3: Añadir el `use` que falta**

En la cabecera de `src/Service/RecatalogService.php`, junto a los demás `use`, añadir:

```php
use OERManager\Service\Governance\CurationHistory;
```

- [ ] **Step 4: Comprobar que el comportamiento de `lastEvent()` no cambia**

Run: `make lint && make test`
Expected: PASS, 299 + 12 tests, sin regresiones.

Run: `grep -n "private function lastEventOf" -A 4 src/Service/RecatalogService.php`
Expected: el método delega en `eventsOf()` y no duplica el recorrido.

- [ ] **Step 5: Commit**

```bash
git add src/Service/RecatalogService.php
git commit -m "feat(historial): history() sobre eventsOf(), conservando el desempate de lastEvent()"
```

---

## Task 3: La acción `drawer-details`

**Files:**
- Modify: `src/Controller/Admin/IndexController.php` (método nuevo tras `recatalogLastEventAction`)
- Modify: `Module.php` (privilegio en la lista ACL de curación)

**Interfaces:**
- Consumes: `RecatalogService::history()` de Task 2; `IntegrityChecker::check()` (ya existente).
- Produces: la ruta `admin/oer-manager` con `action=drawer-details`, que responde JSON `{integrity: {status, issues}, history: [...]}`. `issues` es `list<array{severity,code,field,message}>` tal cual los emite `IntegrityResult`.

**La ruta no cambia:** `config/module.config.php:263` ya define `/oer-manager[/:action]` y admite guiones. Solo falta el privilegio ACL.

- [ ] **Step 1: Añadir la acción**

En `src/Controller/Admin/IndexController.php`, después de `recatalogLastEventAction()`:

```php
    /**
     * Detalle del drawer que el cliente NO puede calcular ni leer por su cuenta
     * (rebanada 3a de TASK-028): incidencias de integridad e historial de
     * curación, en una sola llamada.
     *
     * Va por el servidor por obligación, no por comodidad: el valor del evento
     * se escribe con `is_public => false` (ADR-0015) y el drawer carga el item
     * con `fetch(apiUrl)` sin autenticar, así que por el JSON-LD no llegaría
     * nunca. Lo dejó anotado el cierre de TASK-007 como aviso para esta cara de
     * lectura.
     *
     * Solo lectura: sin CSRF. La comprobación de enlaces va ENCENDIDA —al
     * contrario que en la tabla— porque aquí es un item a la vez y el detalle
     * es justo lo que se viene a ver.
     */
    public function drawerDetailsAction()
    {
        $id = (int) $this->params()->fromQuery('id');
        if ($id <= 0) {
            return new JsonModel(['integrity' => null, 'history' => []]);
        }

        try {
            $item = $this->api()->read('items', $id)->getContent();
        } catch (\Exception $e) {
            return new JsonModel(['integrity' => null, 'history' => []]);
        }

        $result = $this->integrityChecker->check($item, true);

        return new JsonModel([
            'integrity' => [
                'status' => $result->getStatus(),
                'issues' => $result->getIssues(),
            ],
            'history' => $this->recatalogService->history($id),
        ]);
    }
```

- [ ] **Step 2: Conceder el privilegio**

En `Module.php`, dentro del `allow(['editor', 'site_admin'], ...)` de curación, añadir `'drawer-details'` a la lista de privilegios, justo después de `'recatalog-last-event'`:

```php
                'recatalog-last-event',
                'drawer-details',
```

- [ ] **Step 3: Verificar**

Run: `make lint && make test`
Expected: PASS.

Run: `php -r 'var_dump(is_array(include "config/module.config.php"));'`
Expected: `bool(true)`.

Run: `docker exec omeka-s-moduletemplate-omekas-1 php /var/www/html/modules/OERManager/test/container/acl-check.php`
Expected: sale con 0. Ese arnés recorre la ACL rol a rol; si el privilegio nuevo hubiera quedado fuera o de más, lo dice.

- [ ] **Step 4: Commit**

```bash
git add src/Controller/Admin/IndexController.php Module.php
git commit -m "feat(drawer): accion de solo lectura con integridad e historial"
```

---

## Task 4: `integrityModel.js` — agrupar incidencias, puro

**Files:**
- Create: `asset/js/core/integrityModel.js`
- Test: `test/js/integrityModel.test.js`

**Interfaces:**
- Consumes: el campo `integrity` de la respuesta de Task 3.
- Produces: `integrityGroups(integrity)` → `[{severity, issues:[{code, field, message}]}]`, con `error` antes que `warning`. Y `INTEGRITY_OK_TEXT`.

- [ ] **Step 1: Escribir el test que falla**

Crear `test/js/integrityModel.test.js`:

```js
import { test } from 'node:test';
import assert from 'node:assert/strict';
import { integrityGroups, INTEGRITY_OK_TEXT } from '../../asset/js/core/integrityModel.js';

const issue = (severity, code) => ({ severity, code, field: 'dcterms:rights', message: 'msg ' + code });

test('sin integridad devuelve lista vacía', () => {
  assert.deepEqual(integrityGroups(null), []);
  assert.deepEqual(integrityGroups(undefined), []);
});

test('sin incidencias devuelve lista vacía', () => {
  assert.deepEqual(integrityGroups({ status: 'ok', issues: [] }), []);
});

test('agrupa por severidad', () => {
  const groups = integrityGroups({ status: 'warning', issues: [issue('warning', 'a'), issue('warning', 'b')] });
  assert.equal(groups.length, 1);
  assert.equal(groups[0].severity, 'warning');
  assert.deepEqual(groups[0].issues.map((i) => i.code), ['a', 'b']);
});

test('los errores van antes que los avisos', () => {
  const groups = integrityGroups({
    status: 'error',
    issues: [issue('warning', 'w'), issue('error', 'e')]
  });
  assert.deepEqual(groups.map((g) => g.severity), ['error', 'warning']);
});

test('una severidad desconocida no se pierde: va al final', () => {
  const groups = integrityGroups({ status: 'warning', issues: [issue('rara', 'x'), issue('error', 'e')] });
  assert.deepEqual(groups.map((g) => g.severity), ['error', 'rara']);
});

test('el texto de ficha sana existe y no está vacío', () => {
  assert.equal(typeof INTEGRITY_OK_TEXT, 'string');
  assert.ok(INTEGRITY_OK_TEXT.length > 0);
});
```

- [ ] **Step 2: Ejecutar y verificar que falla**

Run: `make test-js`
Expected: FAIL — `Cannot find module .../asset/js/core/integrityModel.js`.

- [ ] **Step 3: Implementación mínima**

Crear `asset/js/core/integrityModel.js`:

```js
/**
 * Agrupación de las incidencias de integridad para el drawer (rebanada 3a).
 *
 * Estrena en la UI el detalle de un comprobador que desde TASK-005 se ejecuta
 * en cada guardado y va SOLO al log de Omeka. La columna de la rebanada 2 dice
 * cuántas; esto dice cuáles.
 *
 * Núcleo puro, sin DOM: la frontera que estableció la rebanada 1.
 */

/** Severidades conocidas, en el orden en que se presentan. */
const SEVERITY_ORDER = ['error', 'warning'];

export const INTEGRITY_OK_TEXT = 'Sin incidencias detectadas.';

/**
 * @param {{status: string, issues: Array<{severity: string}>}|null|undefined} integrity
 * @returns {Array<{severity: string, issues: Array<object>}>}
 */
export function integrityGroups(integrity) {
  const issues = (integrity && integrity.issues) || [];
  if (!issues.length) {
    return [];
  }

  const bySeverity = new Map();
  issues.forEach((issue) => {
    const severity = issue.severity || 'warning';
    if (!bySeverity.has(severity)) {
      bySeverity.set(severity, []);
    }
    bySeverity.get(severity).push(issue);
  });

  // Una severidad que no conozcamos no se descarta: se muestra al final. Es
  // preferible enseñar algo sin ordenar a esconder una incidencia real.
  const rank = (severity) => {
    const index = SEVERITY_ORDER.indexOf(severity);
    return index === -1 ? SEVERITY_ORDER.length : index;
  };

  return [...bySeverity.entries()]
    .map(([severity, list]) => ({ severity, issues: list }))
    .sort((a, b) => rank(a.severity) - rank(b.severity));
}
```

- [ ] **Step 4: Ejecutar y verificar que pasa**

Run: `make test-js`
Expected: PASS, 6 tests nuevos (58 en total).

- [ ] **Step 5: Commit**

```bash
git add asset/js/core/integrityModel.js test/js/integrityModel.test.js
git commit -m "feat(drawer): modelo puro de agrupacion de incidencias de integridad"
```

---

## Task 5: `historyModel.js` — filas del historial y el estado vacío, puro

**Files:**
- Create: `asset/js/core/historyModel.js`
- Modify: `asset/js/core/drawerModel.js` (exportar el mapa término→etiqueta)
- Test: `test/js/historyModel.test.js`

**Interfaces:**
- Consumes: el campo `history` de la respuesta de Task 3; `TERM_LABELS` de `drawerModel.js`.
- Produces: `historyRows(history)` → `[{when, contributor, summary, isUndo, changes:[{term, label, added, removed, emptied}], hasReasons}]`, y `HISTORY_EMPTY_NOTICE`.

**El estado vacío es requisito, no adorno.** El spec (E-2) obliga a declarar la cobertura: hay **0 eventos** en el catálogo real frente a 386 anotaciones, así que un curador que vea el vacío sin explicación leerá «este REA nunca se tocó», que es falso.

- [ ] **Step 1: Exportar las etiquetas de término**

En `asset/js/core/drawerModel.js`, justo después de `DRAWER_FIELDS`, añadir:

```js
/**
 * Término RDF → etiqueta legible. Se deriva de DRAWER_FIELDS para que el
 * historial y el drawer no puedan discrepar en cómo llaman a una dimensión.
 */
export const TERM_LABELS = Object.fromEntries(DRAWER_FIELDS);
```

- [ ] **Step 2: Escribir el test que falla**

Crear `test/js/historyModel.test.js`:

```js
import { test } from 'node:test';
import assert from 'node:assert/strict';
import { historyRows, HISTORY_EMPTY_NOTICE } from '../../asset/js/core/historyModel.js';

const row = (changes, extra = {}) => ({
  when: '2026-08-11T10:00:00+00:00',
  contributor: 'fmatdia',
  summary: 'Re-catalogación · lrmi:teaches +1',
  isUndo: false,
  changes,
  ...extra
});

test('un historial vacío da filas vacías', () => {
  assert.deepEqual(historyRows([]), []);
  assert.deepEqual(historyRows(null), []);
});

test('el aviso de cobertura existe y menciona el alcance', () => {
  assert.equal(typeof HISTORY_EMPTY_NOTICE, 'string');
  assert.ok(HISTORY_EMPTY_NOTICE.length > 0);
});

test('traduce el término RDF a su etiqueta del drawer', () => {
  const rows = historyRows([row([{ term: 'lrmi:teaches', added: ['A'], removed: [], emptied: false }])]);
  assert.equal(rows[0].changes[0].label, 'Saberes básicos');
});

test('un término desconocido conserva el término como etiqueta', () => {
  const rows = historyRows([row([{ term: 'ex:loquesea', added: ['A'], removed: [], emptied: false }])]);
  assert.equal(rows[0].changes[0].label, 'ex:loquesea');
});

test('marca la fila que tiene algún porqué', () => {
  const withReason = historyRows([row([
    { term: 'lrmi:teaches', added: [], removed: [{ title: 'X', reason: 'porque sí' }], emptied: true }
  ])]);
  assert.equal(withReason[0].hasReasons, true);

  const without = historyRows([row([
    { term: 'lrmi:teaches', added: [], removed: [{ title: 'X', reason: '' }], emptied: true }
  ])]);
  assert.equal(without[0].hasReasons, false);
});

test('conserva quién, cuándo y el resumen', () => {
  const rows = historyRows([row([{ term: 'lrmi:teaches', added: ['A'], removed: [], emptied: false }])]);
  assert.equal(rows[0].contributor, 'fmatdia');
  assert.equal(rows[0].when, '2026-08-11T10:00:00+00:00');
  assert.equal(rows[0].summary, 'Re-catalogación · lrmi:teaches +1');
});

test('propaga la marca de reversión', () => {
  const rows = historyRows([row([{ term: 'lrmi:teaches', added: ['A'], removed: [], emptied: false }], { isUndo: true })]);
  assert.equal(rows[0].isUndo, true);
});

test('una fila sin cambios no revienta', () => {
  const rows = historyRows([row([])]);
  assert.deepEqual(rows[0].changes, []);
  assert.equal(rows[0].hasReasons, false);
});
```

- [ ] **Step 3: Ejecutar y verificar que falla**

Run: `make test-js`
Expected: FAIL — `Cannot find module .../asset/js/core/historyModel.js`.

- [ ] **Step 4: Implementación mínima**

Crear `asset/js/core/historyModel.js`:

```js
import { TERM_LABELS } from './drawerModel.js';

/**
 * Filas del historial de curación para el drawer (rebanada 3a, ADR-0015).
 *
 * El servidor ya entrega los cambios resueltos a título; aquí solo se traduce
 * el término RDF a su etiqueta y se marca qué filas traen justificación, que es
 * lo que decide si se pinta el «ver porqué» colapsado (PEND-013).
 *
 * Núcleo puro, sin DOM.
 */

/**
 * El historial cubre desde que existe el registro de curación, no desde que
 * existe el catálogo. Sin este aviso, un curador que abra un REA sin eventos
 * leería el vacío como «nunca se tocó», y es falso: hay 386 anotaciones en el
 * catálogo diciendo lo contrario. Declararlo es requisito del diseño, no un
 * adorno.
 */
export const HISTORY_EMPTY_NOTICE =
  'Sin curaciones registradas. El historial recoge los cambios hechos desde el módulo, '
  + 'no los anteriores al registro de curación.';

/**
 * @param {Array<object>|null|undefined} history
 * @returns {Array<object>}
 */
export function historyRows(history) {
  if (!Array.isArray(history)) {
    return [];
  }

  return history.map((entry) => {
    const changes = (entry.changes || []).map((change) => ({
      ...change,
      label: TERM_LABELS[change.term] || change.term
    }));

    const hasReasons = changes.some(
      (change) => (change.removed || []).some((value) => Boolean(value.reason))
    );

    return {
      when: entry.when,
      contributor: entry.contributor,
      summary: entry.summary,
      isUndo: Boolean(entry.isUndo),
      changes,
      hasReasons
    };
  });
}
```

- [ ] **Step 5: Ejecutar y verificar que pasa**

Run: `make test-js && make test`
Expected: PASS, 8 tests JS nuevos (66 en total). PHP sin cambios.

- [ ] **Step 6: Commit**

```bash
git add asset/js/core/historyModel.js asset/js/core/drawerModel.js test/js/historyModel.test.js
git commit -m "feat(drawer): modelo puro del historial con aviso de cobertura"
```

---

## Task 6: Pintar las secciones en el drawer

**Files:**
- Create: `asset/js/ui/drawerDetails.js`
- Modify: `asset/js/ui/drawer.js` (añadir el enlace al item nativo, la lista de medios y el enganche de detalles)
- Modify: `view/oer-manager/admin/index/index.phtml` (atributo `data-drawer-details-url`)
- Modify: `asset/css/oer-master-view.css`

**Interfaces:**
- Consumes: `integrityGroups`, `INTEGRITY_OK_TEXT`, `historyRows`, `HISTORY_EMPTY_NOTICE` de Tasks 4-5; el evento `DRAWER_RENDERED` que `drawer.js` ya emite.
- Produces: nada que consuma otra tarea.

**Se engancha por evento, no por cableado.** `drawer.js` ya emite `oer:drawer-rendered` con el contenedor y el JSON del item, y el panel del re-catalogador se engancha ahí precisamente para que el drawer no dependa de él. Las secciones nuevas siguen el mismo patrón: `drawer.js` no importa `drawerDetails.js`.

- [ ] **Step 1: Añadir las dos URL, por el camino que el repo ya tiene**

**Las URL no se leen del `dataset` en el punto de uso.** `asset/js/config.js` existe precisamente porque antes se leían con `$('#oer-master-view-table').data(...)` en más de diez puntos dispersos, «de modo que un dato que la plantilla dejara de emitir no se detectaba hasta fallar en producción». Todo pasa por `readConfig()`.

Y la URL del editor nativo **no se puede componer en JS**: `window.location.origin + '/admin/item/…'` rompe en cualquier instalación de Omeka bajo subdirectorio. La genera el servidor, que es quien sabe la base.

En `view/oer-manager/admin/index/index.phtml`, en los `data-*` de `<table id="oer-master-view-table">`, junto a `data-recatalog-last-event-url`:

```php
    data-drawer-details-url="<?php echo $escape($this->url('admin/oer-manager', ['action' => 'drawer-details'])); ?>"
```

Y en el `<tr>` de cada fila, junto a `data-api-url`:

```php
            data-edit-url="<?php echo $escape($item->url('edit')); ?>"
```

En `asset/js/config.js`, añadir la clave nueva al objeto que devuelve `readConfig()`, junto a `recatalogLastEventUrl`:

```js
        drawerDetailsUrl: d.drawerDetailsUrl,
```

- [ ] **Step 2: Crear el módulo de UI**

Crear `asset/js/ui/drawerDetails.js`:

```js
import { DRAWER_RENDERED } from './drawer.js';
import { integrityGroups, INTEGRITY_OK_TEXT } from '../core/integrityModel.js';
import { historyRows, HISTORY_EMPTY_NOTICE } from '../core/historyModel.js';

/**
 * Secciones del drawer que el cliente no puede calcular: integridad e historial
 * (rebanada 3a de TASK-028).
 *
 * Se engancha a `oer:drawer-rendered` en vez de estar cableado dentro de
 * drawer.js, igual que el panel del re-catalogador: así el drawer no depende de
 * estas secciones y se pueden mover sin romperlo.
 *
 * Todo el texto se escribe con textContent: los títulos y los mensajes vienen
 * del catálogo, nunca del código.
 */

function heading(text) {
  const element = document.createElement('h4');
  element.textContent = text;
  return element;
}

function note(text, className) {
  const element = document.createElement('p');
  element.className = className;
  element.textContent = text;
  return element;
}

function renderIntegrity(integrity) {
  const section = document.createElement('section');
  section.className = 'oer-drawer-section oer-drawer-integrity';
  section.appendChild(heading(Omeka.jsTranslate('Integridad')));

  const groups = integrityGroups(integrity);
  if (!groups.length) {
    section.appendChild(note(Omeka.jsTranslate(INTEGRITY_OK_TEXT), 'oer-drawer-empty'));
    return section;
  }

  groups.forEach((group) => {
    const list = document.createElement('ul');
    list.className = `oer-integrity-issues oer-integrity-issues-${group.severity}`;
    group.issues.forEach((issue) => {
      const item = document.createElement('li');
      item.textContent = issue.message;
      list.appendChild(item);
    });
    section.appendChild(list);
  });

  return section;
}

function renderChange(change) {
  const block = document.createElement('div');
  block.className = 'oer-history-change';

  const label = document.createElement('span');
  label.className = 'oer-history-term';
  label.textContent = change.emptied
    ? `${change.label} (${Omeka.jsTranslate('vaciada')})`
    : change.label;
  block.appendChild(label);

  (change.added || []).forEach((title) => {
    const line = document.createElement('span');
    line.className = 'oer-history-added';
    line.textContent = `+ ${title}`;
    block.appendChild(line);
  });

  (change.removed || []).forEach((value) => {
    const line = document.createElement('span');
    line.className = 'oer-history-removed';
    line.textContent = `− ${value.title}`;
    block.appendChild(line);

    // PEND-013: la justificación de la IA se muestra, pero COLAPSADA. Aquí ya
    // está decidido y escrito, así que no ancla la decisión del curador; y
    // regenerarla costaría otra pasada de LLM.
    if (value.reason) {
      const why = document.createElement('details');
      why.className = 'oer-history-why';
      const toggle = document.createElement('summary');
      toggle.textContent = Omeka.jsTranslate('Ver porqué');
      why.appendChild(toggle);
      const reason = document.createElement('p');
      reason.textContent = value.reason;
      why.appendChild(reason);
      block.appendChild(why);
    }
  });

  return block;
}

function renderHistory(history) {
  const section = document.createElement('section');
  section.className = 'oer-drawer-section oer-drawer-history';
  section.appendChild(heading(Omeka.jsTranslate('Historial de curación')));

  const rows = historyRows(history);
  if (!rows.length) {
    section.appendChild(note(Omeka.jsTranslate(HISTORY_EMPTY_NOTICE), 'oer-drawer-empty'));
    return section;
  }

  rows.forEach((row) => {
    const entry = document.createElement('article');
    entry.className = row.isUndo ? 'oer-history-entry oer-history-undo' : 'oer-history-entry';

    const header = document.createElement('p');
    header.className = 'oer-history-header';
    header.textContent = `${row.when} · ${row.contributor}`;
    entry.appendChild(header);

    const summary = document.createElement('p');
    summary.className = 'oer-history-summary';
    summary.textContent = row.summary;
    entry.appendChild(summary);

    row.changes.forEach((change) => entry.appendChild(renderChange(change)));
    section.appendChild(entry);
  });

  return section;
}

export function initDrawerDetails(config) {
  document.addEventListener(DRAWER_RENDERED, (event) => {
    const { itemId, content } = event.detail;
    const url = config.drawerDetailsUrl;
    if (!url) {
      return;
    }

    const placeholder = document.createElement('div');
    placeholder.className = 'oer-drawer-details';
    placeholder.textContent = Omeka.jsTranslate('Cargando detalle…');
    content.appendChild(placeholder);

    fetch(`${url}?id=${encodeURIComponent(itemId)}`, { headers: { Accept: 'application/json' } })
      .then((response) => response.json())
      .then((details) => {
        placeholder.textContent = '';
        placeholder.appendChild(renderIntegrity(details.integrity));
        placeholder.appendChild(renderHistory(details.history));
      })
      .catch(() => {
        placeholder.textContent = Omeka.jsTranslate('No se ha podido cargar el detalle ampliado.');
      });
  });
}
```

- [ ] **Step 3: Añadir el enlace al item nativo y la lista de medios**

En `asset/js/ui/drawer.js`, dentro de `buildContent(itemJson)`, justo antes de `return content;`:

```js
    // Salida al editor nativo de Omeka: hoy no hay ninguna desde el drawer
    // (TASK-027 §5.1, imprescindible). La URL la genera el servidor y viaja en
    // la fila: componerla en JS rompería en instalaciones bajo subdirectorio.
    const row = document.querySelector(`tr[data-resource-id="${itemJson['o:id']}"]`);
    const editUrl = row ? row.dataset.editUrl : '';
    if (editUrl) {
        const editLink = document.createElement('a');
        editLink.className = 'oer-drawer-edit-link';
        editLink.href = editUrl;
        editLink.textContent = Omeka.jsTranslate('Abrir en el editor de Omeka');
        content.appendChild(editLink);
    }

    // Medios: un REA sin ningún medio no es un recurso (afecta a #40442).
    const media = itemJson['o:media'] || [];
    const mediaSection = document.createElement('p');
    mediaSection.className = media.length ? 'oer-drawer-media' : 'oer-drawer-media oer-drawer-media-none';
    mediaSection.textContent = media.length
        ? `${Omeka.jsTranslate('Medios')}: ${media.length}`
        : Omeka.jsTranslate('Este REA no tiene ningún medio.');
    content.appendChild(mediaSection);
```

- [ ] **Step 4: Arrancar el módulo**

En `asset/js/main.js`, importar `initDrawerDetails` y llamarla dentro del `if (config)` junto a las demás, **pasándole `config`** como hacen todas salvo `initSearchForm()`:

```js
import { initDrawerDetails } from './ui/drawerDetails.js';
```

```js
    initDrawerDetails(config);
```

- [ ] **Step 5: Estilos**

Añadir al final de `asset/css/oer-master-view.css`:

```css
/* --- Secciones de lectura del drawer (TASK-028 rebanada 3a) --- */

.oer-drawer-section {
    margin-top: 1.25em;
    padding-top: 0.75em;
    border-top: 1px solid var(--oer-rule);
}

.oer-drawer-empty {
    color: var(--oer-muted);
    font-style: italic;
}

.oer-integrity-issues {
    margin: 0;
    padding-left: 1.2em;
}

/* La severidad se distingue por FORMA además de por color (ADR-0014 regla 3):
   el glifo lo pone el marcador de lista, no solo la tinta. */
.oer-integrity-issues-error > li {
    list-style-type: "⛔ ";
    color: var(--oer-bad);
}

.oer-integrity-issues-warning > li {
    list-style-type: "⚠ ";
    color: var(--oer-warn);
}

.oer-history-entry {
    margin-bottom: 1em;
}

.oer-history-header {
    color: var(--oer-muted);
    font-size: 0.9em;
    margin: 0;
}

.oer-history-summary {
    margin: 0.15em 0;
}

.oer-history-change {
    margin-left: 1em;
}

.oer-history-term {
    display: block;
    font-size: 0.9em;
    color: var(--oer-muted);
}

.oer-history-added,
.oer-history-removed {
    display: block;
}

.oer-history-removed {
    color: var(--oer-muted);
}

.oer-history-why summary {
    cursor: pointer;
    font-size: 0.9em;
    color: var(--oer-muted);
}
```

- [ ] **Step 6: Verificar**

Run: `make lint && make test && make test-js`
Expected: PASS los tres.

Run: `php -l view/oer-manager/admin/index/index.phtml`
Expected: sin errores.

Run: `node --check asset/js/ui/drawerDetails.js`
Expected: sin errores.

- [ ] **Step 7: Commit**

```bash
git add asset/js/ui/drawerDetails.js asset/js/ui/drawer.js asset/js/main.js \
        view/oer-manager/admin/index/index.phtml asset/css/oer-master-view.css
git commit -m "feat(drawer): pinta integridad, historial, enlace nativo y medios"
```

---

## Task 7: Arnés de contenedor

**Files:**
- Create: `test/container/drawer-details-check.php`

**Interfaces:**
- Consumes: todo lo anterior.
- Produces: ejecutable de CLI, sale 0 si todo pasa y 1 si no.

**Este arnés SÍ escribe, y es la única excepción del plan.** No hay ningún evento en el catálogo (0 en 19 REA), así que para comprobar el historial hay que fabricar uno. Sigue el patrón de `test/container/undo-harness.php`: escribe, comprueba y **restaura el estado previo**. Antes de escribir nada, guarda el estado del item; al terminar, lo devuelve. Si el arnés falla a mitad, debe dejar dicho en la salida qué item quedó tocado.

- [ ] **Step 1: Leer el arnés que ya hace esto**

Run: `sed -n '1,60p' test/container/undo-harness.php`

Fíjate en cómo obtiene los servicios, cómo captura el estado previo y cómo lo restaura al final. **Reutiliza ese patrón**; no inventes otro.

- [ ] **Step 2: Escribir el arnés**

Crear `test/container/drawer-details-check.php` siguiendo esta estructura, con el patrón de `check()`/`skip()` de `columns-check.php`:

```php
<?php

/**
 * Arnés de las superficies de lectura del drawer (TASK-028 rebanada 3a).
 *
 * ESCRIBE Y RESTAURA: es la única excepción de la rebanada. El catálogo no
 * tiene ningún evento de curación (0 en 19 REA), así que para comprobar el
 * historial hay que fabricar uno. Mismo patrón que undo-harness.php: se captura
 * el estado previo, se escribe, se comprueba y se restaura.
 *
 * Uso, desde el contenedor:
 *   php /var/www/html/modules/OERManager/test/container/drawer-details-check.php
 */

require '/var/www/html/bootstrap.php';

$application = Omeka\Mvc\Application::init(require '/var/www/html/application/config/application.config.php');
$services = $application->getServiceManager();
$api = $services->get('Omeka\ApiManager');
$recatalog = $services->get(OERManager\Service\RecatalogService::class);
$checker = $services->get(OERManager\Service\IntegrityChecker::class);
```

El arnés debe comprobar, en este orden:

1. **Sobre un REA sin eventos:** `RecatalogService::history()` devuelve `[]`. Esta es la comprobación que confirma E-2 del spec, y hoy es la que vale para los 19 REA.
2. **La integridad que devuelve el servicio coincide con la que mide `columns-check.php`** para el mismo item, con enlaces encendidos.
3. **Fabricando un evento** con `apply()` sobre un item de pruebas: `history()` devuelve una fila, con `contributor` y `summary` no vacíos, y los cambios resueltos a **título**, no a id — que es lo que justifica el coste de `titlesFor()`.
4. **El caso que motiva toda la decisión E-1:** vaciar una dimensión por completo y comprobar que el historial **sí** registra los valores retirados. Es lo que una value annotation no puede representar.
5. **Restauración:** al terminar, el item vuelve a su estado previo y `history()` refleja también esa reversión (deshacer es rehacer, así que deja su propio evento — decláralo en la salida en vez de fingir que el item queda idéntico).

- [ ] **Step 3: Ejecutar**

Run:
```bash
docker exec omeka-s-moduletemplate-omekas-1 \
  php /var/www/html/modules/OERManager/test/container/drawer-details-check.php
```
Expected: todas las comprobaciones en OK y salida 0.

**Si el arnés deja el item tocado**, arréglalo antes de commitear: un arnés que ensucia el catálogo del propietario es peor que no tenerlo.

- [ ] **Step 4: Confirmar que no rompe los arneses hermanos**

Run:
```bash
docker exec omeka-s-moduletemplate-omekas-1 php /var/www/html/modules/OERManager/test/container/columns-check.php
docker exec omeka-s-moduletemplate-omekas-1 php /var/www/html/modules/OERManager/test/container/search-filters-check.php
```
Expected: `8 OK, 0 FAIL, 1 SKIP` y `8 OK, 0 FAIL`.

- [ ] **Step 5: Commit**

```bash
git add test/container/drawer-details-check.php
git commit -m "test(contenedor): arnes de las superficies de lectura del drawer"
```

---

## Task 8: Cerrar el gobierno

**Files:**
- Modify: `docs/requirements.md` (PEND-013 resuelto)
- Modify: `docs/backlog.md`, `docs/project-memory.md`, `docs/traceability.md`

- [ ] **Step 1: Marcar PEND-013 como resuelto**

En `docs/requirements.md`, en la fila de **PEND-013**, cambiar el estado a `Resuelto` y añadir al final de la celda de detalle:

> **Resuelto (2026-08-11, TASK-028 rebanada 3a):** la justificación **se muestra en el historial de lo ya confirmado, tras un «ver porqué» colapsado**. Mantiene el principio de TASK-023 —no anclar al curador *antes* de decidir— porque aquí ya está decidido y escrito, y no tira un dato cuya regeneración costaría otra pasada de LLM.

- [ ] **Step 2: Actualizar backlog, memoria y trazabilidad**

En `docs/backlog.md`, fila de TASK-028: estado a `rebanadas 1, 2 y 3a **hechas**; 3b y 4 pendientes de spec`, con el resumen de lo entregado, los números de las suites y lo declarado como no verificado.

En `docs/project-memory.md`, entrada nueva en «Estado actual». **Incluye el dato que gobierna la rebanada:** 0 eventos frente a 386 anotaciones, y que por eso el historial nace vacío y lo declara en pantalla.

En `docs/traceability.md`, enlazar RF-008 (auditoría) y RF-002 con la rebanada 3a.

- [ ] **Step 3: Verificación final**

Run: `make lint && make test && make test-js`
Expected: PASS los tres.

- [ ] **Step 4: Commit**

```bash
git add docs/
git commit -m "docs: rebanada 3a de TASK-028 cerrada, PEND-013 resuelto"
```

---

## Autorrevisión del plan

**Cobertura del spec.** §3 E-1 → Tasks 1-2 · E-2 → Task 5 (`HISTORY_EMPTY_NOTICE`) y Task 7 punto 1 · E-3 → Task 6 (`<details>` de «ver porqué») y Task 8 · E-4 → Task 3 · E-5 → no requiere tarea: es una exclusión, y la ausencia de la ficha destilada en el drawer es su implementación. §7 alcance: issues (Tasks 3, 4, 6) · historial (1, 2, 5, 6) · enlace nativo (Task 6 step 3) · medios (Task 6 step 3). §8 arquitectura → estructura de ficheros. §10 verificación → Tasks 1, 4, 5 (host), 7 (contenedor); el navegador queda para el propietario, como declara el spec. §11 gobierno → Task 8.

**Placeholders.** Task 7 describe las cinco comprobaciones en prosa en vez de darlas en código, a propósito: el arnés debe calcarse de `undo-harness.php`, que ya resuelve la parte frágil (bootstrap, captura y restauración del estado), y transcribir aquí una versión inventada llevaría al implementador a divergir de un patrón probado. El paso 1 le obliga a leerlo antes.

**Consistencia de tipos.** `CurationHistory::rows(array $events, array $titles): array` y `referencedIds(array $events): array` se usan con esa firma en Task 2. Las claves del modelo (`when`, `contributor`, `summary`, `isUndo`, `changes`, y dentro `term`, `added`, `removed`, `emptied`) son idénticas en Tasks 1, 5 y 6. `removed` es siempre `{title, reason}`, nunca una cadena suelta. `integrityGroups` devuelve `{severity, issues}` en Tasks 4 y 6. `TERM_LABELS` se define en Task 5 step 1 y se consume en el mismo fichero de Task 5.

**Riesgo conocido:** Task 2 toca el desempate de `lastEventOf()`, del que depende el **deshacer**. Si se rompe, el undo restaura el estado equivocado y ningún test de host lo detecta —esa clase no se puede instanciar fuera del contenedor—. Por eso Task 7 punto 5 ejercita el ciclo completo escribir → leer → restaurar.
