# TASK-040 — Licencia en `dcterms:license` (URI) — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.
>
> **Carga obligatoria antes de tocar código:** skill `omeka-module` (todas las tareas) y skill `recatalogador` (Task 4: el arnés escribe y restaura un valor RDF en un REA real — trampa de `ValueHydrator`).

**Goal:** Que todo lo que el módulo supervisa como «licencia» lea `dcterms:license` (valor URI + etiqueta) en vez de `dcterms:rights`, avise si la licencia no es una URI, y nunca confunda «URI sin etiqueta» con «sin licencia».

**Architecture:** El término vive en una sola constante (`IntegrityPolicy::LICENSE_TERM`) y todos los consumidores la referencian. Una pieza pura nueva, `Governance\ValueText::of(?label, ?uri)`, fija el texto mostrable de un valor (etiqueta; si falta, URI) para PHP; `valueText()` hace lo mismo en JS. La integridad gana la regla `license_not_uri`, alimentada por un campo `hasUri` que `IntegrityChecker::project()` añade a la proyección plana.

**Tech Stack:** Omeka-S 4.2, PHP 8.4 (sin sintaxis 8.5), PHPUnit (`make test`), Node test runner (`make test-js`), PHPCS PSR-12 (`make lint`), módulo CustomVocab 2.0.2.

**Spec:** [`docs/decisions/0019-licencia-dcterms-license-uri.md`](../../decisions/0019-licencia-dcterms-license-uri.md) (ADR-0019, aceptado 2026-09-10). Tarea: TASK-040 en `docs/backlog.md`.

## Global Constraints

- Término de licencia: **`dcterms:license`**. `dcterms:rights` deja de leerse en el módulo; **no** se borra, **no** se migra, **no** se escribe.
- Control del valor: CustomVocab **de tipo URI** identificado por el setting existente `oermanager_licence_vocab_id`. El módulo **no** crea ni edita ese vocabulario ni la plantilla REA en esta tarea.
- Texto mostrable de una licencia: **etiqueta si la hay; si no, la URI**.
- Integridad: `missing_license` (warning) si falta; `license_not_uri` (warning, **uno por valor**) si un valor no lleva URI. Sin validación de pertenencia al vocabulario.
- Extender el core, nunca parchearlo; sin tablas Doctrine propias (NFR-002).
- Cadenas de UI nuevas: `// @translate` en PHP, `$translate(...)` en vistas. No hay `.po` en `language/` todavía: no hay catálogo que actualizar.
- Lint y test **en el host**, sin `docker compose exec`. Arnés de contenedor: `docker exec omeka-s-moduletemplate-omekas-1 php /var/www/html/modules/OERManager/test/container/<fichero>.php`.
- `git commit`/`push`: **pedir confirmación en el chat antes de cada uno** (CLAUDE.md). Nunca commitear en `main`.
- Modificar la skill `recatalogador` (preexistente): **pedir confirmación antes** (CLAUDE.md).

## File Structure

| Fichero | Acción | Responsabilidad |
| --- | --- | --- |
| `src/Service/Governance/IntegrityPolicy.php` | Modificar | Término `dcterms:license` + regla `license_not_uri` |
| `src/Service/IntegrityChecker.php` | Modificar | Proyección con `hasUri` |
| `test/Service/Governance/IntegrityPolicyTest.php` | Modificar | Tests de la regla nueva; licencia sana = URI |
| `src/Service/Governance/ValueText.php` | Crear | Texto mostrable: etiqueta o URI (puro) |
| `test/Service/Governance/ValueTextTest.php` | Crear | Tests de `ValueText` |
| `src/ColumnType/GovernanceValue.php` | Modificar | Celda con `ValueText` |
| `src/Service/ItemPanelData.php` | Modificar | Área «Información»: término + `ValueText` |
| `src/Service/Stats/DimensionFacts.php` | Modificar | Dimensión licencia: término + `ValueText` |
| `src/Service/MasterViewQuery.php` | Modificar | Filtro por licencia y «Sin licencia» con la constante |
| `config/module.config.php` | Modificar | Columna por defecto «Licencia» con la constante |
| `test/ModuleConfigTest.php` | Modificar | Expectativa de la columna |
| `src/Form/ConfigForm.php`, `src/Service/GovernanceSettings.php` | Modificar | Rótulo/ayuda del setting (vocabulario de URIs) |
| `view/oer-manager/admin/index/drawer-details.phtml` | Modificar | Rótulo del campo en el panel |
| `view/oer-manager/admin/index/search.phtml` | Modificar | Ayuda del filtro (etiqueta o URI exactas) |
| `test/Service/PanelAreasTest.php` | Modificar | Clave de ejemplo (cosmético) |
| `asset/js/core/values.js`, `asset/js/core/drawerModel.js` | Modificar | `valueText()` cae a la URI; campo del drawer |
| `test/js/drawerModel.test.js`, `test/js/integrityModel.test.js` | Modificar | Tests JS |
| `test/container/licence-check.php` | Crear | Arnés: lectura + escritura/restauración opcional |
| `docs/*` + skill `recatalogador` | Modificar | Cierre de gobierno (Task 5) |

---

### Task 0: Rama de trabajo

- [ ] **Step 1: Crear la rama desde `main`**

```bash
git switch -c task-040-licencia-dcterms-license
```

- [ ] **Step 2: Commit de los documentos de gobierno ya escritos (pedir confirmación antes)**

En el árbol de trabajo están: ADR-0019 (nuevo), nota de reemplazo en ADR-0004, PEND-011 reabierto en `docs/requirements.md`, TASK-040 en `docs/backlog.md` y este plan. **OJO:** `docs/backlog.md` contiene además TASK-039, un cambio previo del propietario sin commitear — preguntar si va en este commit o se separa (`git add -p docs/backlog.md`).

```bash
git add docs/decisions/0019-licencia-dcterms-license-uri.md docs/decisions/0004-mapeo-rdf-alineamiento-tags.md docs/requirements.md docs/backlog.md docs/superpowers/plans/2026-09-10-task-040-licencia-dcterms-license.md
git commit -m "docs: ADR-0019 — la licencia pasa a dcterms:license (URI); TASK-040 y plan"
```

---

### Task 1: Integridad — `dcterms:license` y regla `license_not_uri`

**Files:**
- Modify: `src/Service/Governance/IntegrityPolicy.php:45` (constante) y `:102-109` (bloque de licencia)
- Modify: `src/Service/IntegrityChecker.php:59-79` (`project()`)
- Test: `test/Service/Governance/IntegrityPolicyTest.php`

**Interfaces:**
- Produces: `IntegrityPolicy::LICENSE_TERM === 'dcterms:license'` (todas las tareas siguientes la usan). Forma del valor proyectado: `array{type:string, hasResource:bool, hasUri?:bool}` — `hasUri` ausente cuenta como `false`. Código nuevo de issue: `'license_not_uri'`.

- [ ] **Step 1: Escribir los tests que fallan**

En `IntegrityPolicyTest.php`, sustituir el `healthy()` actual (la licencia sana deja de ser un literal) y añadir los tests nuevos al final de la clase:

```php
    /** Un REA completo: las cuatro properties de anclaje enlazadas + licencia URI. */
    private function healthy(): array
    {
        $values = [IntegrityPolicy::LICENSE_TERM => [$this->licence()]];
        foreach (IntegrityPolicy::ALIGNMENT_TERMS as $term) {
            $values[$term] = [$this->link()];
        }
        return $values;
    }

    /** Licencia sana (ADR-0019): un valor con URI, del CustomVocab o nativo. */
    private function licence(string $type = 'uri'): array
    {
        return ['type' => $type, 'hasResource' => false, 'hasUri' => true];
    }
```

```php
    public function testLicenceTermIsDctermsLicense(): void
    {
        $this->assertSame('dcterms:license', IntegrityPolicy::LICENSE_TERM);
    }

    public function testCustomVocabUriLicenceIsHealthy(): void
    {
        $values = $this->healthy();
        $values[IntegrityPolicy::LICENSE_TERM] = [$this->licence('customvocab:4')];

        $this->assertSame([], IntegrityPolicy::issuesFor($values, [], false));
    }

    public function testLiteralLicenceIsNotAUri(): void
    {
        $values = $this->healthy();
        $values[IntegrityPolicy::LICENSE_TERM] = [['type' => 'literal', 'hasResource' => false, 'hasUri' => false]];

        $issues = IntegrityPolicy::issuesFor($values, [], false);

        $this->assertSame(['license_not_uri'], $this->codes($issues));
        $this->assertSame('warning', $issues[0]['severity']);
        $this->assertSame(IntegrityPolicy::LICENSE_TERM, $issues[0]['field']);
    }

    /** Sin la clave `hasUri` no se presume URI: mejor un aviso de más que uno de menos. */
    public function testLicenceWithoutHasUriFlagCountsAsNotUri(): void
    {
        $values = $this->healthy();
        $values[IntegrityPolicy::LICENSE_TERM] = [['type' => 'literal', 'hasResource' => false]];

        $this->assertSame(['license_not_uri'], $this->codes(IntegrityPolicy::issuesFor($values, [], false)));
    }

    public function testEachNonUriLicenceValueIsItsOwnWarning(): void
    {
        $values = $this->healthy();
        $values[IntegrityPolicy::LICENSE_TERM] = [
            ['type' => 'literal', 'hasResource' => false, 'hasUri' => false],
            $this->licence(),
            ['type' => 'customvocab:2', 'hasResource' => false, 'hasUri' => false],
        ];

        $this->assertSame(
            ['license_not_uri', 'license_not_uri'],
            $this->codes(IntegrityPolicy::issuesFor($values, [], false))
        );
    }
```

(`testMissingLicenceIsAWarning` ya existente sigue exigiendo exactamente `['missing_license']`: garantiza que una licencia ausente no dispara además `license_not_uri`.)

- [ ] **Step 2: Ejecutar y verificar que fallan**

Run: `vendor/bin/phpunit -c test/phpunit.xml --filter IntegrityPolicyTest`
Expected: FAIL — `testLicenceTermIsDctermsLicense` (`'dcterms:rights'` ≠ `'dcterms:license'`), `testLiteralLicenceIsNotAUri`, `testLicenceWithoutHasUriFlagCountsAsNotUri` y `testEachNonUriLicenceValueIsItsOwnWarning` (no emiten nada).

- [ ] **Step 3: Implementar en `IntegrityPolicy`**

Constante (línea 45):

```php
    /** Licencia del REA (ADR-0019): `dcterms:license`, guardada como URI. Antes `dcterms:rights` (ADR-0004 §5). */
    public const LICENSE_TERM = 'dcterms:license';
```

Docblock de `issuesFor()` — la forma de `$values` pasa a:

```php
     * @param array<string, list<array{type:string, hasResource:bool, hasUri?:bool}>> $values
     *        Término → sus valores. Basta con incluir los términos de anclaje,
     *        la licencia y los obligatorios de plantilla; el resto se ignora.
     *        `hasUri` solo se mira en la licencia; si falta, cuenta como false.
```

Sustituir el bloque `if ([] === ($values[self::LICENSE_TERM] ?? [])) { ... }` por:

```php
        $licenceValues = $values[self::LICENSE_TERM] ?? [];
        if ([] === $licenceValues) {
            $issues[] = [
                'severity' => 'warning',
                'code' => 'missing_license',
                'field' => self::LICENSE_TERM,
                'message' => 'El REA no tiene licencia asignada.', // @translate
            ];
        }

        // ADR-0019: la licencia se guarda como URI. Un literal («CC BY» metido
        // por la REST API, una importación o un CustomVocab de términos mal
        // apuntado en la configuración) tiene licencia pero no la que se puede
        // resolver. Un aviso por valor, como literal_in_link_property.
        foreach ($licenceValues as $value) {
            if (!($value['hasUri'] ?? false)) {
                $issues[] = [
                    'severity' => 'warning',
                    'code' => 'license_not_uri',
                    'field' => self::LICENSE_TERM,
                    'message' => 'La licencia no está guardada como URI.', // @translate
                ];
            }
        }
```

- [ ] **Step 4: Proyectar `hasUri` en `IntegrityChecker::project()`**

Docblock `@return array<string, list<array{type:string, hasResource:bool, hasUri:bool}>>` y, dentro del bucle, añadir la clave:

```php
                $projected[$term][] = [
                    'type' => $type,
                    'hasResource' => (!$checkLinks || !str_starts_with($type, 'resource'))
                        ? true
                        : (bool) $value->valueResource(),
                    // Barato: uri() es un getter de la entidad, no despierta proxies.
                    'hasUri' => '' !== trim((string) $value->uri()),
                ];
```

- [ ] **Step 5: Ejecutar y verificar que pasan**

Run: `vendor/bin/phpunit -c test/phpunit.xml --filter IntegrityPolicyTest`
Expected: PASS (todos, incluidos los ya existentes).

- [ ] **Step 6: Suite completa y lint**

Run: `make lint && make test`
Expected: lint limpio; todos los tests en verde (base actual: 376 tests; +5 nuevos = 381).

- [ ] **Step 7: Commit (pedir confirmación antes)**

```bash
git add src/Service/Governance/IntegrityPolicy.php src/Service/IntegrityChecker.php test/Service/Governance/IntegrityPolicyTest.php
git commit -m "feat(integridad): la licencia es dcterms:license y debe ser URI (ADR-0019)"
```

---

### Task 2: Texto mostrable de la licencia y consumidores PHP

**Files:**
- Create: `src/Service/Governance/ValueText.php`
- Test: `test/Service/Governance/ValueTextTest.php`
- Modify: `src/ColumnType/GovernanceValue.php:13,55-90`
- Modify: `src/Service/ItemPanelData.php:9,50-68`
- Modify: `src/Service/Stats/DimensionFacts.php:7,17-25,48-52`
- Modify: `src/Service/MasterViewQuery.php:5-7,23-28,89-95`
- Modify: `config/module.config.php:418-423`; Test: `test/ModuleConfigTest.php:154-160`
- Modify: `src/Form/ConfigForm.php:128-135`, `src/Service/GovernanceSettings.php:22`
- Modify: `view/oer-manager/admin/index/drawer-details.phtml:50`, `view/oer-manager/admin/index/search.phtml:111-112`
- Modify: `test/Service/PanelAreasTest.php:112`

**Interfaces:**
- Consumes: `IntegrityPolicy::LICENSE_TERM` (Task 1).
- Produces: `OERManager\Service\Governance\ValueText::of(?string $label, ?string $uri): string`.

- [ ] **Step 1: Escribir el test que falla**

`test/Service/Governance/ValueTextTest.php`:

```php
<?php

declare(strict_types=1);

namespace OERManager\Test\Service\Governance;

use OERManager\Service\Governance\ValueText;
use PHPUnit\Framework\TestCase;

/**
 * Texto mostrable de un valor (ADR-0019). En el core, un valor URI sin etiqueta
 * se convierte en '' (`toString()` devuelve `value()`, que es la etiqueta): sin
 * esta pieza, un REA con licencia se veía como «Sin licencia».
 */
final class ValueTextTest extends TestCase
{
    public function testLabelWinsOverUri(): void
    {
        $this->assertSame(
            'Creative Commons Attribution 4.0 International',
            ValueText::of('Creative Commons Attribution 4.0 International', 'https://creativecommons.org/licenses/by/4.0/')
        );
    }

    public function testUriWithoutLabelShowsTheUri(): void
    {
        $this->assertSame(
            'https://creativecommons.org/licenses/by/4.0/',
            ValueText::of(null, 'https://creativecommons.org/licenses/by/4.0/')
        );
    }

    public function testBlankLabelFallsBackToUri(): void
    {
        $this->assertSame('https://example.org/l', ValueText::of("  \n", ' https://example.org/l '));
    }

    public function testLiteralWithoutUriIsItsOwnText(): void
    {
        $this->assertSame('CC BY', ValueText::of(' CC BY ', null));
    }

    public function testNothingIsEmpty(): void
    {
        $this->assertSame('', ValueText::of(null, null));
        $this->assertSame('', ValueText::of('', ''));
    }
}
```

- [ ] **Step 2: Ejecutar y verificar que falla**

Run: `vendor/bin/phpunit -c test/phpunit.xml --filter ValueTextTest`
Expected: FAIL — `Class "OERManager\Service\Governance\ValueText" not found`.

- [ ] **Step 3: Implementar `ValueText`**

`src/Service/Governance/ValueText.php`:

```php
<?php

declare(strict_types=1);

namespace OERManager\Service\Governance;

/**
 * Texto mostrable de un valor RDF: su etiqueta y, si no la tiene, su URI.
 *
 * Existe por ADR-0019: la licencia se guarda como URI, y en el core 4.2 un
 * valor `uri`/`customvocab` convierte a cadena su `value()` —la etiqueta—, que
 * puede ir vacía. Leerlo con `(string) $value` hacía pasar un REA licenciado por
 * «sin licencia». Pura para poder probarse en el host.
 */
final class ValueText
{
    public static function of(?string $label, ?string $uri): string
    {
        $label = trim((string) $label);
        return '' !== $label ? $label : trim((string) $uri);
    }
}
```

- [ ] **Step 4: Ejecutar y verificar que pasa**

Run: `vendor/bin/phpunit -c test/phpunit.xml --filter ValueTextTest`
Expected: PASS (5 tests).

- [ ] **Step 5: Test de la columna por defecto (falla)**

En `test/ModuleConfigTest.php`, `testTheTwoGovernanceValueColumnsPointAtLicenceAndResourceType()`:

```php
        $this->assertSame(['lrmi:learningResourceType', 'dcterms:license'], $terms);
```

Run: `vendor/bin/phpunit -c test/phpunit.xml --filter ModuleConfigTest`
Expected: FAIL (`'dcterms:rights'` ≠ `'dcterms:license'`).

- [ ] **Step 6: Columna por defecto con la constante**

`config/module.config.php` (el fichero ya está en el namespace `OERManager`; usa `Service\...::class` en otros sitios):

```php
                [
                    'type' => 'oerGovernanceValue',
                    'property_term' => Service\Governance\IntegrityPolicy::LICENSE_TERM,
                    'header' => 'Licencia', // @translate
                    'empty_label' => 'Sin licencia', // @translate
                ],
```

Run: `vendor/bin/phpunit -c test/phpunit.xml --filter ModuleConfigTest`
Expected: PASS.

- [ ] **Step 7: `GovernanceValue` usa `ValueText`**

Añadir `use OERManager\Service\Governance\ValueText;`. En el docblock de clase, `Licencia (dcterms:rights)` → `Licencia (dcterms:license, URI — ADR-0019)`. En `renderContent()`, el bucle queda:

```php
        $texts = [];
        foreach ($values as $value) {
            // (string) $value es la etiqueta; una licencia URI sin etiqueta
            // daría '' y la celda diría «Sin licencia» (ADR-0019).
            $text = ValueText::of((string) $value, $value->uri());
            if ('' !== $text) {
                $texts[] = $text;
            }
        }
```

- [ ] **Step 8: `ItemPanelData::record()`**

Añadir `use OERManager\Service\Governance\IntegrityPolicy;` y `use OERManager\Service\Governance\ValueText;`. En `record()`:

```php
        $fields = [
            'dcterms:description',
            IntegrityPolicy::LICENSE_TERM,
            'schema:isPartOf',
            'lrmi:learningResourceType',
        ];
```

y dentro del bucle de valores:

```php
                $texts[] = $resource
                    ? (string) $resource->displayTitle()
                    : ValueText::of($value->value(), $value->uri());
```

- [ ] **Step 9: `DimensionFacts`**

Añadir `use OERManager\Service\Governance\IntegrityPolicy;` y `use OERManager\Service\Governance\ValueText;`. Comentario de `DIMENSION_TERMS`: `La licencia es literal y se trata aparte.` → `La licencia no es un enlace a item y se trata aparte.` Constante y extracción:

```php
    /** ADR-0019: una sola fuente para el término de licencia. */
    public const LICENCE_TERM = IntegrityPolicy::LICENSE_TERM;
```

```php
            // Se agrupa por el texto mostrable: con un CustomVocab de URIs la
            // etiqueta es la del vocabulario; sin etiqueta, la URI (ADR-0019 §6).
            $licenceValue = $item->value(self::LICENCE_TERM);
            $row['licencia'] = null !== $licenceValue
                ? ValueText::of($licenceValue->value(), $licenceValue->uri())
                : null;
            if ('' === $row['licencia']) {
                $row['licencia'] = null;
            }
```

- [ ] **Step 10: `MasterViewQuery`**

Añadir `use OERManager\Service\Governance\IntegrityPolicy;`. En `MISSING_FILTERS`: `'licence' => IntegrityPolicy::LICENSE_TERM,`. En el filtro por licencia:

```php
        // `eq` del core casa contra `value` O `uri` (buildPropertyQuery), así que
        // el filtro admite la etiqueta exacta o la URI exacta (ADR-0019).
        if (!empty($query['licence'])) {
            $params['property'][] = [
                'property' => IntegrityPolicy::LICENSE_TERM,
                'type' => 'eq',
                'text' => $query['licence'],
            ];
        }
```

- [ ] **Step 11: Rótulos (configuración, panel, búsqueda)**

`src/Form/ConfigForm.php`, campo `LICENCE_VOCAB_ID`:

```php
                'label' => 'CustomVocab de licencias (dcterms:license)', // @translate
                'info' => 'ID del CustomVocab de tipo URI con las licencias admitidas (URI canónica y '
                    . 'etiqueta). Si se deja vacío, la licencia se edita a mano y no se puede normalizar.', // @translate
```

`src/Service/GovernanceSettings.php:22`:

```php
    /** CustomVocab de licencias (dcterms:license, de tipo URI — ADR-0019). Lo siembra el módulo si falta. */
```

`view/oer-manager/admin/index/drawer-details.phtml:50`: `'dcterms:rights' => $translate('Licencia'),` → `'dcterms:license' => $translate('Licencia'),`.

`view/oer-manager/admin/index/search.phtml`, input `oer-search-licence`:

```php
            <input type="text" id="oer-search-licence" name="licence"
                   value="<?php echo $escape($query['licence'] ?? ''); ?>"
                   title="<?php echo $escape($translate('Etiqueta o URI exactas de la licencia')); ?>">
```

`test/Service/PanelAreasTest.php:112`: clave de ejemplo `'dcterms:rights'` → `'dcterms:license'`.

- [ ] **Step 12: Ningún `dcterms:rights` vivo en el módulo**

Run: `grep -rn "dcterms:rights" src config view asset/js test Module.php`
Expected: sin resultados (los únicos restos legítimos están en `docs/`).

- [ ] **Step 13: Suite completa y lint**

Run: `make lint && make test`
Expected: lint limpio; verde (381 + 5 de `ValueTextTest` = 386).

- [ ] **Step 14: Commit (pedir confirmación antes)**

```bash
git add src/Service/Governance/ValueText.php test/Service/Governance/ValueTextTest.php src/ColumnType/GovernanceValue.php src/Service/ItemPanelData.php src/Service/Stats/DimensionFacts.php src/Service/MasterViewQuery.php config/module.config.php test/ModuleConfigTest.php src/Form/ConfigForm.php src/Service/GovernanceSettings.php view/oer-manager/admin/index/drawer-details.phtml view/oer-manager/admin/index/search.phtml test/Service/PanelAreasTest.php
git commit -m "feat(licencia): columna, panel, estadísticas y filtros leen dcterms:license (ADR-0019)"
```

---

### Task 3: Núcleo JS — la URI es texto cuando no hay etiqueta

**Files:**
- Modify: `asset/js/core/values.js`, `asset/js/core/drawerModel.js:16`
- Test: `test/js/drawerModel.test.js`, `test/js/integrityModel.test.js:5`

**Interfaces:**
- Produces: `valueText(value)` devuelve `value['@id']` para un valor URI sin `o:label` (valor sin `value_resource_id`). `DRAWER_FIELDS[8]` = `['dcterms:license', 'Licencia']`.

- [ ] **Step 1: Escribir los tests que fallan**

En `test/js/drawerModel.test.js`, cambiar la expectativa del campo 9 y añadir un test:

```js
    assert.deepEqual(DRAWER_FIELDS[8], ['dcterms:license', 'Licencia']);
```

```js
test('valueText enseña la URI de un valor URI sin etiqueta (ADR-0019)', () => {
    const uri = 'https://creativecommons.org/licenses/by/4.0/';
    assert.equal(valueText({ '@id': uri }), uri);
    assert.equal(valueText({ '@id': uri, 'o:label': 'CC BY 4.0' }), 'CC BY 4.0');
    // Un valor de recurso también trae @id (la URL de la API): no es texto.
    assert.equal(valueText({ '@id': 'http://x/api/items/5', value_resource_id: 5, display_title: '' }), '');
});
```

En `test/js/integrityModel.test.js:5`, `field: 'dcterms:rights'` → `field: 'dcterms:license'` (cosmético).

- [ ] **Step 2: Ejecutar y verificar que fallan**

Run: `make test-js`
Expected: FAIL — el test nuevo (`''` ≠ la URI) y `los nueve campos del drawer` (`dcterms:rights`).

- [ ] **Step 3: Implementar**

`asset/js/core/values.js`, sustituir la última línea de `valueText()`:

```js
    if (value['o:label']) {
        return value['o:label'];
    }
    // Un valor URI sin etiqueta (ADR-0019: la licencia se guarda como URI) no
    // tiene más texto que la propia URI. Un valor de recurso también trae
    // `@id` —la URL de la API—, que no es texto que enseñar: lo delata
    // `value_resource_id`.
    if (value['@id'] && !value['value_resource_id']) {
        return value['@id'];
    }
    return '';
```

`asset/js/core/drawerModel.js:16`: `['dcterms:rights', 'Licencia']` → `['dcterms:license', 'Licencia']`.

- [ ] **Step 4: Ejecutar y verificar que pasan**

Run: `make test-js`
Expected: PASS (base 82 + 1 = 83).

- [ ] **Step 5: Commit (pedir confirmación antes)**

```bash
git add asset/js/core/values.js asset/js/core/drawerModel.js test/js/drawerModel.test.js test/js/integrityModel.test.js
git commit -m "feat(drawer): la licencia es dcterms:license y una URI sin etiqueta se muestra (ADR-0019)"
```

---

### Task 4: Arnés de contenedor `licence-check.php`

**Files:**
- Create: `test/container/licence-check.php`

**Interfaces:**
- Consumes: `IntegrityPolicy::LICENSE_TERM`, `IntegrityChecker::check()`, `ColumnType\GovernanceValue::renderContent()`, `ItemPanelData::forItem()`, `Stats\DimensionFacts::extract()`, `MasterViewQuery::buildSearchParams()`, `GovernanceSettings::LICENCE_VOCAB_ID`/`parseId()`, `CustomVocabRepresentation::type()`/`listUriLabels()`.

> ⚠️ **Skill `recatalogador` cargada antes de este paso.** La escritura usa `clear_property_values` + `collectionAction => 'append'`: un `update` parcial con values sin ese patrón borra en silencio el resto del item (incidente 2026-06-25). El arnés **se niega** a escribir en un REA que ya tenga `dcterms:license` — así restaurar es solo vaciar la property y no hay anotaciones que perder — y restaura en un `finally`.

- [ ] **Step 1: Escribir el arnés**

```php
<?php

/**
 * Arnés de contenedor de TASK-040 (ADR-0019): la licencia es dcterms:license, como URI.
 *
 * 1. SOLO LECTURA (siempre): sobre los REA reales, `missing_license` sale
 *    exactamente en los que no tienen dcterms:license; un REA que solo tiene
 *    dcterms:rights ya no cuenta como licenciado; la columna por defecto
 *    apunta a dcterms:license.
 * 2. ESCRITURA CON RESTAURACIÓN (solo con --write): en un REA SIN
 *    dcterms:license escribe, uno tras otro, una URI sin etiqueta, una URI con
 *    etiqueta, un literal y —si el setting apunta a un CustomVocab— un valor de
 *    ese vocabulario; comprueba integridad, columna, panel, estadísticas y
 *    filtros; y deja dcterms:license vacío como estaba. Comprueba al final que
 *    el resto del item no ha cambiado.
 *
 * Uso, desde el contenedor:
 *   php /var/www/html/modules/OERManager/test/container/licence-check.php
 *   php /var/www/html/modules/OERManager/test/container/licence-check.php --write <email> [item_id]
 *
 * Sale 1 si alguna comprobación falla.
 */

require '/var/www/html/bootstrap.php';

use OERManager\ColumnType\GovernanceValue;
use OERManager\Service\GovernanceSettings;
use OERManager\Service\Governance\IntegrityPolicy;
use OERManager\Service\Governance\ValueText;
use OERManager\Service\IntegrityChecker;
use OERManager\Service\ItemPanelData;
use OERManager\Service\MasterViewQuery;
use OERManager\Service\Stats\DimensionFacts;

$application = Omeka\Mvc\Application::init(require '/var/www/html/application/config/application.config.php');
$services = $application->getServiceManager();
$api = $services->get('Omeka\ApiManager');
/** @var IntegrityChecker $checker */
$checker = $services->get(IntegrityChecker::class);
$view = $services->get('ViewRenderer');

$passed = 0;
$failed = 0;
$skipped = 0;

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

function skip(string $label, string $why): void
{
    global $skipped;
    $skipped++;
    echo "  SKIP $label — $why\n";
}

/** Solo los códigos de licencia, en orden. */
function licenceCodes(IntegrityChecker $checker, $item): array
{
    return array_values(array_filter(
        array_column($checker->check($item, false)->getIssues(), 'code'),
        static fn (string $code): bool => in_array($code, ['missing_license', 'license_not_uri'], true)
    ));
}

$args = array_slice($argv, 1);
$writeMode = '--write' === ($args[0] ?? '');

if ($writeMode) {
    // Autenticar ANTES de leer: sin identidad, la API solo ve items públicos y
    // no deja escribir (mismo patrón que workflow-check.php).
    $email = (string) ($args[1] ?? '');
    $entityManager = $services->get('Omeka\EntityManager');
    $user = '' === $email ? null
        : $entityManager->getRepository(\Omeka\Entity\User::class)->findOneBy(['email' => $email]);
    if (null === $user) {
        fwrite(STDERR, "uso: php licence-check.php --write <emailUsuario> [item_id] (usuario no encontrado: '$email')\n");
        exit(2);
    }
    $services->get('Omeka\AuthenticationService')->getStorage()->write($user);
    printf("autenticado como %s (%s)\n", $user->getEmail(), $user->getRole());
}

$classes = $api->search('resource_classes', ['term' => MasterViewQuery::LEARNING_RESOURCE_CLASS_TERM])->getContent();
if (!$classes) {
    fwrite(STDERR, "No existe la clase lrmi:LearningResource en esta instalación.\n");
    exit(1);
}
$items = $api->search('items', ['resource_class_id' => $classes[0]->id()])->getContent();
if (!$items) {
    fwrite(STDERR, "No hay ningún REA en el catálogo.\n");
    exit(1);
}

$column = new GovernanceValue();
$columnData = ['property_term' => IntegrityPolicy::LICENSE_TERM, 'empty_label' => 'Sin licencia'];

echo "\n1. Solo lectura\n";

check('el término de licencia es dcterms:license', 'dcterms:license' === IntegrityPolicy::LICENSE_TERM);

$defaultTerms = array_values(array_filter(array_column(
    $services->get('Config')['column_defaults']['admin']['oer_items'] ?? [],
    'property_term'
)));
check('la columna por defecto «Licencia» apunta a dcterms:license',
    in_array('dcterms:license', $defaultTerms, true) && !in_array('dcterms:rights', $defaultTerms, true),
    'property_term: ' . implode(', ', $defaultTerms));

$withLicence = 0;
$rightsOnly = [];
$mismatch = [];
foreach ($items as $item) {
    $hasLicence = [] !== $item->value(IntegrityPolicy::LICENSE_TERM, ['all' => true, 'default' => []]);
    $missing = in_array('missing_license', licenceCodes($checker, $item), true);
    if ($hasLicence) {
        $withLicence++;
    } elseif (null !== $item->value('dcterms:rights')) {
        $rightsOnly[] = $item;
    }
    if ($missing === $hasLicence) {
        $mismatch[] = $item->id();
    }
}
printf("   REA=%d  con dcterms:license=%d  solo dcterms:rights=%d\n", count($items), $withLicence, count($rightsOnly));

check('missing_license sale exactamente en los REA sin dcterms:license', [] === $mismatch,
    'discrepan: #' . implode(', #', $mismatch));

if ([] === $rightsOnly) {
    skip('un REA con solo dcterms:rights se ve «Sin licencia» en la columna',
        'ningún REA visible tiene dcterms:rights sin dcterms:license');
} else {
    $cell = (string) $column->renderContent($view, $rightsOnly[0], $columnData);
    check(sprintf('un REA con solo dcterms:rights (#%d) se ve «Sin licencia» en la columna', $rightsOnly[0]->id()),
        str_contains($cell, 'oer-value-missing'), strip_tags($cell));
}

if (!$writeMode) {
    echo "\n(parte de escritura omitida: usar --write <email> [item_id])\n";
    echo "\n$passed OK, $failed FAIL, $skipped SKIP\n";
    exit($failed > 0 ? 1 : 0);
}

echo "\n2. Escritura con restauración\n";

$itemId = isset($args[2]) ? (int) $args[2] : null;
if (null === $itemId) {
    foreach ($items as $candidate) {
        if (null === $candidate->value(IntegrityPolicy::LICENSE_TERM)) {
            $itemId = (int) $candidate->id();
            break;
        }
    }
}
if (null === $itemId) {
    fwrite(STDERR, "Todos los REA tienen ya dcterms:license: pasar un item_id sin licencia.\n");
    exit(1);
}
$item = $api->read('items', $itemId)->getContent();
if (null !== $item->value(IntegrityPolicy::LICENSE_TERM)) {
    fwrite(STDERR, "El REA #$itemId ya tiene dcterms:license: el arnés no escribe sobre licencias reales.\n");
    exit(1);
}
$properties = $api->search('properties', ['term' => IntegrityPolicy::LICENSE_TERM])->getContent();
if (!$properties) {
    fwrite(STDERR, "No existe la property dcterms:license en esta instalación.\n");
    exit(1);
}
$pid = $properties[0]->id();
echo "   REA #$itemId, dcterms:license property_id=$pid\n";

/** Todo el item salvo la licencia y la fecha de modificación: lo que NO debe cambiar. */
$snapshot = static function ($item): array {
    $json = json_decode(json_encode($item), true);
    unset($json[IntegrityPolicy::LICENSE_TERM], $json['o:modified']);
    return $json;
};
$before = $snapshot($item);

// ⚠️ clear_property_values + append: el único patrón que no borra el resto del item.
$write = static function (array $licenceValues) use ($api, $itemId, $pid) {
    $api->update('items', $itemId, [
        IntegrityPolicy::LICENSE_TERM => $licenceValues,
        'clear_property_values' => [$pid],
    ], [], ['isPartial' => true, 'collectionAction' => 'append']);
    return $api->read('items', $itemId)->getContent();
};

$panelData = $services->get(ItemPanelData::class);
$facts = $services->get(DimensionFacts::class);
$query = $services->get(MasterViewQuery::class);

$observe = static function ($item) use ($checker, $column, $columnData, $view, $panelData, $facts): array {
    return [
        'codes' => licenceCodes($checker, $item),
        'cell' => trim(strip_tags((string) $column->renderContent($view, $item, $columnData))),
        'panel' => $panelData->forItem($item)['record'][IntegrityPolicy::LICENSE_TERM] ?? null,
        'stats' => $facts->extract([$item])[(int) $item->id()]['licencia'],
    ];
};

$found = static function (array $filters) use ($api, $query, $itemId): bool {
    $params = $query->buildSearchParams($filters);
    $params['id'] = $itemId;
    return 1 === $api->search('items', $params)->getTotalResults();
};

$uri = 'https://creativecommons.org/licenses/by/4.0/';
$label = 'Creative Commons Attribution 4.0 International';

try {
    // (a) URI nativa sin etiqueta: el caso que el core convierte en ''.
    $seen = $observe($write([['type' => 'uri', 'property_id' => $pid, '@id' => $uri]]));
    check('(a) URI sin etiqueta: sin avisos de licencia', [] === $seen['codes'], implode(',', $seen['codes']));
    check('(a) la celda enseña la URI', $uri === $seen['cell'], $seen['cell']);
    check('(a) el panel enseña la URI', $uri === $seen['panel'], (string) $seen['panel']);
    check('(a) estadísticas agrupan por la URI', $uri === $seen['stats'], (string) $seen['stats']);
    check('(a) el filtro por licencia casa con la URI exacta', $found(['licence' => $uri]));
    check('(a) el filtro «Sin licencia» ya no lo incluye', !$found(['missing' => ['licence']]));

    // (b) URI con etiqueta.
    $seen = $observe($write([['type' => 'uri', 'property_id' => $pid, '@id' => $uri, 'o:label' => $label]]));
    check('(b) URI con etiqueta: sin avisos de licencia', [] === $seen['codes'], implode(',', $seen['codes']));
    check('(b) la celda enseña la etiqueta', $label === $seen['cell'], $seen['cell']);
    check('(b) el panel enseña la etiqueta', $label === $seen['panel'], (string) $seen['panel']);
    check('(b) estadísticas agrupan por la etiqueta', $label === $seen['stats'], (string) $seen['stats']);
    check('(b) el filtro por licencia casa con la etiqueta exacta', $found(['licence' => $label]));

    // (c) Literal: tiene licencia, pero no es URI.
    $seen = $observe($write([['type' => 'literal', 'property_id' => $pid, '@value' => 'CC BY']]));
    check('(c) literal: avisa license_not_uri y no missing_license', ['license_not_uri'] === $seen['codes'],
        implode(',', $seen['codes']));
    check('(c) la celda sigue enseñando el literal', 'CC BY' === $seen['cell'], $seen['cell']);

    // (d) El vocabulario configurado.
    $vocabId = GovernanceSettings::parseId($services->get('Omeka\Settings')->get(GovernanceSettings::LICENCE_VOCAB_ID));
    if (null === $vocabId) {
        skip('(d) licencia desde el CustomVocab configurado', 'oermanager_licence_vocab_id vacío');
    } else {
        $vocab = $api->read('custom_vocabs', $vocabId)->getContent();
        $uriLabels = $vocab->listUriLabels() ?? [];
        if ('uri' === $vocab->type() && [] !== $uriLabels) {
            $vocabUri = (string) array_key_first($uriLabels);
            $seen = $observe($write([['type' => "customvocab:$vocabId", 'property_id' => $pid, '@id' => $vocabUri]]));
            check("(d) CustomVocab de URIs #$vocabId: sin avisos de licencia", [] === $seen['codes'],
                implode(',', $seen['codes']));
            // Misma pieza que la columna: el arnés no reimplementa la regla.
            check('(d) la celda enseña la etiqueta del vocabulario',
                ValueText::of($uriLabels[$vocabUri], $vocabUri) === $seen['cell'], $seen['cell']);
        } else {
            // Hoy (2026-09-10) el setting apunta al vocabulario 2, de TÉRMINOS:
            // el paso manual del propietario está pendiente. La regla nueva debe
            // delatarlo sola.
            $term = (string) (($vocab->listTerms() ?? [])[0] ?? 'CC BY');
            $seen = $observe($write([['type' => "customvocab:$vocabId", 'property_id' => $pid, '@value' => $term]]));
            check("(d) CustomVocab #$vocabId de términos: license_not_uri delata el vocabulario mal apuntado",
                ['license_not_uri'] === $seen['codes'], implode(',', $seen['codes']));
            skip('(d) licencia desde un CustomVocab de URIs',
                "el vocabulario #$vocabId es de tipo '" . $vocab->type() . "': falta el paso manual de ADR-0019");
        }
    }
} finally {
    // Restaurar: el REA no tenía dcterms:license, así que basta con vaciarla.
    $restored = $write([]);
    check("REA #$itemId restaurado sin dcterms:license", null === $restored->value(IntegrityPolicy::LICENSE_TERM));
    check('el resto del item no ha cambiado (título, descripción, anclaje…)', $before === $snapshot($restored));
}

echo "\n$passed OK, $failed FAIL, $skipped SKIP\n";
exit($failed > 0 ? 1 : 0);
```

- [ ] **Step 2: Sintaxis**

`make lint` ignora `test/` entero (`--ignore=...,test/`), así que no valida el arnés. Comprobar al menos la sintaxis con el PHP 8.4 del contenedor:

Run: `docker exec omeka-s-moduletemplate-omekas-1 php -l /var/www/html/modules/OERManager/test/container/licence-check.php`
Expected: `No syntax errors detected`.

- [ ] **Step 3: Ejecutar la parte de lectura**

Run: `docker exec omeka-s-moduletemplate-omekas-1 php /var/www/html/modules/OERManager/test/container/licence-check.php`
Expected con el dato de 2026-09-10: `REA=19 con dcterms:license=0 solo dcterms:rights=4` (si los 19 son públicos; sin autenticar la API solo ve públicos), `4 OK, 0 FAIL, 0 SKIP`, exit 0.

- [ ] **Step 4: Ejecutar la parte de escritura (pedir confirmación antes: escribe en el catálogo real y restaura)**

Run: `docker exec omeka-s-moduletemplate-omekas-1 php /var/www/html/modules/OERManager/test/container/licence-check.php --write editor@example.com`
Expected con el dato de 2026-09-10 (setting → vocabulario 2, de términos): lectura 4 OK, (a) 6 OK, (b) 5 OK, (c) 2 OK, (d) 1 OK + 1 SKIP, restauración 2 OK → `20 OK, 0 FAIL, 1 SKIP`, exit 0. Tras el paso manual del propietario (vocabulario de URIs), la rama (d) da 2 OK y 0 SKIP → `21 OK, 0 FAIL, 0 SKIP`.

Si falla «el resto del item no ha cambiado»: **parar**, no reintentar, e inspeccionar el diff de `$before`/`$snapshot($restored)` — es la señal del incidente de `ValueHydrator`.

- [ ] **Step 5: Regresión de los arneses existentes que tocan licencia**

Run: `docker exec omeka-s-moduletemplate-omekas-1 php /var/www/html/modules/OERManager/test/container/columns-check.php`
Expected: exit 0. El recuento `missing_license` pasa de 15 a 19 (los 4 REA con solo `dcterms:rights`); anotarlo en el cierre.

Run: `docker exec omeka-s-moduletemplate-omekas-1 php /var/www/html/modules/OERManager/test/container/stats-check.php` y `.../detail-panel-check.php`
Expected: exit 0 en ambos.

- [ ] **Step 6: Commit (pedir confirmación antes)**

```bash
git add test/container/licence-check.php
git commit -m "test(contenedor): arnés licence-check para dcterms:license (ADR-0019)"
```

---

### Task 5: Cierre de gobierno y pasos manuales del propietario

**Files:**
- Modify: `docs/backlog.md` (TASK-040 → `hecha`, con evidencia), `docs/traceability.md` (fila «Control de autoría y licencia»), `docs/project-memory.md` (entrada nueva)
- Modify (con confirmación): `.claude/skills/recatalogador/SKILL.md` (fila «Licencia del REA» de la tabla de mapeo)

- [ ] **Step 1: Suites finales**

Run: `make lint && make test && make test-js`
Expected: lint limpio; PHP 386 tests; JS 83 tests. Copiar las cifras reales al backlog.

- [ ] **Step 2: Skill `recatalogador` (pedir confirmación antes)**

Fila de la tabla de mapeo:

```markdown
| Licencia del REA | `dcterms:license` | URI (+ etiqueta) | `CustomVocab` de tipo URI (setting `oermanager_licence_vocab_id`) | curación/integridad — ADR-0019; `dcterms:rights` ya no se lee |
```

- [ ] **Step 3: Registros**

- `docs/backlog.md`: TASK-040 → `hecha`, añadiendo commits, cifras de suites, salida de `licence-check.php` (lectura y escritura) y el cambio de `missing_license` 15 → 19 en `columns-check.php`.
- `docs/traceability.md`, fila «Control de autoría y licencia del REA»: ADR `ADR-0019 (licencia = dcterms:license URI; reemplaza ADR-0004 §5)` y TASK `TASK-040`; criterio: «`missing_license` exactamente en los REA sin `dcterms:license`; `license_not_uri` para valores sin URI; URI sin etiqueta visible en columna/panel/estadísticas/drawer».
- `docs/project-memory.md`: entrada TASK-040 con el hallazgo del core (URI sin etiqueta → cadena vacía) y el efecto visible (4 REA pasan a «Sin licencia»).

- [ ] **Step 4: Comunicar los pasos manuales al propietario (no son código)**

1. En *Custom Vocab* del admin, crear un vocabulario **de tipo URI** con una línea «URI etiqueta» por licencia (contenido = PEND-011, decisión editorial suya; p. ej. `https://creativecommons.org/licenses/by/4.0/ Creative Commons Attribution 4.0 International`).
2. En la configuración del módulo, apuntar «CustomVocab de licencias (dcterms:license)» a su id.
3. En la plantilla REA (id 3), sustituir `dcterms:rights`/`customvocab:2` por `dcterms:license` con el vocabulario nuevo.
4. Volver a ejecutar `licence-check.php --write ...`: el SKIP de (d) debe convertirse en OK.
5. Avisar a los curadores: los 4 REA que tenían licencia en `dcterms:rights` aparecerán «Sin licencia» hasta rellenar `dcterms:license`.

- [ ] **Step 5: Commit de cierre (pedir confirmación antes)**

```bash
git add docs/backlog.md docs/traceability.md docs/project-memory.md .claude/skills/recatalogador/SKILL.md
git commit -m "docs: cierra TASK-040 — licencia en dcterms:license (ADR-0019)"
```

---

## Self-Review

- **Cobertura del spec (ADR-0019):** §1 término único → Tasks 1-3 + grep del Task 2 Step 12. §2 URI desde CustomVocab por setting → rótulos Task 2 Step 11, verificación Task 4 (d), pasos manuales Task 5 Step 4. §3 texto mostrable → `ValueText` (Task 2) + `valueText()` (Task 3). §4 integridad → Task 1. §5 olvidar `dcterms:rights` → Task 2 Step 12 + Task 4 lectura. §6 estadísticas por texto mostrable → Task 2 Step 9 + Task 4 (a)/(b). Consecuencias: skill y registros → Task 5; PEND-011 ya reabierto (Task 0).
- **Tipos coherentes:** `IntegrityPolicy::LICENSE_TERM`, `ValueText::of(?string, ?string): string`, código `license_not_uri`, clave `hasUri` — mismos nombres en todas las tareas.
- **Sin validación de pertenencia al vocabulario** (fuera de alcance por decisión del propietario); ningún paso la introduce.
