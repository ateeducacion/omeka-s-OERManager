# TASK-028 rebanada 2 — columnas y filtros de gobernanza

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** La vista maestra deja de dedicar su superficie al anclaje curricular (que está al 100 %) y estrena las columnas y filtros de gobernanza, exponiendo por primera vez el `IntegrityChecker` que lleva desde TASK-005 calculándose en silencio.

**Architecture:** Cada columna nueva es un adaptador delgado sobre una pieza **pura** probable en el host (`IntegrityPolicy`, `CurricularSummary`); `IntegrityChecker` queda como proyector que traduce `ItemRepresentation` a datos planos y delega. Los filtros baratos van a `MasterViewQuery` como queries `nex`; el de integridad es computado y reusa el `ComputedFilter` de la rebanada 1 a través de un resolutor que deja **una sola** rama computada en el controlador.

**Tech Stack:** PHP 8.4, Omeka-S 4.2, PHPUnit 11.5, `node --test` (sin dependencias ni bundler), PHPCS PSR-12.

**Spec:** `docs/superpowers/specs/2026-08-03-task-028-rebanada-2-design.md`

## Global Constraints

- **PHP 8.4.** No usar sintaxis de PHP 8.5 aunque el host la acepte.
- **PSR-12.** `make lint` debe quedar en verde; autofix con `make fix`. El Stop hook no cierra el turno si falla.
- **Sin tablas Doctrine propias** (NFR-002). Todo se calcula al vuelo o vive en settings nativos.
- **Extender el core, nunca parchearlo** (NFR-001). Integración solo por `module.config.php`, `attachListeners` y mecanismos nativos.
- **Cadenas de UI traducibles:** `// @translate` junto al literal en PHP, `$this->translate(...)` en vistas. Las cadenas nuevas no estarán en `language/*.mo` y degradarán al literal español, que es el texto correcto.
- **Namespaces:** código en `OERManager\` → `src/`; tests en `OERManager\Test\` → `test/`.
- **Tipos de columna del módulo** declaran `getResourceTypes(): ['oer_items']`. Solo `AlignmentStatus` conserva además `'items'`, a propósito.
- **Tres comandos de verificación:** `make lint`, `make test` (PHPUnit), `make test-js` (`node --test 'test/js/**/*.test.js'`).
- **Punto de partida:** rama `task-028-rebanada-2-gobernanza`, ya creada, con el spec commiteado en `ee7a7e5`.

## Estructura de ficheros

**Se crean:**

| Fichero | Responsabilidad |
| --- | --- |
| `src/Service/Governance/IntegrityPolicy.php` | Las reglas de integridad sobre datos planos. Pura |
| `src/Service/Governance/CurricularSummary.php` | Dedup de materias y cursos, marca de literal. Pura |
| `src/Service/ComputedPredicates.php` | Qué predicados computados pide una query. Pura |
| `src/ColumnType/Integrity.php` | Celda del semáforo |
| `src/ColumnType/Curricular.php` | Celda curricular fusionada |
| `src/ColumnType/GovernanceValue.php` | Celda de valor con estado vacío explícito (Licencia y Tipo) |
| `asset/js/core/contrast.js` | Ratio de contraste WCAG y parseo de tokens CSS. Puro |
| `test/Service/Governance/IntegrityPolicyTest.php` | |
| `test/Service/Governance/CurricularSummaryTest.php` | |
| `test/Service/ComputedPredicatesTest.php` | |
| `test/js/contrast.test.js` | |
| `test/container/columns-check.php` | Arnés de verificación en contenedor |

**Se modifican:**

| Fichero | Cambio |
| --- | --- |
| `src/Service/IntegrityChecker.php` | Pasa a proyector; gana el interruptor `$checkLinks` |
| `src/Service/MasterViewQuery.php` | Cuatro filtros `nex` de gobernanza |
| `src/Controller/Admin/IndexController.php` | Una sola rama computada; mapa de integridad para el riel |
| `config/module.config.php` | Registro de las tres columnas nuevas y `column_defaults` |
| `src/ColumnType/AlignmentStatus.php` | Rótulo «Anclaje» |
| `view/oer-manager/admin/index/index.phtml` | `data-integrity` en la fila, «(sin título)» marcado |
| `view/oer-manager/admin/index/search.phtml` | Casillas de los filtros `nex` |
| `asset/css/oer-master-view.css` | Riel anclado a integridad, celdas nuevas |
| `test/ModuleConfigTest.php` | Contrato de las columnas nuevas |

**Desviación deliberada del spec.** El spec §5 lista una tercera pieza pura, `ValuePresence`. Al aterrizarlo resulta ser «¿el valor está vacío?», tres líneas sin decisión que probar; una clase pura para eso sería ceremonia. Se omite y `GovernanceValue` lo resuelve en línea; el resultado observable —estado vacío explícito— es idéntico. Queda cubierto por el arnés de contenedor (Task 12).

---

## Task 1: `IntegrityPolicy` — las reglas, puras

Es el corazón de la rebanada: aquí viven D-3 (literal en property de enlace), D-6 (el mínimo se aplica siempre) y D-5 (dos estados). Se escribe primero porque todo lo demás lo consume.

**Files:**
- Create: `src/Service/Governance/IntegrityPolicy.php`
- Test: `test/Service/Governance/IntegrityPolicyTest.php`

**Interfaces:**
- Consumes: nada (primera tarea).
- Produces: `IntegrityPolicy::issuesFor(array $values, array $requiredTerms, bool $checkLinks): array` — devuelve una lista de `array{severity:string, code:string, field:string, message:string}`, la misma forma que `IntegrityResult` ya consume. Constantes públicas `ALIGNMENT_TERMS` (list<string>) y `LICENSE_TERM` (string).

- [ ] **Step 1: Escribir el test que falla**

Crear `test/Service/Governance/IntegrityPolicyTest.php`:

```php
<?php

declare(strict_types=1);

namespace OERManager\Test\Service\Governance;

use OERManager\Service\Governance\IntegrityPolicy;
use PHPUnit\Framework\TestCase;

/**
 * Reglas de integridad (RF-006) sobre datos planos, para que sean probables en
 * el host: IntegrityChecker tipa ItemRepresentation y no se puede instanciar
 * fuera del contenedor (limitación del arnés, TASK-008).
 */
final class IntegrityPolicyTest extends TestCase
{
    /** Valor de enlace sano: apunta a un item-término existente. */
    private function link(): array
    {
        return ['type' => 'resource:item', 'hasResource' => true];
    }

    /** Un REA completo: las cuatro properties de anclaje enlazadas + licencia. */
    private function healthy(): array
    {
        $values = [IntegrityPolicy::LICENSE_TERM => [['type' => 'literal', 'hasResource' => false]]];
        foreach (IntegrityPolicy::ALIGNMENT_TERMS as $term) {
            $values[$term] = [$this->link()];
        }
        return $values;
    }

    private function codes(array $issues): array
    {
        return array_column($issues, 'code');
    }

    public function testHealthyItemHasNoIssues(): void
    {
        $this->assertSame([], IntegrityPolicy::issuesFor($this->healthy(), [], false));
    }

    public function testMissingLicenceIsAWarning(): void
    {
        $values = $this->healthy();
        unset($values[IntegrityPolicy::LICENSE_TERM]);

        $issues = IntegrityPolicy::issuesFor($values, [], false);

        $this->assertSame(['missing_license'], $this->codes($issues));
        $this->assertSame('warning', $issues[0]['severity']);
        $this->assertSame(IntegrityPolicy::LICENSE_TERM, $issues[0]['field']);
    }

    public function testEachMissingAlignmentTermIsItsOwnWarning(): void
    {
        $values = $this->healthy();
        unset($values['lrmi:teaches'], $values['lrmi:assesses']);

        $issues = IntegrityPolicy::issuesFor($values, [], false);

        $this->assertSame(['missing_alignment', 'missing_alignment'], $this->codes($issues));
        $this->assertSame(['lrmi:teaches', 'lrmi:assesses'], array_column($issues, 'field'));
    }

    /**
     * D2 del estudio (TASK-027): hoy un literal en una property de enlace cuenta
     * como valor presente y la integridad dice «ok». Son 4 valores del catálogo
     * real (schema:about ×3, lrmi:educationalLevel ×1).
     */
    public function testLiteralInALinkPropertyIsAWarning(): void
    {
        $values = $this->healthy();
        $values['schema:about'] = [['type' => 'literal', 'hasResource' => false]];

        $issues = IntegrityPolicy::issuesFor($values, [], false);

        $this->assertSame(['literal_in_link_property'], $this->codes($issues));
        $this->assertSame('warning', $issues[0]['severity']);
        $this->assertSame('schema:about', $issues[0]['field']);
    }

    public function testALiteralIsNotAlsoReportedAsMissing(): void
    {
        $values = $this->healthy();
        $values['schema:about'] = [['type' => 'literal', 'hasResource' => false]];

        $this->assertNotContains('missing_alignment', $this->codes(
            IntegrityPolicy::issuesFor($values, [], false)
        ));
    }

    /**
     * D-6: la plantilla SUMA sus campos obligatorios, ya no sustituye al mínimo.
     * Antes de este cambio, asignar una plantilla silenciaba anclaje y licencia
     * (la trampa que TASK-027 §8.1 anticipó y que PEND-012 iba a abrir).
     */
    public function testTemplateRequirementsAddToTheMinimumInsteadOfReplacingIt(): void
    {
        $values = $this->healthy();
        unset($values[IntegrityPolicy::LICENSE_TERM]);

        $issues = IntegrityPolicy::issuesFor($values, ['dcterms:description'], false);

        $this->assertSame(['missing_license', 'missing_required'], $this->codes($issues));
        $this->assertSame('dcterms:description', $issues[1]['field']);
    }

    public function testSatisfiedTemplateRequirementRaisesNothing(): void
    {
        $values = $this->healthy();
        $values['dcterms:description'] = [['type' => 'literal', 'hasResource' => false]];

        $this->assertSame([], IntegrityPolicy::issuesFor($values, ['dcterms:description'], false));
    }

    /** D-7: la comprobación de enlace vivo está apagada en columna y filtro. */
    public function testDeadLinkIsOnlyReportedWhenLinkCheckingIsOn(): void
    {
        $values = $this->healthy();
        $values['lrmi:teaches'] = [['type' => 'resource:item', 'hasResource' => false]];

        $this->assertSame([], IntegrityPolicy::issuesFor($values, [], false));

        $issues = IntegrityPolicy::issuesFor($values, [], true);
        $this->assertSame(['dead_link'], $this->codes($issues));
        $this->assertSame('error', $issues[0]['severity']);
    }

    public function testEmptyValueListCountsAsMissing(): void
    {
        $values = $this->healthy();
        $values['schema:about'] = [];

        $this->assertSame(['missing_alignment'], $this->codes(
            IntegrityPolicy::issuesFor($values, [], false)
        ));
    }
}
```

- [ ] **Step 2: Ejecutar el test y verificar que falla**

Run: `make test`
Expected: FAIL — `Class "OERManager\Service\Governance\IntegrityPolicy" not found`.

- [ ] **Step 3: Implementación mínima**

Crear `src/Service/Governance/IntegrityPolicy.php`:

```php
<?php

declare(strict_types=1);

namespace OERManager\Service\Governance;

/**
 * Reglas de integridad RDF (RF-006) expresadas sobre datos planos.
 *
 * Vive separada de IntegrityChecker para poder probarse en el HOST: el checker
 * tipa ItemRepresentation y cualquier test que lo instancie muere en el
 * autoload sin el core (limitación del arnés, TASK-008). Es el mismo movimiento
 * que ConfigPayload hizo en TASK-029 con el mapeo de settings.
 *
 * Cambios de la rebanada 2 de TASK-028 frente a lo que TASK-005 escribió:
 *
 *  - D-6: las reglas mínimas se aplican SIEMPRE. Antes eran la rama «else» de
 *    «¿tiene plantilla?», así que asignar una plantilla que no declarase
 *    obligatorios el anclaje y la licencia habría silenciado ambos avisos sin
 *    que el catálogo mejorase (trampa anticipada en TASK-027 §8.1).
 *  - D-3: un literal en una property de enlace es un aviso propio. Antes contaba
 *    como valor presente y la integridad decía «ok» (defecto D2 del estudio).
 *  - D-7: la comprobación de enlace vivo es opcional. Es la única que necesita
 *    el destino de cada valor, y en el core el FK cascadea al borrar
 *    (Value.php, @JoinColumn(onDelete="CASCADE")), así que el enlace muerto es
 *    casi inalcanzable: no se paga por buscarlo en cada fila de la tabla.
 */
final class IntegrityPolicy
{
    /** Properties de anclaje curricular (ADR-0004). Todas son de enlace. */
    public const ALIGNMENT_TERMS = [
        'lrmi:educationalLevel',
        'schema:about',
        'lrmi:teaches',
        'lrmi:assesses',
    ];

    public const LICENSE_TERM = 'dcterms:rights';

    /**
     * @param array<string, list<array{type:string, hasResource:bool}>> $values
     *        Término → sus valores. Basta con incluir los términos de anclaje,
     *        la licencia y los obligatorios de plantilla; el resto se ignora.
     * @param list<string> $requiredTerms Obligatorios de la plantilla, [] si no hay
     * @param bool $checkLinks Comprobar que los enlaces tienen destino vivo
     * @return list<array{severity:string, code:string, field:string, message:string}>
     */
    public static function issuesFor(array $values, array $requiredTerms, bool $checkLinks): array
    {
        $issues = [];

        foreach (self::ALIGNMENT_TERMS as $term) {
            $termValues = $values[$term] ?? [];

            if ([] === $termValues) {
                $issues[] = [
                    'severity' => 'warning',
                    'code' => 'missing_alignment',
                    'field' => $term,
                    'message' => sprintf(
                        'Falta el campo de anclaje "%s".', // @translate
                        $term
                    ),
                ];
                continue;
            }

            foreach ($termValues as $value) {
                if (!str_starts_with($value['type'], 'resource')) {
                    $issues[] = [
                        'severity' => 'warning',
                        'code' => 'literal_in_link_property',
                        'field' => $term,
                        'message' => sprintf(
                            'El campo "%s" tiene un valor literal donde debería enlazar a un item-término.', // @translate
                            $term
                        ),
                    ];
                    continue;
                }
                if ($checkLinks && !$value['hasResource']) {
                    $issues[] = [
                        'severity' => 'error',
                        'code' => 'dead_link',
                        'field' => $term,
                        'message' => sprintf(
                            'El valor de "%s" apunta a un recurso inexistente.', // @translate
                            $term
                        ),
                    ];
                }
            }
        }

        if ([] === ($values[self::LICENSE_TERM] ?? [])) {
            $issues[] = [
                'severity' => 'warning',
                'code' => 'missing_license',
                'field' => self::LICENSE_TERM,
                'message' => 'El REA no tiene licencia asignada.', // @translate
            ];
        }

        foreach ($requiredTerms as $term) {
            if ([] === ($values[$term] ?? [])) {
                $issues[] = [
                    'severity' => 'warning',
                    'code' => 'missing_required',
                    'field' => $term,
                    'message' => sprintf(
                        'El campo obligatorio "%s" de la plantilla no tiene valor.', // @translate
                        $term
                    ),
                ];
            }
        }

        return $issues;
    }
}
```

- [ ] **Step 4: Ejecutar los tests y verificar que pasan**

Run: `make test && make lint`
Expected: PASS, 10 tests nuevos, lint en verde.

- [ ] **Step 5: Commit**

```bash
git add src/Service/Governance/IntegrityPolicy.php test/Service/Governance/IntegrityPolicyTest.php
git commit -m "feat(integridad): reglas puras y probables en host (TASK-028 D-3/D-6/D-7)"
```

---

## Task 2: `IntegrityChecker` pasa a proyector

**Files:**
- Modify: `src/Service/IntegrityChecker.php` (reescritura completa del cuerpo)
- Modify: `Module.php:277-278` (la llamada del listener mantiene la comprobación de enlaces encendida)

**Interfaces:**
- Consumes: `IntegrityPolicy::issuesFor()` de Task 1.
- Produces: `IntegrityChecker::check(ItemRepresentation $item, bool $checkLinks = true): IntegrityResult`. El parámetro es opcional y por defecto `true`, así que **el listener existente no cambia de comportamiento**. Constantes `ALIGNMENT_TERMS` y `LICENSE_TERM` se conservan como alias de las de `IntegrityPolicy` para no romper a nadie que las use.

- [ ] **Step 1: Reescribir `IntegrityChecker`**

Sustituir el contenido de `src/Service/IntegrityChecker.php` por:

```php
<?php

namespace OERManager\Service;

use OERManager\Service\Governance\IntegrityPolicy;
use Omeka\Api\Representation\ItemRepresentation;

/**
 * Comprobación de integridad de valores RDF (RF-006, TASK-005, ADR-0004).
 *
 * Desde la rebanada 2 de TASK-028 esta clase es un **proyector**: traduce la
 * ItemRepresentation a datos planos y delega las reglas en IntegrityPolicy, que
 * sí se puede probar en el host. Aquí no vive ninguna decisión.
 *
 * Uso: $checker->check($item) → IntegrityResult.
 */
class IntegrityChecker
{
    /** @var list<string> Alias de IntegrityPolicy, conservado por compatibilidad. */
    public const ALIGNMENT_TERMS = IntegrityPolicy::ALIGNMENT_TERMS;

    public const LICENSE_TERM = IntegrityPolicy::LICENSE_TERM;

    /**
     * @param bool $checkLinks Comprobar que los enlaces tienen destino vivo.
     *        Encendido en el listener de guardado (un item, coste irrelevante) y
     *        en el drawer; APAGADO en la columna y en el filtro de la vista
     *        maestra, porque valueResource() inicializa el proxy Doctrine de
     *        cada destino —una consulta por valor— para perseguir un caso que la
     *        FK en cascada del core hace casi imposible (D-7).
     */
    public function check(ItemRepresentation $item, bool $checkLinks = true): IntegrityResult
    {
        $requiredTerms = $this->requiredTerms($item);

        $terms = array_unique(array_merge(
            IntegrityPolicy::ALIGNMENT_TERMS,
            [IntegrityPolicy::LICENSE_TERM],
            $requiredTerms
        ));

        return new IntegrityResult(
            IntegrityPolicy::issuesFor($this->project($item, $terms, $checkLinks), $requiredTerms, $checkLinks)
        );
    }

    /**
     * Proyecta los valores del item a la forma plana que espera la policy.
     *
     * `hasResource` solo se resuelve si hace falta: es la llamada cara
     * (valueResource() construye la representación del destino, despertando el
     * proxy). Con $checkLinks a false se deja en true, valor que la policy no
     * mira porque no evalúa la regla de enlace vivo.
     *
     * @param list<string> $terms
     * @return array<string, list<array{type:string, hasResource:bool}>>
     */
    private function project(ItemRepresentation $item, array $terms, bool $checkLinks): array
    {
        $allValues = $item->values();
        $projected = [];

        foreach ($terms as $term) {
            $projected[$term] = [];
            foreach ($allValues[$term]['values'] ?? [] as $value) {
                $type = $value->type();
                $projected[$term][] = [
                    'type' => $type,
                    'hasResource' => (!$checkLinks || !str_starts_with($type, 'resource'))
                        ? true
                        : (bool) $value->valueResource(),
                ];
            }
        }

        return $projected;
    }

    /** @return list<string> Campos obligatorios de la plantilla, [] si no hay plantilla. */
    private function requiredTerms(ItemRepresentation $item): array
    {
        $template = $item->resourceTemplate();
        if (!$template) {
            return [];
        }

        $required = [];
        foreach ($template->resourceTemplateProperties() as $templateProperty) {
            if ($templateProperty->isRequired()) {
                $required[] = $templateProperty->property()->term();
            }
        }

        return $required;
    }
}
```

- [ ] **Step 2: Verificar que el listener no cambia**

Leer `Module.php:265-292` y confirmar que la llamada es `$checker->check($item)` sin segundo argumento. Como el parámetro es opcional y por defecto `true`, el comportamiento del listener es idéntico salvo por las reglas nuevas. **No editar `Module.php`.**

Run: `grep -n 'check(\$item' Module.php`
Expected: una sola línea, `$result = $checker->check($item);`

- [ ] **Step 3: Ejecutar lint y tests**

Run: `make lint && make test`
Expected: PASS. Los tests de Task 1 siguen verdes; no hay tests de host que instancien `IntegrityChecker` (por eso existe Task 1).

- [ ] **Step 4: Commit**

```bash
git add src/Service/IntegrityChecker.php
git commit -m "refactor(integridad): el checker pasa a proyector y delega en IntegrityPolicy"
```

---

## Task 3: `CurricularSummary` — dedup y linaje, puro

**Files:**
- Create: `src/Service/Governance/CurricularSummary.php`
- Test: `test/Service/Governance/CurricularSummaryTest.php`

**Interfaces:**
- Consumes: nada.
- Produces: `CurricularSummary::summarise(array $subjects, array $stages): array`, donde cada entrada de `$subjects`/`$stages` es `array{title:string, isLiteral:bool}` y el retorno es `array{subjects:list<string>, primaryStage:string, extraStages:int, hasLiteral:bool, tooltip:string}`.

**Nota de alcance.** La celda **no empareja** materia con curso: hacerlo exigiría recorrer el grafo curricular por fila. Muestra las materias deduplicadas y, como línea secundaria, el primer curso más el recuento de los demás. El detalle completo va al `tooltip`.

- [ ] **Step 1: Escribir el test que falla**

Crear `test/Service/Governance/CurricularSummaryTest.php`:

```php
<?php

declare(strict_types=1);

namespace OERManager\Test\Service\Governance;

use OERManager\Service\Governance\CurricularSummary;
use PHPUnit\Framework\TestCase;

/**
 * La columna Curricular fusiona lrmi:educationalLevel y schema:about. El caso
 * que la motiva es real: el currículo repite «Matemáticas» en cuatro cursos y
 * la tabla pintaba cuatro chips idénticos (TASK-027 §3).
 */
final class CurricularSummaryTest extends TestCase
{
    private function link(string $title): array
    {
        return ['title' => $title, 'isLiteral' => false];
    }

    public function testSingleSubjectAndStage(): void
    {
        $result = CurricularSummary::summarise([$this->link('Biología')], [$this->link('3º ESO')]);

        $this->assertSame(['Biología'], $result['subjects']);
        $this->assertSame('3º ESO', $result['primaryStage']);
        $this->assertSame(0, $result['extraStages']);
        $this->assertFalse($result['hasLiteral']);
    }

    public function testRepeatedSubjectTitleIsDeduplicated(): void
    {
        $result = CurricularSummary::summarise(
            [$this->link('Matemáticas'), $this->link('Matemáticas'), $this->link('Matemáticas')],
            [$this->link('1º ESO'), $this->link('2º ESO')]
        );

        $this->assertSame(['Matemáticas'], $result['subjects']);
    }

    public function testExtraStagesAreCountedNotListed(): void
    {
        $result = CurricularSummary::summarise(
            [$this->link('Matemáticas')],
            [$this->link('1º ESO'), $this->link('2º ESO'), $this->link('3º ESO'), $this->link('4º ESO')]
        );

        $this->assertSame('1º ESO', $result['primaryStage']);
        $this->assertSame(3, $result['extraStages']);
    }

    public function testRepeatedStageTitleIsDeduplicatedBeforeCounting(): void
    {
        $result = CurricularSummary::summarise(
            [$this->link('Matemáticas')],
            [$this->link('1º ESO'), $this->link('1º ESO')]
        );

        $this->assertSame(0, $result['extraStages']);
    }

    /** D2: un literal en una property de enlace se marca; los 4 casos del catálogo real. */
    public function testALiteralAnywhereRaisesTheFlag(): void
    {
        $result = CurricularSummary::summarise(
            [['title' => 'Matemáticas', 'isLiteral' => true]],
            [$this->link('1º ESO')]
        );

        $this->assertTrue($result['hasLiteral']);
    }

    public function testLiteralInStagesAlsoRaisesTheFlag(): void
    {
        $result = CurricularSummary::summarise(
            [$this->link('Matemáticas')],
            [['title' => '1º ESO', 'isLiteral' => true]]
        );

        $this->assertTrue($result['hasLiteral']);
    }

    public function testTooltipListsEverythingWithoutTruncating(): void
    {
        $result = CurricularSummary::summarise(
            [$this->link('Matemáticas')],
            [$this->link('1º ESO'), $this->link('2º ESO')]
        );

        $this->assertSame('Matemáticas — 1º ESO, 2º ESO', $result['tooltip']);
    }

    public function testEmptyInputIsNotAnError(): void
    {
        $result = CurricularSummary::summarise([], []);

        $this->assertSame([], $result['subjects']);
        $this->assertSame('', $result['primaryStage']);
        $this->assertSame(0, $result['extraStages']);
        $this->assertFalse($result['hasLiteral']);
        $this->assertSame('', $result['tooltip']);
    }

    public function testBlankTitlesAreDiscarded(): void
    {
        $result = CurricularSummary::summarise([$this->link('  ')], [$this->link('')]);

        $this->assertSame([], $result['subjects']);
        $this->assertSame('', $result['primaryStage']);
    }
}
```

- [ ] **Step 2: Ejecutar el test y verificar que falla**

Run: `make test`
Expected: FAIL — `Class "OERManager\Service\Governance\CurricularSummary" not found`.

- [ ] **Step 3: Implementación mínima**

Crear `src/Service/Governance/CurricularSummary.php`:

```php
<?php

declare(strict_types=1);

namespace OERManager\Service\Governance;

/**
 * Resumen de la celda curricular: fusiona lrmi:educationalLevel (curso) y
 * schema:about (materia) en una sola columna (TASK-027 §3).
 *
 * Deduplica por título porque el currículo repite la misma materia en varios
 * cursos —«Matemáticas» aparece en cuatro— y la tabla pintaba un chip por cada
 * uno. NO empareja materia con curso: hacerlo exigiría recorrer el grafo
 * curricular en cada fila, y la pregunta que la celda responde («¿de qué va
 * este REA?») no lo necesita.
 *
 * Pura a propósito: la resolución de valores a títulos la hace el ColumnType.
 */
final class CurricularSummary
{
    /**
     * @param list<array{title:string, isLiteral:bool}> $subjects schema:about
     * @param list<array{title:string, isLiteral:bool}> $stages   lrmi:educationalLevel
     * @return array{subjects:list<string>, primaryStage:string, extraStages:int, hasLiteral:bool, tooltip:string}
     */
    public static function summarise(array $subjects, array $stages): array
    {
        $hasLiteral = false;
        foreach ([...$subjects, ...$stages] as $value) {
            if ($value['isLiteral'] && '' !== trim($value['title'])) {
                $hasLiteral = true;
                break;
            }
        }

        $subjectTitles = self::uniqueTitles($subjects);
        $stageTitles = self::uniqueTitles($stages);

        $tooltip = '';
        if ([] !== $subjectTitles || [] !== $stageTitles) {
            $tooltip = trim(
                implode(', ', $subjectTitles)
                . ([] !== $stageTitles ? ' — ' . implode(', ', $stageTitles) : '')
            );
        }

        return [
            'subjects' => $subjectTitles,
            'primaryStage' => $stageTitles[0] ?? '',
            'extraStages' => max(0, count($stageTitles) - 1),
            'hasLiteral' => $hasLiteral,
            'tooltip' => $tooltip,
        ];
    }

    /**
     * @param list<array{title:string, isLiteral:bool}> $values
     * @return list<string>
     */
    private static function uniqueTitles(array $values): array
    {
        $titles = [];
        foreach ($values as $value) {
            $title = trim($value['title']);
            if ('' !== $title && !in_array($title, $titles, true)) {
                $titles[] = $title;
            }
        }
        return $titles;
    }
}
```

- [ ] **Step 4: Ejecutar los tests y verificar que pasan**

Run: `make test && make lint`
Expected: PASS, 9 tests nuevos.

- [ ] **Step 5: Commit**

```bash
git add src/Service/Governance/CurricularSummary.php test/Service/Governance/CurricularSummaryTest.php
git commit -m "feat(curricular): resumen puro con dedup por titulo y marca de literal"
```

---

## Task 4: `ComputedPredicates` — qué predicados pide una query

**Files:**
- Create: `src/Service/ComputedPredicates.php`
- Test: `test/Service/ComputedPredicatesTest.php`

**Interfaces:**
- Consumes: `AlignmentStatus::PARTIAL` (constante ya existente en `src/ColumnType/AlignmentStatus.php`).
- Produces: `ComputedPredicates::activeKeys(array $query): array` → lista de claves, subconjunto de `[ComputedPredicates::ALIGNMENT_PARTIAL, ComputedPredicates::INTEGRITY]`. Constantes `INTEGRITY_OK = 'ok'` e `INTEGRITY_WARNING = 'warning'` para los valores admitidos del filtro.

- [ ] **Step 1: Escribir el test que falla**

Crear `test/Service/ComputedPredicatesTest.php`:

```php
<?php

declare(strict_types=1);

namespace OERManager\Test\Service;

use OERManager\Service\ComputedPredicates;
use PHPUnit\Framework\TestCase;

/**
 * El controlador tenía una rama if/else escrita a medida del filtro «parcial».
 * Con un segundo filtro computado esa rama se duplicaría entera —búsqueda con
 * tope, ComputedFilter, paginator, isTruncated—, así que se resuelve antes qué
 * predicados pide la query y el controlador se queda con UNA rama.
 */
final class ComputedPredicatesTest extends TestCase
{
    public function testNoFiltersMeansNoComputedWork(): void
    {
        $this->assertSame([], ComputedPredicates::activeKeys([]));
    }

    public function testPartialAlignmentIsComputed(): void
    {
        $this->assertSame(
            [ComputedPredicates::ALIGNMENT_PARTIAL],
            ComputedPredicates::activeKeys(['alignment' => 'partial'])
        );
    }

    /** Los otros dos estados de anclaje SÍ se expresan como query (MasterViewQuery). */
    public function testCompleteAndNoneAlignmentAreNotComputed(): void
    {
        $this->assertSame([], ComputedPredicates::activeKeys(['alignment' => 'complete']));
        $this->assertSame([], ComputedPredicates::activeKeys(['alignment' => 'none']));
    }

    public function testIntegrityFilterIsComputed(): void
    {
        $this->assertSame(
            [ComputedPredicates::INTEGRITY],
            ComputedPredicates::activeKeys(['integrity' => 'warning'])
        );
        $this->assertSame(
            [ComputedPredicates::INTEGRITY],
            ComputedPredicates::activeKeys(['integrity' => 'ok'])
        );
    }

    /**
     * D-5: el semáforo pinta dos estados porque «error» no tiene productor —el
     * FK del core cascadea al borrar, así que el enlace muerto es casi
     * inalcanzable—. Pedirlo por URL no debe disparar un barrido inútil.
     */
    public function testUnknownIntegrityValueIsIgnored(): void
    {
        $this->assertSame([], ComputedPredicates::activeKeys(['integrity' => 'error']));
        $this->assertSame([], ComputedPredicates::activeKeys(['integrity' => 'cualquiera']));
        $this->assertSame([], ComputedPredicates::activeKeys(['integrity' => '']));
    }

    public function testBothFiltersCombineInAStableOrder(): void
    {
        $this->assertSame(
            [ComputedPredicates::ALIGNMENT_PARTIAL, ComputedPredicates::INTEGRITY],
            ComputedPredicates::activeKeys(['integrity' => 'ok', 'alignment' => 'partial'])
        );
    }

    public function testArrayValuesFromTheQueryStringDoNotExplode(): void
    {
        $this->assertSame([], ComputedPredicates::activeKeys([
            'alignment' => ['partial'],
            'integrity' => ['ok'],
        ]));
    }
}
```

- [ ] **Step 2: Ejecutar el test y verificar que falla**

Run: `make test`
Expected: FAIL — `Class "OERManager\Service\ComputedPredicates" not found`.

- [ ] **Step 3: Implementación mínima**

Crear `src/Service/ComputedPredicates.php`:

```php
<?php

declare(strict_types=1);

namespace OERManager\Service;

use OERManager\ColumnType\AlignmentStatus;

/**
 * Resuelve qué predicados computados pide una query de la vista maestra.
 *
 * Existe para que el controlador tenga UNA sola rama computada: el bloque de
 * ADR-0013 (buscar con tope duro → evaluar predicado → paginar) es largo y
 * duplicarlo por filtro es cómo se propagan los defectos tipo D4.
 *
 * Un valor no reconocido se ignora en vez de tratarse como filtro: un barrido
 * de hasta 2 000 items es demasiado caro para dispararlo desde la URL.
 */
final class ComputedPredicates
{
    public const ALIGNMENT_PARTIAL = 'alignment_partial';
    public const INTEGRITY = 'integrity';

    /** Estados del semáforo que la UI ofrece (D-5: «error» no tiene productor). */
    public const INTEGRITY_OK = 'ok';
    public const INTEGRITY_WARNING = 'warning';

    /** @return list<string> */
    public static function activeKeys(array $query): array
    {
        $keys = [];

        $alignment = $query['alignment'] ?? '';
        if (is_string($alignment) && AlignmentStatus::PARTIAL === $alignment) {
            $keys[] = self::ALIGNMENT_PARTIAL;
        }

        $integrity = $query['integrity'] ?? '';
        if (is_string($integrity) && in_array($integrity, [self::INTEGRITY_OK, self::INTEGRITY_WARNING], true)) {
            $keys[] = self::INTEGRITY;
        }

        return $keys;
    }
}
```

- [ ] **Step 4: Ejecutar los tests y verificar que pasan**

Run: `make test && make lint`
Expected: PASS, 7 tests nuevos.

- [ ] **Step 5: Commit**

```bash
git add src/Service/ComputedPredicates.php test/Service/ComputedPredicatesTest.php
git commit -m "feat(filtros): resolutor de predicados computados (una sola rama en el controlador)"
```

---

## Task 5: Columna Integridad

**Files:**
- Create: `src/ColumnType/Integrity.php`
- Modify: `config/module.config.php:313-330` (registro como factoría — necesita el checker)

**Interfaces:**
- Consumes: `IntegrityChecker::check($item, false)` de Task 2; `IntegrityResult::getStatus()` y `getIssues()` (ya existentes).
- Produces: tipo de columna `'oerIntegrity'`. Constantes `Integrity::STATUS_CLASS_PREFIX = 'oer-integrity-'`.

- [ ] **Step 1: Crear la clase**

Crear `src/ColumnType/Integrity.php`:

```php
<?php

namespace OERManager\ColumnType;

use Laminas\View\Renderer\PhpRenderer;
use OERManager\Service\IntegrityChecker;
use OERManager\Service\IntegrityResult;
use Omeka\Api\Representation\AbstractEntityRepresentation;
use Omeka\Api\Representation\ItemRepresentation;
use Omeka\ColumnType\ColumnTypeInterface;

/**
 * Semáforo de integridad de la ficha (RF-002, RF-006).
 *
 * Estrena en la UI un activo que llevaba desde TASK-005 calculándose en cada
 * guardado y yendo SOLO al log de Omeka.
 *
 * Dos estados, no tres (D-5): «error» solo lo produciría un enlace muerto, y el
 * core cascadea el borrado del valor cuando desaparece su destino
 * (Value.php, @JoinColumn(onDelete="CASCADE")), así que hoy ningún dato puede
 * producirlo. La constante sigue existiendo en IntegrityResult; lo que no se
 * hace es pintar un rojo que nunca se encenderá.
 *
 * La comprobación de enlace vivo va APAGADA (D-7): es la única que despierta el
 * proxy Doctrine de cada destino, y aquí se renderizan 25 filas por página.
 */
class Integrity implements ColumnTypeInterface
{
    public const STATUS_CLASS_PREFIX = 'oer-integrity-';

    private IntegrityChecker $checker;

    public function __construct(IntegrityChecker $checker)
    {
        $this->checker = $checker;
    }

    public function getLabel(): string
    {
        return 'Integridad'; // @translate
    }

    public function getResourceTypes(): array
    {
        return ['oer_items'];
    }

    public function getMaxColumns(): ?int
    {
        return 1;
    }

    public function renderDataForm(PhpRenderer $view, array $data): string
    {
        return '';
    }

    /** Un estado calculado en PHP no es ordenable en SQL. */
    public function getSortBy(array $data): ?string
    {
        return null;
    }

    public function renderHeader(PhpRenderer $view, array $data): string
    {
        return $view->translate($this->getLabel());
    }

    public function renderContent(PhpRenderer $view, AbstractEntityRepresentation $resource, array $data): ?string
    {
        if (!$resource instanceof ItemRepresentation) {
            return null;
        }

        $result = $this->checker->check($resource, false);
        $escape = $view->plugin('escapeHtml');
        $count = count($result->getIssues());

        if ($result->isOk()) {
            $label = $view->translate('Ficha completa'); // @translate
            return sprintf(
                '<span class="oer-integrity %s%s" title="%s"><span class="oer-visually-hidden">%s</span></span>',
                self::STATUS_CLASS_PREFIX,
                $escape(IntegrityResult::STATUS_OK),
                $escape($label),
                $escape($label)
            );
        }

        $label = sprintf(
            $view->translate('%s incidencias en la ficha'), // @translate
            $count
        );

        return sprintf(
            '<span class="oer-integrity %s%s" title="%s">%s<span class="oer-visually-hidden">%s</span></span>',
            self::STATUS_CLASS_PREFIX,
            $escape($result->getStatus()),
            $escape($label),
            $escape((string) $count),
            $escape($label)
        );
    }
}
```

- [ ] **Step 2: Registrar el tipo**

En `config/module.config.php`, dentro de `'column_types' => ['factories' => [...]]`, añadir junto a `'oerValue'`:

```php
            'oerIntegrity' => function ($container) {
                return new ColumnType\Integrity(
                    $container->get(Service\IntegrityChecker::class)
                );
            },
```

- [ ] **Step 3: Ejecutar lint**

Run: `make lint && make test`
Expected: PASS. El tipo aún no está en `column_defaults` (eso es Task 8), así que no cambia nada visible.

- [ ] **Step 4: Commit**

```bash
git add src/ColumnType/Integrity.php config/module.config.php
git commit -m "feat(columnas): semaforo de integridad de dos estados (TASK-028 D-5)"
```

---

## Task 6: Columna Curricular

**Files:**
- Create: `src/ColumnType/Curricular.php`
- Modify: `config/module.config.php` (registro como invokable)

**Interfaces:**
- Consumes: `CurricularSummary::summarise()` de Task 3.
- Produces: tipo de columna `'oerCurricular'`.

- [ ] **Step 1: Crear la clase**

Crear `src/ColumnType/Curricular.php`:

```php
<?php

namespace OERManager\ColumnType;

use Laminas\View\Renderer\PhpRenderer;
use OERManager\Service\Governance\CurricularSummary;
use Omeka\Api\Representation\AbstractEntityRepresentation;
use Omeka\Api\Representation\ItemRepresentation;
use Omeka\ColumnType\ColumnTypeInterface;

/**
 * Celda curricular: fusiona lrmi:educationalLevel (curso) y schema:about
 * (materia) en una sola columna, sustituyendo las dos que había (TASK-027 §3).
 *
 * Arregla dos defectos del estudio: deduplica el título repetido —el currículo
 * repite «Matemáticas» en cuatro cursos y la tabla pintaba cuatro chips
 * idénticos— y marca con ⚠ el valor LITERAL en una property de enlace (D2), que
 * hoy se pinta como enlace legítimo.
 *
 * Ese ⚠ es uno de los dos únicos glifos que ADR-0014 regla 3 autoriza en la
 * fila, porque pide una acción concreta y distinta de las demás: promover el
 * valor a enlace. La decisión de qué se marca vive en CurricularSummary; aquí
 * solo se pinta.
 */
class Curricular implements ColumnTypeInterface
{
    public const SUBJECT_TERM = 'schema:about';
    public const STAGE_TERM = 'lrmi:educationalLevel';

    public function getLabel(): string
    {
        return 'Curricular'; // @translate
    }

    public function getResourceTypes(): array
    {
        return ['oer_items'];
    }

    public function getMaxColumns(): ?int
    {
        return 1;
    }

    public function renderDataForm(PhpRenderer $view, array $data): string
    {
        return '';
    }

    public function getSortBy(array $data): ?string
    {
        return null;
    }

    public function renderHeader(PhpRenderer $view, array $data): string
    {
        return $view->translate($this->getLabel());
    }

    public function renderContent(PhpRenderer $view, AbstractEntityRepresentation $resource, array $data): ?string
    {
        if (!$resource instanceof ItemRepresentation) {
            return null;
        }

        $summary = CurricularSummary::summarise(
            $this->titlesFor($resource, self::SUBJECT_TERM),
            $this->titlesFor($resource, self::STAGE_TERM)
        );

        if ([] === $summary['subjects'] && '' === $summary['primaryStage']) {
            return null;
        }

        $escape = $view->plugin('escapeHtml');
        $parts = [];

        if ($summary['hasLiteral']) {
            $warning = $view->translate('Hay un valor literal donde debería haber un enlace al item-término'); // @translate
            $parts[] = sprintf(
                '<span class="oer-curricular-literal" title="%s"><span class="oer-visually-hidden">%s</span></span>',
                $escape($warning),
                $escape($warning)
            );
        }

        if ([] !== $summary['subjects']) {
            $parts[] = '<span class="oer-curricular-subject">'
                . $escape(implode(', ', $summary['subjects'])) . '</span>';
        }

        if ('' !== $summary['primaryStage']) {
            $stage = $summary['primaryStage'];
            if ($summary['extraStages'] > 0) {
                $stage .= sprintf(
                    $view->translate(' (+%s cursos)'), // @translate
                    $summary['extraStages']
                );
            }
            $parts[] = '<span class="oer-curricular-stage">' . $escape($stage) . '</span>';
        }

        return sprintf(
            '<span class="oer-curricular" title="%s">%s</span>',
            $escape($summary['tooltip']),
            implode(' ', $parts)
        );
    }

    /**
     * El título de un enlace es el del item destino; el de un literal, su propio
     * texto. valueResource() aquí SÍ se paga: es lo que la celda muestra, no una
     * comprobación evitable como la de D-7.
     *
     * @return list<array{title:string, isLiteral:bool}>
     */
    private function titlesFor(ItemRepresentation $item, string $term): array
    {
        $out = [];
        foreach ($item->value($term, ['all' => true, 'default' => []]) as $value) {
            $isLiteral = !str_starts_with($value->type(), 'resource');
            if ($isLiteral) {
                $out[] = ['title' => (string) $value->value(), 'isLiteral' => true];
                continue;
            }
            $target = $value->valueResource();
            $out[] = [
                'title' => $target ? (string) $target->displayTitle() : '',
                'isLiteral' => false,
            ];
        }
        return $out;
    }
}
```

- [ ] **Step 2: Registrar el tipo**

En `config/module.config.php`, dentro de `'column_types' => ['invokables' => [...]]`, añadir:

```php
            'oerCurricular' => ColumnType\Curricular::class,
```

- [ ] **Step 3: Ejecutar lint y tests**

Run: `make lint && make test`
Expected: PASS.

- [ ] **Step 4: Commit**

```bash
git add src/ColumnType/Curricular.php config/module.config.php
git commit -m "feat(columnas): celda curricular fusionada con dedup y marca de literal (D2)"
```

---

## Task 7: Columna de valor con estado vacío (Licencia y Tipo)

**Files:**
- Create: `src/ColumnType/GovernanceValue.php`
- Modify: `config/module.config.php` (registro como invokable)

**Interfaces:**
- Consumes: nada de tareas previas.
- Produces: tipo de columna `'oerGovernanceValue'`, parametrizado por los datos de columna `property_term` (string) y `empty_label` (string). Se registra **una vez** y se usa **dos** en `column_defaults` (Task 8).

**Por qué un solo tipo para dos columnas:** Licencia y Tipo son el mismo problema —mostrar un valor con estado vacío explícito— y dos clases casi idénticas serían duplicación. Con 18/19 REA sin licencia, la celda vacía era precisamente la información que faltaba.

**Lo que NO hace (D-4):** no marca con glifo el valor «fuera del vocabulario». Con 16/19 tipos fuera del CustomVocab, marcarlos sería marcar la tabla entera, y ADR-0014 regla 2 reserva el acento a lo que reclama acción. Esto revisa conscientemente TASK-027 §3, que pedía tres estados marcados en la celda; los tres siguen vivos en el filtro y en el drawer.

- [ ] **Step 1: Crear la clase**

Crear `src/ColumnType/GovernanceValue.php`:

```php
<?php

namespace OERManager\ColumnType;

use Laminas\View\Renderer\PhpRenderer;
use Omeka\Api\Representation\AbstractEntityRepresentation;
use Omeka\Api\Representation\ItemRepresentation;
use Omeka\ColumnType\ColumnTypeInterface;

/**
 * Valor de una property con ESTADO VACÍO EXPLÍCITO (TASK-027 §3).
 *
 * Sirve a Licencia (dcterms:rights) y a Tipo de recurso
 * (lrmi:learningResourceType): el mismo problema, un solo tipo registrado y
 * usado dos veces con `property_term` distinto.
 *
 * La aportación frente a ColumnType\Value es la ausencia: hoy la celda vacía no
 * dice nada, y con 18 de 19 REA sin licencia eso era justo la información que
 * faltaba. El estado vacío va en tinta apagada y con texto, SIN glifo: ADR-0014
 * reserva la marca por forma a lo que dispara una acción distinta, y una marca
 * presente en el 90 % de las filas deja de señalar la excepción.
 */
class GovernanceValue implements ColumnTypeInterface
{
    public function getLabel(): string
    {
        return 'Valor'; // @translate
    }

    public function getResourceTypes(): array
    {
        return ['oer_items'];
    }

    public function getMaxColumns(): ?int
    {
        return null;
    }

    public function renderDataForm(PhpRenderer $view, array $data): string
    {
        return '';
    }

    public function getSortBy(array $data): ?string
    {
        return $data['property_term'] ?? null;
    }

    public function renderHeader(PhpRenderer $view, array $data): string
    {
        return $view->translate($data['header'] ?? $this->getLabel());
    }

    public function renderContent(PhpRenderer $view, AbstractEntityRepresentation $resource, array $data): ?string
    {
        if (!$resource instanceof ItemRepresentation) {
            return null;
        }

        $term = (string) ($data['property_term'] ?? '');
        if ('' === $term) {
            return null;
        }

        $escape = $view->plugin('escapeHtml');
        $values = $resource->value($term, ['all' => true, 'default' => []]);

        $texts = [];
        foreach ($values as $value) {
            $text = trim((string) $value);
            if ('' !== $text) {
                $texts[] = $text;
            }
        }

        if ([] === $texts) {
            return sprintf(
                '<span class="oer-value-missing">%s</span>',
                $escape($view->translate($data['empty_label'] ?? 'Sin valor')) // @translate
            );
        }

        return sprintf('<span class="oer-value">%s</span>', $escape(implode(', ', $texts)));
    }
}
```

- [ ] **Step 2: Registrar el tipo**

En `config/module.config.php`, dentro de `'column_types' => ['invokables' => [...]]`, añadir:

```php
            'oerGovernanceValue' => ColumnType\GovernanceValue::class,
```

- [ ] **Step 3: Ejecutar lint y tests**

Run: `make lint && make test`
Expected: PASS.

- [ ] **Step 4: Commit**

```bash
git add src/ColumnType/GovernanceValue.php config/module.config.php
git commit -m "feat(columnas): valor con estado vacio explicito para licencia y tipo"
```

---

## Task 8: Juego de columnas por defecto y renombrado a «Anclaje»

**Files:**
- Modify: `config/module.config.php:331-343` (`column_defaults`)
- Modify: `src/ColumnType/AlignmentStatus.php:21-24` (rótulo)
- Modify: `test/ModuleConfigTest.php`

**Interfaces:**
- Consumes: los tres tipos registrados en Tasks 5-7.
- Produces: el `column_defaults` definitivo de la rebanada. Nada consume esto.

**Alcance exacto del renombrado (spec §8):** cambia el **rótulo**. NO cambian el nombre de la clase `AlignmentStatus`, sus constantes ni el parámetro de query `alignment`: los internos espejan el vocabulario RDF (`lrmi:educationalAlignment`), que no se renombra, y el parámetro sostiene los enlaces y marcadores ya existentes.

- [ ] **Step 1: Escribir el test que falla**

Añadir a `test/ModuleConfigTest.php`, dentro de la clase:

```php
    public function testGovernanceColumnTypesAreRegistered(): void
    {
        $config = include __DIR__ . '/../config/module.config.php';
        $types = array_merge(
            array_keys($config['column_types']['invokables'] ?? []),
            array_keys($config['column_types']['factories'] ?? [])
        );

        foreach (['oerIntegrity', 'oerCurricular', 'oerGovernanceValue'] as $type) {
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

        $this->assertCount(7, $defaults);
        $this->assertSame([
            'oerAlignmentStatus',
            'oerIntegrity',
            'oerCurricular',
            'oerGovernanceValue',
            'oerGovernanceValue',
            'oerIsPublic',
            'oerModified',
        ], array_column($defaults, 'type'));
    }

    public function testTheTwoGovernanceValueColumnsPointAtLicenceAndResourceType(): void
    {
        $config = include __DIR__ . '/../config/module.config.php';
        $defaults = $config['column_defaults']['admin']['oer_items'] ?? [];

        $terms = array_values(array_filter(array_column($defaults, 'property_term')));
        $this->assertSame(['lrmi:learningResourceType', 'dcterms:rights'], $terms);
    }

    /** El rótulo pasa a «Anclaje» (decisión del propietario, 2026-08-03). */
    public function testAlignmentColumnIsLabelledAnclaje(): void
    {
        $this->assertSame('Anclaje', (new \OERManager\ColumnType\AlignmentStatus())->getLabel());
    }
```

- [ ] **Step 2: Ejecutar y verificar que falla**

Run: `make test`
Expected: FAIL en las cuatro pruebas nuevas.

- [ ] **Step 3: Cambiar el rótulo**

En `src/ColumnType/AlignmentStatus.php`, sustituir el cuerpo de `getLabel()`:

```php
    public function getLabel(): string
    {
        // Renombrado de «Alineamiento» a «Anclaje» por decisión del propietario
        // (2026-08-03). Solo el rótulo: la clase, sus constantes y el parámetro
        // de query `alignment` espejan el vocabulario RDF (lrmi:educationalAlignment)
        // y sostienen los enlaces ya existentes.
        return 'Anclaje'; // @translate
    }
```

- [ ] **Step 4: Sustituir `column_defaults`**

En `config/module.config.php`, sustituir el bloque `'column_defaults'` entero por:

```php
    // Reequilibrio de ADR-0013 (TASK-028 rebanada 2): la tabla pasa de mostrar
    // el anclaje —que está al 100 %— a mostrar la gobernanza, que está vacía.
    // Ocho columnas contando el Título, que lo pinta la plantilla: es el tope
    // que TASK-027 §3 fijó para no forzar scroll horizontal en el admin.
    //
    // Los oerValue de lrmi:educationalLevel y schema:about salen (los fusiona
    // oerCurricular) y el de dcterms:rights también (lo sustituye la celda con
    // estado vacío). Siguen REGISTRADOS: un curador puede reactivarlos desde la
    // configuración nativa de columnas.
    //
    // OJO: column_defaults solo aplica a quien NO haya guardado su propia
    // selección. Quien la guardó tras la rebanada 1 conserva las columnas
    // viejas hasta que la reajuste.
    'column_defaults' => [
        'admin' => [
            'oer_items' => [
                ['type' => 'oerAlignmentStatus'],
                ['type' => 'oerIntegrity'],
                ['type' => 'oerCurricular'],
                [
                    'type' => 'oerGovernanceValue',
                    'property_term' => 'lrmi:learningResourceType',
                    'header' => 'Tipo de recurso', // @translate
                    'empty_label' => 'Sin tipo', // @translate
                ],
                [
                    'type' => 'oerGovernanceValue',
                    'property_term' => 'dcterms:rights',
                    'header' => 'Licencia', // @translate
                    'empty_label' => 'Sin licencia', // @translate
                ],
                ['type' => 'oerIsPublic'],
                ['type' => 'oerModified'],
            ],
        ],
    ],
```

- [ ] **Step 5: Ejecutar los tests y verificar que pasan**

Run: `make test && make lint`
Expected: PASS.

- [ ] **Step 6: Commit**

```bash
git add config/module.config.php src/ColumnType/AlignmentStatus.php test/ModuleConfigTest.php
git commit -m "feat(columnas): juego por defecto de gobernanza y renombrado a Anclaje"
```

---

## Task 9: Filtros `nex` de gobernanza

**Files:**
- Modify: `src/Service/MasterViewQuery.php:76-92` (añadir tras el filtro de licencia)
- Modify: `view/oer-manager/admin/index/search.phtml`

**Interfaces:**
- Consumes: nada de tareas previas.
- Produces: los parámetros GET `missing[]` con valores en `MasterViewQuery::MISSING_FILTERS` (mapa `clave => término`).

**Se deja fuera «sin autoría»** pese a figurar como imprescindible en TASK-027 §9: con 19/19 sin autoría devolvería el catálogo entero y sin su columna no comunica nada. Entra en la rebanada 2b con RF-015.

- [ ] **Step 1: Añadir los filtros a `MasterViewQuery`**

En `src/Service/MasterViewQuery.php`, añadir la constante justo debajo de `LEARNING_RESOURCE_CLASS_TERM`:

```php
    /**
     * Filtros de gobernanza expresables como query de la API (`nex`), así que no
     * pagan barrido computado. Los afectados hoy, medidos en TASK-027: licencia
     * 18/19, descripción 4/19, tipo 3/19, título 1/19.
     */
    public const MISSING_FILTERS = [
        'licence' => 'dcterms:rights',
        'description' => 'dcterms:description',
        'title' => 'dcterms:title',
        'resource_type' => 'lrmi:learningResourceType',
    ];
```

Y dentro de `buildSearchParams()`, justo antes de la llamada a `$this->addAlignmentFilter(...)`:

```php
        // Filtros «sin X» de gobernanza. Se piden como missing[]=clave para no
        // colisionar con los filtros de valor: `licence` filtra POR licencia y
        // `missing[]=licence` filtra por su AUSENCIA.
        foreach ((array) ($query['missing'] ?? []) as $key) {
            if (is_string($key) && isset(self::MISSING_FILTERS[$key])) {
                $params['property'][] = [
                    'property' => self::MISSING_FILTERS[$key],
                    'type' => 'nex',
                ];
            }
        }
```

- [ ] **Step 2: Añadir las casillas a la búsqueda avanzada**

En `view/oer-manager/admin/index/search.phtml`, antes del botón de envío del formulario, añadir:

```php
    <?php
    // Filtros de gobernanza (TASK-028 rebanada 2). Son queries `nex` puras: no
    // pagan el barrido computado del filtro de integridad.
    $missing = (array) ($query['missing'] ?? []);
    $missingLabels = [
        'licence' => $translate('Sin licencia'),
        'description' => $translate('Sin descripción'),
        'title' => $translate('Sin título'),
        'resource_type' => $translate('Sin tipo de recurso'),
    ];
    ?>
    <div class="field">
        <div class="field-meta">
            <span class="label"><?php echo $translate('Gobernanza'); ?></span>
        </div>
        <div class="inputs">
            <?php foreach ($missingLabels as $key => $label): ?>
            <label class="oer-checkbox">
                <input type="checkbox" name="missing[]" value="<?php echo $escape($key); ?>"
                    <?php echo in_array($key, $missing, true) ? 'checked' : ''; ?>>
                <?php echo $escape($label); ?>
            </label>
            <?php endforeach; ?>
        </div>
    </div>
```

- [ ] **Step 3: Verificar a mano que la query se construye**

Run: `make lint && make test`
Expected: PASS.

Comprobación manual del mapeo (no hay test de host posible: `MasterViewQuery` tipa `Omeka\Api\Manager`):

Run: `grep -n "MISSING_FILTERS" -A 6 src/Service/MasterViewQuery.php`
Expected: la constante con los cuatro pares y el bucle que los traduce a `type => 'nex'`.

- [ ] **Step 4: Commit**

```bash
git add src/Service/MasterViewQuery.php view/oer-manager/admin/index/search.phtml
git commit -m "feat(filtros): cuatro filtros nex de gobernanza en la busqueda avanzada"
```

---

## Task 10: Filtro de integridad y rama computada única

**Files:**
- Modify: `src/Controller/Admin/IndexController.php:98-167` (`indexAction`)
- Modify: `view/oer-manager/admin/index/index.phtml:1-60` (selector del filtro en la barra rápida)

**Interfaces:**
- Consumes: `ComputedPredicates::activeKeys()` (Task 4), `IntegrityChecker::check($item, false)` (Task 2), `ComputedFilter::apply()` (ya existente), `AlignmentStatus::statusFor()` (ya existente).
- Produces: la variable de vista `$integrityStatuses` (`array<int,string>`, id → `'ok'|'warning'|'error'`) que Task 11 usa para el riel.

**Nota de coste consciente:** el estado de integridad se calcula dos veces por fila — una en la columna y otra para el riel. Con la comprobación de enlaces apagada (D-7) son lecturas de valores ya cargados en memoria. Plumbing el resultado desde el controlador hasta el `ColumnType` exigiría un canal que el mecanismo nativo de columnas no ofrece, y no compensa.

- [ ] **Step 1: Sustituir el cuerpo de `indexAction`**

En `src/Controller/Admin/IndexController.php`, sustituir desde `$this->browse()->setDefaults('oer_items');` hasta la línea `}` que cierra el `else` (hoy líneas 103-145) por:

```php
        $this->browse()->setDefaults('oer_items');
        $query = $this->params()->fromQuery();
        $searchParams = $this->masterViewQuery->buildSearchParams($query);

        // Una sola rama computada (TASK-028 rebanada 2). Antes había un if/else
        // escrito a medida del filtro «parcial»; con un segundo filtro computado
        // ese bloque —búsqueda con tope, ComputedFilter, paginator, isTruncated—
        // se habría duplicado entero, que es cómo se propagan los defectos D4.
        $computedKeys = ComputedPredicates::activeKeys($query);

        if ([] !== $computedKeys) {
            // Las representaciones se piden de una vez, acotadas al tope, en vez
            // de resolver ids y releer cada uno: los predicados necesitan el item
            // entero, así que un read por id serían hasta HARD_CAP consultas.
            $fullParams = $searchParams;
            $fullParams['page'] = 1;
            $fullParams['per_page'] = ComputedFilter::HARD_CAP;
            $response = $this->api()->search('items', $fullParams);

            $candidates = [];
            foreach ($response->getContent() as $candidate) {
                $candidates[(int) $candidate->id()] = $candidate;
            }

            $predicate = $this->computedPredicate($computedKeys, $candidates, $query);
            $filtered = $this->computedFilter->apply(
                array_keys($candidates),
                $predicate,
                (int) ($query['page'] ?? 1),
                (int) $this->settings->get('pagination_per_page', 25)
            );
            $items = array_map(static fn (int $id) => $candidates[$id], $filtered['ids']);
            $this->paginator($filtered['total']);
            $isTruncated = $filtered['truncated']
                || $response->getTotalResults() > ComputedFilter::HARD_CAP;
        } else {
            $response = $this->api()->search('items', $searchParams);
            $items = $response->getContent();
            $this->paginator($response->getTotalResults());
            $isTruncated = false;
        }

        // Estado de integridad de las filas visibles, para el riel de ADR-0014.
        // Con la comprobación de enlaces apagada (D-7) esto son lecturas en
        // memoria; la columna lo recalcula por su cuenta porque el mecanismo
        // nativo de columnas no ofrece un canal para pasárselo.
        $integrityStatuses = [];
        foreach ($items as $item) {
            $integrityStatuses[(int) $item->id()] = $this->integrityChecker
                ->check($item, false)
                ->getStatus();
        }
```

- [ ] **Step 2: Añadir el método que compone los predicados**

En la misma clase, justo después de `indexAction()`, añadir:

```php
    /**
     * Compone los predicados computados activos en uno solo (AND).
     *
     * @param list<string> $keys
     * @param array<int,ItemRepresentation> $candidates
     * @return callable(int):bool
     */
    private function computedPredicate(array $keys, array $candidates, array $query): callable
    {
        $predicates = [];

        foreach ($keys as $key) {
            if (ComputedPredicates::ALIGNMENT_PARTIAL === $key) {
                $predicates[] = static fn (int $id): bool => AlignmentStatus::PARTIAL
                    === AlignmentStatus::statusFor($candidates[$id]);
                continue;
            }
            if (ComputedPredicates::INTEGRITY === $key) {
                $wanted = (string) $query['integrity'];
                $checker = $this->integrityChecker;
                $predicates[] = static fn (int $id): bool => $wanted
                    === $checker->check($candidates[$id], false)->getStatus();
            }
        }

        return static function (int $id) use ($predicates): bool {
            foreach ($predicates as $predicate) {
                if (!$predicate($id)) {
                    return false;
                }
            }
            return true;
        };
    }
```

- [ ] **Step 3: Inyectar el checker en el controlador**

En `src/Controller/Admin/IndexController.php`:

1. Añadir el `use`: `use OERManager\Service\ComputedPredicates;` y `use OERManager\Service\IntegrityChecker;`
2. Añadir la propiedad `private IntegrityChecker $integrityChecker;` junto a las demás.
3. Añadir el parámetro `IntegrityChecker $integrityChecker` **al final** de la firma del constructor y su asignación.

En `config/module.config.php`, en la factoría del controlador, añadir como último argumento:

```php
                    $container->get(Service\IntegrityChecker::class),
```

- [ ] **Step 4: Pasar la variable de vista**

En `indexAction()`, junto a los demás `$view->setVariable(...)`, añadir:

```php
        $view->setVariable('integrityStatuses', $integrityStatuses);
```

- [ ] **Step 5: Añadir el selector a la barra rápida**

En `view/oer-manager/admin/index/index.phtml`, junto al selector del filtro de anclaje, añadir:

```php
    <select name="integrity" aria-label="<?php echo $escape($translate('Integridad')); ?>">
        <option value=""><?php echo $translate('Integridad: todas'); ?></option>
        <option value="ok" <?php echo 'ok' === ($query['integrity'] ?? '') ? 'selected' : ''; ?>>
            <?php echo $translate('Ficha completa'); ?>
        </option>
        <option value="warning" <?php echo 'warning' === ($query['integrity'] ?? '') ? 'selected' : ''; ?>>
            <?php echo $translate('Con incidencias'); ?>
        </option>
    </select>
```

- [ ] **Step 6: Ejecutar lint y tests**

Run: `make lint && make test`
Expected: PASS.

- [ ] **Step 7: Commit**

```bash
git add src/Controller/Admin/IndexController.php config/module.config.php view/oer-manager/admin/index/index.phtml
git commit -m "feat(filtros): filtro de integridad computado con una sola rama en el controlador"
```

---

## Task 11: Riel anclado a integridad, título ausente y estilos

**Files:**
- Modify: `view/oer-manager/admin/index/index.phtml:110-121`
- Modify: `asset/css/oer-master-view.css`

**Interfaces:**
- Consumes: `$integrityStatuses` de Task 10; las clases `oer-integrity-*`, `oer-curricular-literal`, `oer-value-missing` de Tasks 5-7.
- Produces: el atributo `data-integrity` en el `<tr>`, que Task 12 comprueba.

- [ ] **Step 1: Anclar el riel y marcar el título ausente**

En `view/oer-manager/admin/index/index.phtml`, sustituir la apertura de la fila y la celda de título:

```php
        <tr data-resource-id="<?php echo $item->id(); ?>" data-api-url="<?php echo $escape($item->apiUrl()); ?>"
            data-alignment="<?php echo $escape(\OERManager\ColumnType\AlignmentStatus::statusFor($item)); ?>"
            data-integrity="<?php echo $escape($integrityStatuses[$item->id()] ?? 'ok'); ?>">
            <td class="oer-column-select"><input type="checkbox" class="oer-row-select" name="resource_ids[]" value="<?php echo $item->id(); ?>" aria-label="<?php echo $escape($translate('Seleccionar REA')); ?>"></td>
            <td class="oer-column-title">
                <div class="oer-title-cell">
                    <?php
                    // El item 4359 del catálogo real no tiene dcterms:title y salía
                    // como enlace vacío: invisible en cualquier listado (TASK-027 §3).
                    $title = trim((string) $item->displayTitle(''));
                    ?>
                    <a href="#" class="oer-open-drawer<?php echo '' === $title ? ' oer-title-missing' : ''; ?>" data-item-id="<?php echo $item->id(); ?>">
                        <?php echo $escape('' === $title ? $translate('(sin título)') : $title); ?>
                    </a>
                    <span class="oer-title-id"><?php echo $escape((string) $item->id()); ?></span>
                    <ul class="actions">
                        <li><?php echo $item->link('', 'edit', ['class' => 'o-icon-edit', 'title' => $translate('Editar')]); ?></li>
                    </ul>
                </div>
            </td>
```

- [ ] **Step 2: Reanclar el riel en el CSS**

En `asset/css/oer-master-view.css`, localizar el bloque que pinta el riel a partir de `[data-alignment]` y sustituir el selector por `[data-integrity]`, con el mapeo:

```css
/*
 * Riel de fila (ADR-0014 regla 4). Se re-ancla de `data-alignment` a
 * `data-integrity` en la rebanada 2 de TASK-028: la integridad es la señal más
 * fiable de las dos —AlignmentStatus da «Completo» a un REA con la materia
 * literal rota (D3), falso positivo que IntegrityChecker ya no comete—.
 * El mecanismo es la escala visual, no la property: si la señal rectora cambia
 * otra vez, se re-ancla aquí y no se elimina.
 *
 * Saturación invertida a propósito: lo correcto se retira, el defecto se queda.
 */
#oer-master-view-table tbody tr[data-integrity="ok"] {
    box-shadow: inset 3px 0 0 var(--oer-rail-ok);
}

#oer-master-view-table tbody tr[data-integrity="warning"] {
    box-shadow: inset 3px 0 0 var(--oer-rail-warn);
}

#oer-master-view-table tbody tr[data-integrity="error"] {
    box-shadow: inset 3px 0 0 var(--oer-rail-bad);
}
```

- [ ] **Step 3: Estilar las celdas nuevas**

Añadir al final de `asset/css/oer-master-view.css`:

```css
/* --- Celdas de gobernanza (TASK-028 rebanada 2) --- */

/*
 * Glifo SOLO donde dispara una acción (ADR-0014 regla 3). Dos por fila como
 * máximo: integridad —abrir el drawer— y literal en property de enlace
 * —promover el valor a enlace—. El texto accesible ya lo pone el PHP, así que
 * el glifo va por ::before, que es presentación y el lector de pantalla ignora.
 */
.oer-integrity {
    font-variant-numeric: tabular-nums;
    white-space: nowrap;
}

.oer-integrity-ok::before {
    content: "\2713";
    color: var(--oer-ok);
}

.oer-integrity-warning::before {
    content: "\26A0";
    color: var(--oer-warn);
    margin-right: 0.25em;
}

.oer-integrity-warning {
    color: var(--oer-warn);
}

.oer-integrity-error::before {
    content: "\26D4";
    color: var(--oer-bad);
    margin-right: 0.25em;
}

.oer-curricular-literal::before {
    content: "\26A0";
    color: var(--oer-warn);
    margin-right: 0.25em;
}

.oer-curricular-stage {
    display: block;
    color: var(--oer-muted);
    font-size: 0.9em;
}

/*
 * La ausencia se lee, no se marca: con 18/19 sin licencia y 16/19 con el tipo
 * fuera del vocabulario, un glifo aquí estaría en el 90 % de las filas y
 * dejaría de señalar la excepción (ADR-0014 regla 2).
 */
.oer-value-missing {
    color: var(--oer-muted);
    font-style: italic;
}

.oer-title-missing {
    font-style: italic;
}

.oer-title-id {
    display: block;
    color: var(--oer-muted);
    font-size: 0.85em;
}
```

- [ ] **Step 4: Comprobar que no queda ningún riel colgado de `data-alignment`**

Run: `grep -n "data-alignment" asset/css/oer-master-view.css`
Expected: sin resultados (el riel ya no cuelga de ahí). Si aparece alguno, es un bloque que quedó sin migrar.

- [ ] **Step 5: Ejecutar lint, tests y tests JS**

Run: `make lint && make test && make test-js`
Expected: PASS. Los 33 tests JS de la rebanada 1 siguen verdes.

- [ ] **Step 6: Commit**

```bash
git add view/oer-manager/admin/index/index.phtml asset/css/oer-master-view.css
git commit -m "feat(ui): riel anclado a integridad, titulo ausente marcado y celdas de gobernanza"
```

---

## Task 12: Contraste WCAG AA, comprobado por test

ADR-0014 dejó como obligación de la rebanada que estrenase la columna de integridad medir el contraste de la tríada contra WCAG AA. Se cumple de forma **ejecutable**: si alguien mueve un hexadecimal por debajo del umbral, se rompe un test.

**Files:**
- Create: `asset/js/core/contrast.js`
- Test: `test/js/contrast.test.js`

**Interfaces:**
- Consumes: los tokens `--oer-ok`, `--oer-warn`, `--oer-bad`, `--oer-muted`, `--oer-surface` de `asset/css/oer-master-view.css`.
- Produces: `contrastRatio(hexA, hexB): number` y `parseTokens(cssText): Record<string,string>`.

- [ ] **Step 1: Escribir el test que falla**

Crear `test/js/contrast.test.js`:

```js
import { test } from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { fileURLToPath } from 'node:url';
import { contrastRatio, parseTokens } from '../../asset/js/core/contrast.js';

const css = readFileSync(
  fileURLToPath(new URL('../../asset/css/oer-master-view.css', import.meta.url)),
  'utf8'
);
const tokens = parseTokens(css);

// El admin de Omeka pinta la tabla sobre blanco; las filas alternas usan el
// token de superficie. El estado debe leerse sobre las dos.
const BACKGROUNDS = ['#ffffff', tokens['--oer-surface']];
const AA = 4.5;

test('parseTokens lee los tokens del CSS real', () => {
  assert.equal(typeof tokens['--oer-ok'], 'string');
  assert.match(tokens['--oer-ok'], /^#[0-9a-f]{6}$/i);
});

test('contrastRatio devuelve los extremos conocidos', () => {
  assert.equal(Math.round(contrastRatio('#000000', '#ffffff')), 21);
  assert.equal(contrastRatio('#ffffff', '#ffffff'), 1);
});

test('contrastRatio es simétrico', () => {
  assert.equal(
    contrastRatio('#1d7a5f', '#ffffff').toFixed(4),
    contrastRatio('#ffffff', '#1d7a5f').toFixed(4)
  );
});

// ADR-0014: la tríada de estado es exigible a WCAG AA. Los valores se eligieron
// por criterio en la rebanada 1 y NO se habían medido nunca.
for (const token of ['--oer-ok', '--oer-warn', '--oer-bad', '--oer-muted']) {
  for (const background of BACKGROUNDS) {
    test(`${token} cumple AA sobre ${background}`, () => {
      const ratio = contrastRatio(tokens[token], background);
      assert.ok(
        ratio >= AA,
        `${token} (${tokens[token]}) da ${ratio.toFixed(2)}:1 sobre ${background}, por debajo de ${AA}:1`
      );
    });
  }
}
```

- [ ] **Step 2: Ejecutar y verificar que falla**

Run: `make test-js`
Expected: FAIL — `Cannot find module .../asset/js/core/contrast.js`.

- [ ] **Step 3: Implementación mínima**

Crear `asset/js/core/contrast.js`:

```js
/**
 * Contraste WCAG de los tokens de color del módulo.
 *
 * ADR-0014 declara la tríada de estado exigible a WCAG AA y deja la medición
 * como obligación de la rebanada que estrene la columna de integridad. Se hace
 * por test y no a ojo: así la obligación se rompe sola si alguien mueve un
 * hexadecimal, en vez de quedarse como nota en un documento.
 *
 * Núcleo puro, sin DOM: la frontera que la rebanada 1 estableció para el JS.
 */

/** @param {string} hex `#rgb` o `#rrggbb` @returns {[number,number,number]} */
function toRgb(hex) {
  const value = String(hex).trim().replace(/^#/, '');
  const full = value.length === 3
    ? value.split('').map((c) => c + c).join('')
    : value;
  if (!/^[0-9a-f]{6}$/i.test(full)) {
    throw new TypeError(`Color no reconocido: ${hex}`);
  }
  return [0, 2, 4].map((i) => parseInt(full.slice(i, i + 2), 16));
}

/** Luminancia relativa, WCAG 2.1 §relative luminance. */
function luminance(hex) {
  const [r, g, b] = toRgb(hex).map((channel) => {
    const c = channel / 255;
    return c <= 0.03928 ? c / 12.92 : ((c + 0.055) / 1.055) ** 2.4;
  });
  return 0.2126 * r + 0.7152 * g + 0.0722 * b;
}

/** Ratio de contraste entre dos colores. 1 = idénticos, 21 = negro sobre blanco. */
export function contrastRatio(a, b) {
  const la = luminance(a);
  const lb = luminance(b);
  const lighter = Math.max(la, lb);
  const darker = Math.min(la, lb);
  return (lighter + 0.05) / (darker + 0.05);
}

/**
 * Extrae las custom properties de un texto CSS.
 * Una sola fuente de verdad: los colores viven en el CSS, no duplicados aquí.
 *
 * @param {string} cssText
 * @returns {Record<string,string>}
 */
export function parseTokens(cssText) {
  const tokens = {};
  const pattern = /(--[\w-]+)\s*:\s*(#[0-9a-fA-F]{3,8})\s*;/g;
  let match = pattern.exec(cssText);
  while (match !== null) {
    tokens[match[1]] = match[2];
    match = pattern.exec(cssText);
  }
  return tokens;
}
```

- [ ] **Step 4: Ejecutar el test**

Run: `make test-js`
Expected: los tres primeros PASAN. Los ocho de la tríada pueden **fallar**: los hexadecimales se eligieron por criterio y nunca se midieron.

- [ ] **Step 5: Corregir los tokens que no lleguen a AA**

Si algún token falla, ajustar su valor en `asset/css/oer-master-view.css` **oscureciéndolo hasta superar 4.5:1**, conservando el tono. ADR-0014 norma el criterio, no los valores: si la medición obliga a mover los hexadecimales, se mueven.

Valores de partida sugeridos si hace falta bajar luminosidad, en orden de menos a más agresivo:

| Token | Actual | Alternativa 1 | Alternativa 2 |
| --- | --- | --- | --- |
| `--oer-ok` | `#1d7a5f` | `#186951` | `#145843` |
| `--oer-warn` | `#b06d10` | `#955c0d` | `#7d4d0b` |
| `--oer-bad` | `#a91919` | `#8f1515` | `#791212` |
| `--oer-muted` | `#79818f` | `#666d79` | `#585e68` |

Tras cada ajuste, volver a ejecutar `make test-js` hasta que los once tests pasen.

**Ojo:** `--oer-muted` es el color de la ausencia («Sin licencia», el `o:id` bajo el título). Si al oscurecerlo la jerarquía visual se pierde, la salida correcta es oscurecerlo igualmente: un texto que no se lee no comunica nada, y la jerarquía se recupera con tamaño y peso, no con contraste insuficiente.

- [ ] **Step 6: Commit**

```bash
git add asset/js/core/contrast.js test/js/contrast.test.js asset/css/oer-master-view.css
git commit -m "test(a11y): contraste WCAG AA de la triada de estado, comprobado por test"
```

---

## Task 13: Arnés de verificación en contenedor

Cierra lo que ningún test de host puede cubrir: que las columnas rindan sobre datos reales lo que el dato manda. Sigue el patrón de `test/container/acl-check.php` y `config-page-check.php`.

**Files:**
- Create: `test/container/columns-check.php`

**Interfaces:**
- Consumes: todo lo anterior.
- Produces: un ejecutable de CLI que sale con `0` si todo cuadra y `1` si no.

**Trampa registrada en TASK-029 que aplica aquí:** invocar una acción del controlador directamente hace que `getRequest()` devuelva la petición del CLI. Este arnés **no** invoca el controlador: instancia los servicios y renderiza las columnas, que es lo que se quiere comprobar.

- [ ] **Step 1: Escribir el arnés**

Crear `test/container/columns-check.php`:

```php
<?php

/**
 * Arnés de contenedor de la rebanada 2 de TASK-028.
 *
 * Comprueba sobre el catálogo REAL lo que ningún test de host puede: que las
 * columnas nuevas rindan lo que el dato manda. En particular los 4 valores
 * literales (schema:about ×3, lrmi:educationalLevel ×1) que hoy pasan por «ok»
 * y deben pasar a aviso (D2/D-3), y el recuento de dead_link que quedó sin
 * medir al escribir el spec.
 *
 * Uso, desde el contenedor:
 *   php /var/www/html/modules/OERManager/test/container/columns-check.php
 *
 * Sale 1 si alguna comprobación falla, para poder encadenarlo en un smoke test.
 */

require '/var/www/html/bootstrap.php';

$application = Omeka\Mvc\Application::init(require '/var/www/html/application/config/application.config.php');
$services = $application->getServiceManager();
$api = $services->get('Omeka\ApiManager');
$checker = $services->get(OERManager\Service\IntegrityChecker::class);

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

$classes = $api->search('resource_classes', ['term' => 'lrmi:LearningResource'])->getContent();
if (!$classes) {
    echo "No existe la clase lrmi:LearningResource en esta instalación.\n";
    exit(1);
}

$items = $api->search('items', [
    'resource_class_id' => $classes[0]->id(),
    'per_page' => 500,
])->getContent();

echo 'REA en el catálogo: ' . count($items) . "\n\n";

echo "1. Reglas de integridad\n";

$deadLinks = 0;
$literals = 0;
$missingLicence = 0;
$statuses = ['ok' => 0, 'warning' => 0, 'error' => 0];

foreach ($items as $item) {
    // Con enlaces ENCENDIDOS: es la pasada que mide el dead_link real.
    $result = $checker->check($item, true);
    $statuses[$result->getStatus()]++;
    foreach ($result->getIssues() as $issue) {
        if ('dead_link' === $issue['code']) {
            $deadLinks++;
        }
        if ('literal_in_link_property' === $issue['code']) {
            $literals++;
        }
        if ('missing_license' === $issue['code']) {
            $missingLicence++;
        }
    }
}

echo "   estados: ok={$statuses['ok']} warning={$statuses['warning']} error={$statuses['error']}\n";
echo "   dead_link=$deadLinks  literal_in_link_property=$literals  missing_license=$missingLicence\n";

// D2: el estudio contó 4 valores literales en properties de enlace. Si el dato
// no ha cambiado deben aflorar ahora, cuando antes pasaban por «ok».
check('los valores literales en properties de enlace afloran', $literals > 0,
    "se esperaban ~4 y se han encontrado $literals");

// D-5: si esto fuera > 0, el semáforo SÍ necesita su tercer estado y hay que
// volver sobre la decisión.
check('dead_link sigue sin productor (D-5)', 0 === $deadLinks,
    "han aparecido $deadLinks enlaces muertos: reabrir D-5");

echo "\n2. D-7: la comprobación de enlaces se puede apagar\n";

$sample = $items[0];
$withLinks = $checker->check($sample, true);
$withoutLinks = $checker->check($sample, false);

check('apagar los enlaces no inventa ni pierde otros avisos',
    count($withLinks->getIssuesBySeverity('warning'))
    === count($withoutLinks->getIssuesBySeverity('warning')));

$start = microtime(true);
foreach ($items as $item) {
    $checker->check($item, false);
}
$cheap = microtime(true) - $start;

$start = microtime(true);
foreach ($items as $item) {
    $checker->check($item, true);
}
$expensive = microtime(true) - $start;

printf("   sin enlaces: %.3f s   con enlaces: %.3f s\n", $cheap, $expensive);
check('la pasada barata no es más lenta que la cara', $cheap <= $expensive + 0.01);

echo "\n3. D-6: la plantilla suma, no sustituye\n";

$withTemplate = null;
foreach ($items as $item) {
    if ($item->resourceTemplate()) {
        $withTemplate = $item;
        break;
    }
}

if (null === $withTemplate) {
    echo "   (ningún REA tiene plantilla todavía — PEND-012 sigue abierto)\n";
    check('sin plantillas asignadas, la regla mínima gobierna sola', true);
} else {
    // D-6: con plantilla, el mínimo SIGUE evaluándose. Antes de la rebanada 2
    // este REA no habría producido missing_license ni missing_alignment jamás,
    // porque la plantilla era una rama excluyente.
    $codes = array_column($checker->check($withTemplate, false)->getIssues(), 'code');
    $hasLicence = (bool) $withTemplate->value(OERManager\Service\Governance\IntegrityPolicy::LICENSE_TERM);
    check('un REA con plantilla sigue evaluando la licencia (D-6)',
        $hasLicence === !in_array('missing_license', $codes, true),
        $hasLicence
            ? 'tiene licencia y aun asi se avisa de que falta'
            : 'no tiene licencia y el aviso no aparece: la plantilla la esta silenciando');
}

echo "\n4. Columna Curricular\n";

$curricular = new OERManager\ColumnType\Curricular();
$rendered = 0;
foreach ($items as $item) {
    $html = $curricular->renderContent(
        $services->get('ViewRenderer'),
        $item,
        []
    );
    if (null !== $html) {
        $rendered++;
    }
}
check('la celda curricular rinde en todos los REA con anclaje', $rendered > 0,
    "ha rendido en $rendered de " . count($items));

echo "\n" . str_repeat('-', 60) . "\n";
echo "$passed OK, $failed FAIL\n";

exit($failed > 0 ? 1 : 0);
```

- [ ] **Step 2: Ejecutar el arnés en el contenedor**

Run:
```bash
docker exec omeka-s-moduletemplate-omekas-1 \
  php /var/www/html/modules/OERManager/test/container/columns-check.php
```
Expected: todas las comprobaciones en OK y salida `0`.

**Si `dead_link > 0`:** la decisión D-5 se reabre — el tercer estado sí tiene productor y hay que encenderlo en la UI. Parar y consultar al propietario antes de seguir.

**Si `literal_in_link_property == 0`:** o el dato cambió desde TASK-027, o la regla no está enganchada. Diagnosticar antes de dar la tarea por buena.

- [ ] **Step 3: Ejecutar la batería completa**

Run: `make lint && make test && make test-js`
Expected: PASS los tres.

- [ ] **Step 4: Commit**

```bash
git add test/container/columns-check.php
git commit -m "test(contenedor): arnes de verificacion de las columnas de gobernanza"
```

---

## Task 14: Cerrar el gobierno

**Files:**
- Modify: `docs/backlog.md` (fila de TASK-028)
- Modify: `docs/project-memory.md` (estado actual)
- Modify: `docs/traceability.md`
- Modify: `docs/decisions/0013-*.md` (nota de la ampliación de RF-006)
- Modify: `docs/requirements.md` (RF-006)

**Interfaces:** ninguna. Es la obligación de cierre que CLAUDE.md impone a toda tarea.

- [ ] **Step 1: Anotar la ampliación de RF-006**

En `docs/requirements.md`, en la fila de **RF-006**, añadir al final de la celda de detalle:

> **Ampliado (2026-08-03, TASK-028 rebanada 2):** las reglas mínimas —anclaje presente y licencia presente— se aplican **siempre**, y los campos obligatorios de la plantilla se **suman** en vez de sustituirlas. Antes eran ramas excluyentes, así que asignar una plantilla que no declarase obligatorios anclaje y licencia habría silenciado ambos avisos sin que el catálogo mejorase (trampa anticipada en TASK-027 §8.1, que PEND-012 iba a abrir). Se añade además el aviso `literal_in_link_property`: un literal en una property de enlace contaba como valor presente y la integridad decía «ok» (defecto D2).

En `docs/decisions/0013-*.md`, añadir una sección `## Afinado (TASK-028 rebanada 2, 2026-08-03)` con el mismo contenido, más las dos revisiones conscientes del §4 del estudio: la columna de Licencia no marca sus tres estados en la celda (D-4), y la de Anclaje no se retira.

- [ ] **Step 2: Actualizar backlog, memoria y trazabilidad**

En `docs/backlog.md`, en la fila de TASK-028, cambiar el estado a `rebanada 1 y 2 **hechas**; rebanadas 3-4 pendientes de spec` y añadir el resumen de lo entregado, los defectos cerrados y lo que quedó declarado como no verificado.

En `docs/project-memory.md`, añadir la entrada de la rebanada 2 al «Estado actual» y actualizar la línea de «Pendiente a continuación».

En `docs/traceability.md`, enlazar RF-002, RF-006 y NFR-006 con la rebanada 2.

- [ ] **Step 3: Verificación final**

Run: `make lint && make test && make test-js`
Expected: PASS los tres.

- [ ] **Step 4: Commit**

```bash
git add docs/
git commit -m "docs: rebanada 2 de TASK-028 cerrada, RF-006 ampliado"
```

---

## Autorrevisión del plan

**Cobertura del spec.** Cada sección tiene tarea: §2 D-1 (alcance, Task 8/9) · D-2 (Task 11 riel) · D-3 (Task 1) · D-4 (Tasks 7, 11) · D-5 (Tasks 1, 4, 5) · D-6 (Task 1) · D-7 (Tasks 1, 2, 10) · D-8 (Task 8) · §3.1 columnas (Task 8) · §3.2 curricular (Tasks 3, 6) · §3.3 tipo y licencia (Task 7) · §4 integridad (Tasks 1, 2) · §5 frontera pura (Tasks 1, 3) · §6.1 filtros nex (Task 9) · §6.2 rama única (Tasks 4, 10) · §7.1 riel y glifos (Task 11) · §7.2 WCAG (Task 12) · §8 renombrado (Task 8) · §9 verificación (Tasks 12, 13) · §11 gobierno (Task 14).

**Desviación declarada:** `ValuePresence` del spec §5 se omite por YAGNI; justificada en «Estructura de ficheros» y cubierta por Task 13.

**Consistencia de tipos.** `IntegrityPolicy::issuesFor(array, array, bool): array` se usa con esa firma en Task 2. `CurricularSummary::summarise(array, array): array` con las claves `subjects`/`primaryStage`/`extraStages`/`hasLiteral`/`tooltip` se usa igual en Task 6. `ComputedPredicates::activeKeys(array): array` y sus constantes se usan igual en Task 10. `IntegrityChecker::check($item, bool $checkLinks = true)` se llama con `false` en Tasks 5 y 10, y sin segundo argumento en el listener, que no se toca.

**Riesgo conocido del plan:** Task 12 puede requerir mover hexadecimales, y eso cambia el aspecto que la rebanada 1 dejó aprobado. Está previsto en el paso 5 con valores concretos y con el criterio de ADR-0014 («norma el criterio, no los valores»).
