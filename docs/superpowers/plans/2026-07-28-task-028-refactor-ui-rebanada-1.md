# TASK-028 rebanada 1 — refactor puro de la UI: plan de implementación

> **Para agentes ejecutores:** SUB-SKILL OBLIGATORIA: usa `superpowers:subagent-driven-development` (recomendada) o `superpowers:executing-plans` para ejecutar este plan tarea a tarea. Los pasos usan casillas (`- [ ]`) para el seguimiento.

**Objetivo:** reestructurar la vista maestra y su JavaScript sin añadir ni un campo nuevo a la UI, cerrando de paso los defectos D1, D4, D5, D6 y D7 del estudio de TASK-027.

**Arquitectura:** en PHP, las columnas dejan de instanciarse a mano en la plantilla y pasan al mecanismo nativo de browse de Omeka bajo una clave propia (`oer_items`), y `MasterViewQuery` se parte para que los filtros computados usen un `ComputedFilter` con tope duro en lugar de cribar la página ya paginada. En JavaScript, el IIFE de 680 líneas se trocea en módulos ES nativos con una frontera dura entre un `core/` puro y testeado y un `ui/` de pegamento jQuery.

**Stack:** PHP 8.4, Omeka-S 4.2, PHPUnit ^11.5, jQuery (global del admin), módulos ES nativos, runner de tests integrado de Node (`node --test`).

## Restricciones globales

- **PHP 8.4**, nunca sintaxis de 8.5, aunque el host tenga 8.5 para tooling.
- **PSR-12**; `make lint` debe quedar en verde (hay Stop hook que lo exige).
- **Extender el core, nunca parchearlo**: solo `attachListeners`, `module.config.php` y mecanismos nativos.
- **Sin tablas Doctrine propias** (NFR-002). Sin PHPStan ni Psalm.
- **Sin dependencias nuevas de npm**: `package.json` con `type: module` y **cero dependencias**. Sin bundler.
- **Frontera dura del JS**: ningún fichero de `asset/js/core/` puede mencionar `$`, `jQuery`, `document`, `window`, `fetch` ni `localStorage`.
- **Sin cambios de comportamiento en el re-catalogador ni en el propose IA**: son el componente de alto riesgo y deben comportarse exactamente igual. Fuera de ahí, los únicos cambios permitidos son los cinco defectos que la rebanada cierra y la nueva disposición de los filtros (decisión D-5). *(Acotación decidida por el propietario el 2026-07-28: la redacción anterior, «sin cambios de comportamiento» a secas, contradecía la tarea 15.)*
- Namespace de tests PHP: `OERManager\Test\…`, `declare(strict_types=1)`, clases `final`.
- Los textos de UI van en español y marcados para i18n (`// @translate` en PHP, `Omeka.jsTranslate(...)` en JS).
- Autorización vigente del propietario: **se puede editar el `Makefile`** solo para añadir el target de tests de JS.

---

## Mapa de ficheros

**Se crean**

| Fichero | Responsabilidad |
| --- | --- |
| `package.json` | `type: module`, cero dependencias |
| `asset/js/config.js` | Lee el dataset de la tabla una sola vez |
| `asset/js/main.js` | Entrada única; cablea los módulos de `ui/` |
| `asset/js/core/messages.js` | Código de error → mensaje, con fallback |
| `asset/js/core/proposalState.js` | Máquina de estados del sondeo del propose |
| `asset/js/core/values.js` | Valor RDF del JSON-LD → texto mostrable |
| `asset/js/core/drawerModel.js` | JSON del item → filas del drawer |
| `asset/js/core/extraction.js` | `content.sources`/`skipped` → resumen legible |
| `asset/js/core/proposalMerge.js` | Qué candidatos de la IA faltan por añadir |
| `asset/js/core/diffModel.js` | Respuesta del preview → filas con títulos |
| `asset/js/ui/visibility.js` | Curación de visibilidad individual y en lote |
| `asset/js/ui/drawer.js` | Apertura, render y cierre del drawer |
| `asset/js/ui/termPicker.js` | Widget de selección de términos (marcado de Chosen) |
| `asset/js/ui/recatalog.js` | Panel de re-catalogación: preview, apply, diff |
| `asset/js/ui/aiPropose.js` | Arranque, sondeo, cancelación y volcado de debug |
| `src/ColumnType/Value.php` … | Cinco subclases finas que declaran `oer_items` |
| `src/Service/ComputedFilter.php` | Ids con tope duro → predicado → paginar |
| `src/Service/ResourceTypeVocab.php` | Lee el CustomVocab de tipos; degrada si no hay |
| `test/js/*.test.js` | Tests de los siete módulos de `core/` |
| `test/Service/ComputedFilterTest.php` | Tests de paginación, total y tope |
| `test/Service/ResourceTypeVocabTest.php` | Tests de degradación del vocabulario |
| `view/oer-manager/admin/index/search.phtml` | Pantalla de búsqueda avanzada |

> **Desviación respecto al spec §4.2:** su lista de módulos incluía un `ui/filters.js` que este plan **no crea**. Al montar los filtros curriculares de la búsqueda avanzada con el mismo marcado que el drawer (tarea 15), `ui/termPicker.js` los gobierna sin código propio, y un módulo aparte solo añadiría una capa vacía. Si en la rebanada de gobernanza los filtros ganan comportamiento propio, se creará entonces.

**Se modifican**

| Fichero | Cambio |
| --- | --- |
| `asset/js/oer-master-view.js` | Se vacía progresivamente y se **elimina** en la tarea 11 |
| `config/module.config.php` | `column_types`, `column_defaults`, servicios nuevos |
| `src/ColumnType/AlignmentStatus.php` | `getResourceTypes()` → `['items', 'oer_items']` |
| `src/Service/MasterViewQuery.php` | D1 (`res`→`eq`); deja de cribar en memoria |
| `src/Service/RecatalogService.php` | `preview()` añade el mapa `titles` |
| `src/Service/CurriculumSearch.php` | `mapResults()` añade el linaje |
| `src/Controller/Admin/IndexController.php` | Usa `ComputedFilter`; acción `search` |
| `view/oer-manager/admin/index/index.phtml` | Columnas nativas; barra rápida; chips |
| `Module.php` | Elementos del formulario de usuario; filtros de `searchFilters` |
| `Makefile` | Target `test-js` |
| `.github/workflows/ci.yml` | Paso de tests de JS |

---

## Tarea 1: Infraestructura de tests de JS y primer módulo puro

**Ficheros**
- Crear: `package.json`, `asset/js/core/messages.js`, `test/js/messages.test.js`
- Modificar: `Makefile:26-32`, `.github/workflows/ci.yml:42`

**Interfaces**
- Produce: `messageFor(code, fallback)` → `string`. Lo consumen las tareas 9, 10 y 11.

Hoy hay **tres mapas de mensajes de error duplicados** en `oer-master-view.js` (preview, apply y propose) con distinto juego de claves. Esta tarea los unifica en uno y, de paso, monta el andamiaje de tests que usarán todas las tareas de JS.

- [ ] **Paso 1: crear `package.json`**

```json
{
  "name": "oer-manager",
  "private": true,
  "type": "module",
  "description": "Módulo OERManager de Omeka-S: núcleo JS puro y sus tests (TASK-028)."
}
```

- [ ] **Paso 2: escribir el test que falla**

`test/js/messages.test.js`:

```js
import test from 'node:test';
import assert from 'node:assert/strict';
import { messageFor } from '../../asset/js/core/messages.js';

test('devuelve el mensaje del código conocido', () => {
    assert.equal(messageFor('csrf'), 'Token de seguridad caducado: recarga la página.');
});

test('cae al texto por defecto cuando el código es desconocido', () => {
    assert.equal(messageFor('lo-que-sea', 'Error genérico.'), 'Error genérico.');
});

test('cae al texto por defecto cuando no hay código', () => {
    assert.equal(messageFor('', 'Error genérico.'), 'Error genérico.');
    assert.equal(messageFor(undefined, 'Error genérico.'), 'Error genérico.');
});

test('cubre los códigos que hoy emite el backend', () => {
    ['csrf', 'denied', 'not_found', 'disabled', 'dispatch', 'llm', 'invalid', 'unexpected']
        .forEach((code) => {
            assert.notEqual(messageFor(code), '', `falta mensaje para ${code}`);
        });
});
```

- [ ] **Paso 3: ejecutar el test y verificar que falla**

Ejecuta: `node --test test/js/messages.test.js`
Esperado: FAIL — no se puede resolver `../../asset/js/core/messages.js`.

- [ ] **Paso 4: implementación mínima**

`asset/js/core/messages.js`:

```js
/**
 * Mensajes de error del módulo (TASK-028). Único mapa: antes había tres
 * duplicados con distinto juego de claves. Núcleo puro: sin DOM ni traducción
 * (traduce quien pinta, en ui/).
 */
const MESSAGES = {
    csrf: 'Token de seguridad caducado: recarga la página.',
    denied: 'No tienes permiso para re-catalogar.',
    not_found: 'No se encontró el recurso.',
    disabled: 'La asistencia IA no está configurada.',
    dispatch: 'No se pudo iniciar el análisis en segundo plano.',
    llm: 'El proveedor de IA falló. Revisa el log de Omeka.',
    invalid: 'Hay destinos inválidos en la propuesta.',
    unexpected: 'Error inesperado; inténtalo de nuevo.'
};

export function messageFor(code, fallback = '') {
    if (!code) {
        return fallback;
    }
    return MESSAGES[code] || fallback;
}
```

- [ ] **Paso 5: ejecutar el test y verificar que pasa**

Ejecuta: `node --test test/js/messages.test.js`
Esperado: PASS, 4 tests.

- [ ] **Paso 6: añadir el target al `Makefile`**

Insértalo justo detrás del target `test` (línea 32), respetando los tabuladores:

```makefile
# Tests del núcleo JS (TASK-028). Runner integrado de Node: sin dependencias
# ni node_modules. Solo cubre asset/js/core/, que es puro por contrato.
.PHONY: test-js
test-js:
	@echo "Running JS core tests..."
	node --test test/js/
```

Y añade su línea a la ayuda, junto a la de `test`:

```makefile
	@echo "  test-js           - Run JS core unit tests (node --test)"
```

- [ ] **Paso 7: verificar el target**

Ejecuta: `make test-js`
Esperado: PASS, 4 tests.

- [ ] **Paso 8: añadir el paso al CI**

En `.github/workflows/ci.yml`, detrás del paso «Run unit tests»:

```yaml
      - name: Run JS core tests
        run: make test-js
```

No hace falta `actions/setup-node`: el runner de `ubuntu-latest` trae Node con `--test` integrado.

- [ ] **Paso 9: verificar que nada se rompe**

Ejecuta: `make lint && make test && make test-js`
Esperado: los tres en verde.

- [ ] **Paso 10: commit**

```bash
git add package.json asset/js/core/messages.js test/js/messages.test.js Makefile .github/workflows/ci.yml
git commit -m "test(js): andamiaje de tests de núcleo JS y mapa único de mensajes"
```

---

## Tarea 2: `core/proposalState.js` — la máquina de estados del sondeo

**Ficheros**
- Crear: `asset/js/core/proposalState.js`, `test/js/proposalState.test.js`

**Interfaces**
- Consume: nada.
- Produce: `decide({ status, error, attempt, maxAttempts })` → `{ action, message?, payload? }` donde `action` ∈ `'retry' | 'done' | 'error' | 'stopped' | 'timeout'`. Lo consume la tarea 11.
- Produce: `POLL_MS = 3000`, `POLL_MAX = 240`.

Hoy esta lógica vive entre `oer-master-view.js:585-615`, enredada con `setTimeout`, `localStorage` y jQuery, y solo se puede ejercitar lanzando trabajos reales contra el LLM. Los valores y las reglas se copian **tal cual** del código actual: es un refactor, no un rediseño.

- [ ] **Paso 1: escribir el test que falla**

`test/js/proposalState.test.js`:

```js
import test from 'node:test';
import assert from 'node:assert/strict';
import { decide, POLL_MS, POLL_MAX } from '../../asset/js/core/proposalState.js';

test('conserva la cadencia y el techo del código actual', () => {
    assert.equal(POLL_MS, 3000);
    assert.equal(POLL_MAX, 240);
});

test('in_progress reintenta', () => {
    const r = decide({ status: 'in_progress', attempt: 0, maxAttempts: POLL_MAX });
    assert.equal(r.action, 'retry');
});

test('in_progress agotado da timeout, no reintento infinito', () => {
    const r = decide({ status: 'in_progress', attempt: POLL_MAX + 1, maxAttempts: POLL_MAX });
    assert.equal(r.action, 'timeout');
    assert.equal(r.message, 'La propuesta tarda demasiado. Reintenta más tarde.');
});

test('stopped no se confunde con error', () => {
    const r = decide({ status: 'stopped', attempt: 3, maxAttempts: POLL_MAX });
    assert.equal(r.action, 'stopped');
    assert.equal(r.message, 'Propuesta cancelada.');
});

test('status de error y error en el cuerpo dan ambos error', () => {
    assert.equal(decide({ status: 'error', attempt: 1, maxAttempts: POLL_MAX }).action, 'error');
    assert.equal(decide({ status: 'completed', error: 'llm', attempt: 1, maxAttempts: POLL_MAX }).action, 'error');
});

test('completed sin error entrega el payload', () => {
    const payload = { alignment: {} };
    const r = decide({ status: 'completed', payload, attempt: 5, maxAttempts: POLL_MAX });
    assert.equal(r.action, 'done');
    assert.equal(r.payload, payload);
});

test('un fallo de red reintenta en vez de abortar', () => {
    // Comportamiento deliberado del código actual (rama .fail del sondeo):
    // un corte puntual no debe tirar un job que sigue vivo en el servidor.
    const r = decide({ status: 'network_error', attempt: 2, maxAttempts: POLL_MAX });
    assert.equal(r.action, 'retry');
});

test('un fallo de red también respeta el techo', () => {
    const r = decide({ status: 'network_error', attempt: POLL_MAX + 1, maxAttempts: POLL_MAX });
    assert.equal(r.action, 'timeout');
});
```

- [ ] **Paso 2: ejecutar y verificar que falla**

Ejecuta: `node --test test/js/proposalState.test.js`
Esperado: FAIL — módulo no encontrado.

- [ ] **Paso 3: implementar**

`asset/js/core/proposalState.js`:

```js
/**
 * Máquina de estados del sondeo del propose asíncrono (TASK-020, extraída en
 * TASK-028). Núcleo puro: decide, no ejecuta. Quien temporiza, guarda el jobId
 * y pinta es ui/aiPropose.js.
 */
export const POLL_MS = 3000;
export const POLL_MAX = 240; // ~12 min de techo de sondeo

export function decide({ status, error, payload, attempt, maxAttempts = POLL_MAX }) {
    if (attempt > maxAttempts) {
        return { action: 'timeout', message: 'La propuesta tarda demasiado. Reintenta más tarde.' };
    }
    // Un corte de red puntual no tumba un job que sigue vivo en el servidor.
    if ('in_progress' === status || 'network_error' === status) {
        return { action: 'retry' };
    }
    if ('stopped' === status) {
        return { action: 'stopped', message: 'Propuesta cancelada.' };
    }
    if ('error' === status || error) {
        return { action: 'error', message: 'El proveedor de IA falló. Revisa el log de Omeka.' };
    }
    return { action: 'done', payload };
}
```

- [ ] **Paso 4: ejecutar y verificar que pasa**

Ejecuta: `make test-js`
Esperado: PASS, 12 tests en total.

- [ ] **Paso 5: commit**

```bash
git add asset/js/core/proposalState.js test/js/proposalState.test.js
git commit -m "test(js): máquina de estados del sondeo del propose como núcleo puro"
```

---

## Tarea 3: `core/values.js` y `core/drawerModel.js`

**Ficheros**
- Crear: `asset/js/core/values.js`, `asset/js/core/drawerModel.js`, `test/js/drawerModel.test.js`

**Interfaces**
- Produce: `valueText(value)` → `string`.
- Produce: `DRAWER_FIELDS` → `Array<[term, label]>` (los nueve campos actuales, en orden).
- Produce: `drawerRows(itemJson, fields = DRAWER_FIELDS)` → `Array<{term, label, text}>`.
- Produce: `drawerTitle(itemJson)` → `string`.
- Los consume la tarea 9.

Traduce `oer-master-view.js:9-22` (la lista de campos) y `:147-176` (el render) sin cambiar el comportamiento: se omiten las filas vacías. El estado vacío explícito que pide ADR-0013 **no entra aquí**; este módulo es donde entrará en la rebanada siguiente.

- [ ] **Paso 1: escribir el test que falla**

`test/js/drawerModel.test.js`:

```js
import test from 'node:test';
import assert from 'node:assert/strict';
import { valueText } from '../../asset/js/core/values.js';
import { drawerRows, drawerTitle, DRAWER_FIELDS } from '../../asset/js/core/drawerModel.js';

test('valueText prefiere el título del recurso enlazado', () => {
    assert.equal(valueText({ display_title: 'Matemáticas', '@value': 'x' }), 'Matemáticas');
});

test('valueText cae al literal y luego a la etiqueta de la URI', () => {
    assert.equal(valueText({ '@value': 'ccbysa' }), 'ccbysa');
    assert.equal(valueText({ 'o:label': 'CC BY-SA' }), 'CC BY-SA');
    assert.equal(valueText({}), '');
});

test('los nueve campos del drawer se conservan y en orden', () => {
    assert.equal(DRAWER_FIELDS.length, 9);
    assert.deepEqual(DRAWER_FIELDS[0], ['dcterms:description', 'Descripción']);
    assert.deepEqual(DRAWER_FIELDS[8], ['dcterms:rights', 'Licencia']);
});

test('drawerRows une los valores múltiples con coma', () => {
    const rows = drawerRows({
        'schema:about': [{ display_title: 'Matemáticas' }, { display_title: 'Física' }]
    });
    assert.equal(rows.length, 1);
    assert.equal(rows[0].term, 'schema:about');
    assert.equal(rows[0].label, 'Materia');
    assert.equal(rows[0].text, 'Matemáticas, Física');
});

test('drawerRows omite los campos vacíos (comportamiento actual)', () => {
    const rows = drawerRows({ 'dcterms:description': [] });
    assert.deepEqual(rows, []);
});

test('drawerTitle usa o:title y cae a dcterms:title', () => {
    assert.equal(drawerTitle({ 'o:title': 'Célula' }), 'Célula');
    assert.equal(drawerTitle({ 'dcterms:title': [{ '@value': 'Célula' }] }), 'Célula');
    assert.equal(drawerTitle({}), '');
});
```

- [ ] **Paso 2: ejecutar y verificar que falla**

Ejecuta: `node --test test/js/drawerModel.test.js`
Esperado: FAIL — módulos no encontrados.

- [ ] **Paso 3: implementar `values.js`**

```js
/**
 * Texto mostrable de un valor del JSON-LD de Omeka (TASK-028). Núcleo puro.
 */
export function valueText(value) {
    if (!value) {
        return '';
    }
    if (value['display_title']) {
        return value['display_title'];
    }
    if (value['@value']) {
        return value['@value'];
    }
    return value['o:label'] || '';
}
```

- [ ] **Paso 4: implementar `drawerModel.js`**

```js
import { valueText } from './values.js';

/**
 * Campos del drawer de detalle (ADR-0005 v1). Se conservan los nueve de la v1;
 * el catálogo ampliado de ADR-0013 entra en la rebanada siguiente.
 */
export const DRAWER_FIELDS = [
    ['dcterms:description', 'Descripción'],
    ['lrmi:educationalLevel', 'Etapa'],
    ['schema:about', 'Materia'],
    ['lrmi:assesses', 'Criterios de evaluación'],
    ['lrmi:teaches', 'Saberes básicos'],
    ['dcterms:relation', 'Eje temático'],
    ['schema:isPartOf', 'Proyecto'],
    ['lrmi:learningResourceType', 'Tipo de recurso'],
    ['dcterms:rights', 'Licencia']
];

export function drawerTitle(itemJson) {
    if (itemJson['o:title']) {
        return itemJson['o:title'];
    }
    const title = itemJson['dcterms:title'];
    return (title && title[0]) ? valueText(title[0]) : '';
}

/**
 * Filas del drawer. Omite los campos sin valor, igual que la v1.
 */
export function drawerRows(itemJson, fields = DRAWER_FIELDS) {
    const rows = [];
    fields.forEach(([term, label]) => {
        const values = itemJson[term] || [];
        if (!values.length) {
            return;
        }
        rows.push({ term, label, text: values.map(valueText).join(', ') });
    });
    return rows;
}
```

- [ ] **Paso 5: ejecutar y verificar que pasa**

Ejecuta: `make test-js`
Esperado: PASS.

- [ ] **Paso 6: commit**

```bash
git add asset/js/core/values.js asset/js/core/drawerModel.js test/js/drawerModel.test.js
git commit -m "test(js): modelo del drawer y texto de valores como núcleo puro"
```

---

## Tarea 4: `core/extraction.js` y `core/proposalMerge.js`

**Ficheros**
- Crear: `asset/js/core/extraction.js`, `asset/js/core/proposalMerge.js`, `test/js/extraction.test.js`, `test/js/proposalMerge.test.js`

**Interfaces**
- Produce: `extractionSummary(content)` → `string`. Lo consume la tarea 11.
- Produce: `pendingChips(alignment, currentIdsByTerm, justifications)` → `{ [term]: Array<{id, title, justification}> }`. Lo consume la tarea 11.

`proposalMerge` es donde vive la invariante de TASK-023: la justificación viaja **con** el chip y no se pinta. Sin test, un descuido futuro la rompe en silencio.

- [ ] **Paso 1: escribir los tests que fallan**

`test/js/extraction.test.js`:

```js
import test from 'node:test';
import assert from 'node:assert/strict';
import { extractionSummary } from '../../asset/js/core/extraction.js';

test('sin datos devuelve cadena vacía', () => {
    assert.equal(extractionSummary(null), '');
});

test('sin fuentes lo dice explícitamente', () => {
    assert.equal(extractionSummary({ sources: [], skipped: {} }), 'leídos: (ninguno)');
});

test('lista fuentes y saltados con su motivo', () => {
    const summary = extractionSummary({
        sources: ['guia.pdf'],
        skipped: { 'foto.png': 'unsupported', 'escaneo.pdf': 'pdf_iconv_unsupported' }
    });
    assert.equal(
        summary,
        'leídos: guia.pdf | saltados: foto.png → unsupported; escaneo.pdf → pdf_iconv_unsupported'
    );
});
```

`test/js/proposalMerge.test.js`:

```js
import test from 'node:test';
import assert from 'node:assert/strict';
import { pendingChips } from '../../asset/js/core/proposalMerge.js';

test('propone solo lo que no está ya puesto', () => {
    const pending = pendingChips(
        { 'schema:about': [{ id: 7, title: 'Matemáticas' }, { id: 9, title: 'Física' }] },
        { 'schema:about': ['7'] },
        {}
    );
    assert.deepEqual(pending['schema:about'], [{ id: 9, title: 'Física', justification: '' }]);
});

test('compara ids con independencia del tipo', () => {
    const pending = pendingChips(
        { 'lrmi:teaches': [{ id: 12, title: 'Saber' }] },
        { 'lrmi:teaches': [12] },
        {}
    );
    assert.deepEqual(pending['lrmi:teaches'], []);
});

test('adjunta la justificación de saberes y criterios', () => {
    const pending = pendingChips(
        { 'lrmi:teaches': [{ id: 4, title: 'La célula' }] },
        {},
        { 'lrmi:teaches': { 4: 'Aborda estructuras celulares.' } }
    );
    assert.equal(pending['lrmi:teaches'][0].justification, 'Aborda estructuras celulares.');
});

test('sin justificación devuelve cadena vacía, nunca undefined', () => {
    const pending = pendingChips({ 'schema:about': [{ id: 1, title: 'X' }] }, {}, {});
    assert.equal(pending['schema:about'][0].justification, '');
});

test('tolera alineamiento ausente', () => {
    assert.deepEqual(pendingChips(null, {}, {}), {});
});
```

- [ ] **Paso 2: ejecutar y verificar que fallan**

Ejecuta: `node --test test/js/extraction.test.js test/js/proposalMerge.test.js`
Esperado: FAIL en ambos — módulos no encontrados.

- [ ] **Paso 3: implementar `extraction.js`**

```js
/**
 * Resumen «leídos / saltados» de la extracción de medios (TASK-017). Es lo que
 * explica por qué una propuesta de IA sale pobre. Núcleo puro.
 */
export function extractionSummary(content) {
    if (!content) {
        return '';
    }
    const sources = content.sources || [];
    const skipped = content.skipped || {};
    const names = Object.keys(skipped);
    const parts = ['leídos: ' + (sources.length ? sources.join(', ') : '(ninguno)')];
    if (names.length) {
        parts.push('saltados: ' + names.map((n) => n + ' → ' + skipped[n]).join('; '));
    }
    return parts.join(' | ');
}
```

- [ ] **Paso 4: implementar `proposalMerge.js`**

```js
/**
 * Qué chips de la propuesta IA faltan por añadir al panel (TASK-010/023).
 * La justificación viaja CON el chip y no se pinta: la decisión vigente es no
 * anclar al curador antes de que revise (TASK-023). Núcleo puro.
 */
export function pendingChips(alignment, currentIdsByTerm, justifications) {
    const pending = {};
    const just = justifications || {};
    Object.keys(alignment || {}).forEach((term) => {
        const current = (currentIdsByTerm[term] || []).map(String);
        const termJust = just[term] || {};
        pending[term] = (alignment[term] || [])
            .filter((candidate) => -1 === current.indexOf(String(candidate.id)))
            .map((candidate) => ({
                id: candidate.id,
                title: candidate.title,
                justification: termJust[candidate.id] || ''
            }));
    });
    return pending;
}
```

- [ ] **Paso 5: ejecutar y verificar que pasan**

Ejecuta: `make test-js`
Esperado: PASS.

- [ ] **Paso 6: commit**

```bash
git add asset/js/core/extraction.js asset/js/core/proposalMerge.js test/js/extraction.test.js test/js/proposalMerge.test.js
git commit -m "test(js): resumen de extracción y merge de la propuesta IA como núcleo puro"
```

---

## Tarea 5: títulos en el preview del re-catalogador (mitad servidor de D7)

**Ficheros**
- Modificar: `src/Service/RecatalogService.php` (`preview()`, `currentTargetIds()`, `invalidTargets()`)

> **Esta tarea no lleva test automático, y es deliberado** (decisión del propietario, 2026-07-28). `RecatalogService` depende del core de Omeka y el arnés del host no puede instanciarlo — la misma limitación documentada en TASK-003. Un test que construyera a mano la estructura esperada pasaría desde el primer momento sin ejercitar código de producción: da falsa cobertura y ensucia la suite. **El cambio se valida solo en contenedor (paso 4)** y así queda declarado en el gobierno de la tarea 16.

**Interfaces**
- Produce: `preview()` devuelve por dimensión, además de lo que ya devolvía, `'titles' => array<int,string>` (id → título) que cubre `current ∪ next`. Lo consume la tarea 6.

**El estudio de TASK-027 se equivocaba** al decir que el backend ya devuelve títulos: `currentTargetIds()` recoge `$resource->id()` y `normalizeIds()` hace `intval`. Sin este cambio, D7 no se puede cerrar.

Los títulos salen **sin lecturas nuevas**: los de `current` del `valueResource()` que ya se recorre, y los de `next` del `api->read()` por id que `invalidTargets()` ya ejecuta.

- [ ] **Paso 1: implementar en `RecatalogService`**

Cambia `currentTargetIds()` para que devuelva también títulos y ajusta `preview()`:

```php
    /**
     * @return array{ids:int[],titles:array<int,string>}
     */
    private function currentTargets(ItemRepresentation $item, string $term): array
    {
        $ids = [];
        $titles = [];
        foreach ($item->value($term, ['all' => true, 'default' => []]) as $value) {
            $resource = $value->valueResource();
            if ($resource) {
                $id = (int) $resource->id();
                $ids[] = $id;
                $titles[$id] = (string) $resource->displayTitle();
            }
        }
        sort($ids);
        return ['ids' => $ids, 'titles' => $titles];
    }
```

En `invalidTargets()`, recoge el título de los que sí existen mediante un parámetro por referencia:

```php
    private function invalidTargets(string $term, array $ids, array &$titles = []): array
    {
        $invalid = [];
        foreach ($ids as $id) {
            try {
                $item = $this->api->read('items', $id)->getContent();
            } catch (\Exception $e) {
                $invalid[] = $id;
                continue;
            }
            $titles[(int) $id] = (string) $item->displayTitle();
            if (!$this->matchesDimension($term, $item)) {
                $invalid[] = $id;
            }
        }
        return $invalid;
    }
```

Y en `preview()`:

```php
            $currentTargets = $this->currentTargets($item, $term);
            $current = $currentTargets['ids'];
            $titles = $currentTargets['titles'];
            $next = $this->normalizeIds($proposed[$term]);
            $invalid = $this->invalidTargets($term, $next, $titles);
            $diff[$term] = [
                'current' => $current,
                'next' => $next,
                'added' => array_values(array_diff($next, $current)),
                'removed' => array_values(array_diff($current, $next)),
                'invalid' => $invalid,
                // D7: el cliente necesita títulos para que el curador confirme
                // viendo QUÉ cambia, no solo cuántos.
                'titles' => $titles,
            ];
```

**Ojo:** `apply()` también llama a `invalidTargets()`. Al tener el tercer parámetro valor por defecto, esa llamada sigue funcionando sin cambios. Comprueba que no queda ninguna llamada a `currentTargetIds()`; si `apply()` la usaba, sustitúyela por `$this->currentTargets(...)['ids']`.

- [ ] **Paso 2: ejecutar lint y tests**

Ejecuta: `make lint && make test`
Esperado: ambos en verde, sin regresión en los tests existentes de `RecatalogService`.

- [ ] **Paso 3: verificar en contenedor**

Reutiliza el arnés de `test/container/`: lanza un `recatalog-preview` real sobre el item **#3181** con una dimensión modificada y comprueba en el JSON de respuesta que `diff['schema:about']['titles']` trae los títulos de los ids de `current` y `next`.

- [ ] **Paso 4: commit**

```bash
git add src/Service/RecatalogService.php
git commit -m "feat(recatalog): el preview devuelve títulos además de ids (D7, mitad servidor)"
```

---

## Tarea 6: `core/diffModel.js` — el diff deja de ser un recuento

**Ficheros**
- Crear: `asset/js/core/diffModel.js`, `test/js/diffModel.test.js`

**Interfaces**
- Consume: la clave `titles` que produce la tarea 5.
- Produce: `RECATALOG_DIMENSIONS` → `Array<[term, label]>` (las cinco actuales).
- Produce: `diffRows(diff, dimensions = RECATALOG_DIMENSIONS)` → `{ rows: Array<{term, label, added: string[], removed: string[], invalid: string[], unchanged: boolean}>, hasInvalid: boolean }`.
- Lo consume la tarea 10.

- [ ] **Paso 1: escribir el test que falla**

`test/js/diffModel.test.js`:

```js
import test from 'node:test';
import assert from 'node:assert/strict';
import { diffRows, RECATALOG_DIMENSIONS } from '../../asset/js/core/diffModel.js';

const DIFF = {
    'schema:about': {
        current: [7], next: [9], added: [9], removed: [7], invalid: [],
        titles: { 7: 'Matemáticas (1º ESO)', 9: 'Física y Química (3º ESO)' }
    }
};

test('las cinco dimensiones del re-catalogador se conservan', () => {
    assert.equal(RECATALOG_DIMENSIONS.length, 5);
    assert.deepEqual(RECATALOG_DIMENSIONS[0], ['lrmi:educationalLevel', 'Curso']);
});

test('muestra títulos, no recuentos (D7)', () => {
    const { rows } = diffRows(DIFF);
    const row = rows.find((r) => r.term === 'schema:about');
    assert.deepEqual(row.added, ['Física y Química (3º ESO)']);
    assert.deepEqual(row.removed, ['Matemáticas (1º ESO)']);
    assert.equal(row.unchanged, false);
});

test('cae al id cuando falta el título', () => {
    const { rows } = diffRows({
        'schema:about': { current: [], next: [42], added: [42], removed: [], invalid: [], titles: {} }
    });
    assert.deepEqual(rows[0].added, ['#42']);
});

test('marca los destinos inválidos y lo propaga', () => {
    const { rows, hasInvalid } = diffRows({
        'lrmi:teaches': { current: [], next: [3], added: [3], removed: [], invalid: [3], titles: {} }
    });
    assert.equal(hasInvalid, true);
    assert.deepEqual(rows[0].invalid, ['#3']);
});

test('una dimensión sin cambios se marca como tal', () => {
    const { rows } = diffRows({
        'schema:about': { current: [7], next: [7], added: [], removed: [], invalid: [], titles: { 7: 'X' } }
    });
    assert.equal(rows[0].unchanged, true);
});

test('omite las dimensiones que el preview no devuelve', () => {
    const { rows } = diffRows(DIFF);
    assert.equal(rows.length, 1);
});

test('tolera un diff vacío', () => {
    assert.deepEqual(diffRows({}), { rows: [], hasInvalid: false });
});
```

- [ ] **Paso 2: ejecutar y verificar que falla**

Ejecuta: `node --test test/js/diffModel.test.js`
Esperado: FAIL — módulo no encontrado.

- [ ] **Paso 3: implementar**

```js
/**
 * Modelo del diff del re-catalogador (TASK-028, D7). Antes se pintaba «+N/−M»:
 * confirmar una escritura RDF viendo solo un número era el punto débil del
 * flujo preview→confirmar. Núcleo puro.
 */
export const RECATALOG_DIMENSIONS = [
    ['lrmi:educationalLevel', 'Curso'],
    ['schema:about', 'Asignatura'],
    ['lrmi:teaches', 'Saberes básicos'],
    ['lrmi:assesses', 'Criterios de evaluación'],
    ['dcterms:relation', 'Eje temático']
];

function label(titles, id) {
    return (titles && titles[id]) ? titles[id] : '#' + id;
}

export function diffRows(diff, dimensions = RECATALOG_DIMENSIONS) {
    const rows = [];
    let hasInvalid = false;
    dimensions.forEach(([term, dimensionLabel]) => {
        const entry = diff[term];
        if (!entry) {
            return;
        }
        const titles = entry.titles || {};
        const invalid = (entry.invalid || []).map((id) => label(titles, id));
        if (invalid.length) {
            hasInvalid = true;
        }
        const added = (entry.added || []).map((id) => label(titles, id));
        const removed = (entry.removed || []).map((id) => label(titles, id));
        rows.push({
            term,
            label: dimensionLabel,
            added,
            removed,
            invalid,
            unchanged: !added.length && !removed.length
        });
    });
    return { rows, hasInvalid };
}
```

- [ ] **Paso 4: ejecutar y verificar que pasa**

Ejecuta: `make test-js`
Esperado: PASS.

- [ ] **Paso 5: commit**

```bash
git add asset/js/core/diffModel.js test/js/diffModel.test.js
git commit -m "test(js): modelo del diff con títulos (D7, mitad cliente)"
```

---

## Tarea 7: columnas registradas bajo la clave `oer_items` (D5 y D6)

**Ficheros**
- Crear: `src/ColumnType/Value.php`, `src/ColumnType/IsPublic.php`, `src/ColumnType/Modified.php`, `src/ColumnType/Id.php`, `src/ColumnType/ResourceTemplate.php`
- Modificar: `src/ColumnType/AlignmentStatus.php:26-29`, `config/module.config.php:276-280`, `view/oer-manager/admin/index/index.phtml:20-27,84-107`

**Interfaces**
- Produce: la clave de configuración `oer_items` y los nombres de tipo `oerValue`, `oerIsPublic`, `oerModified`, `oerId`, `oerResourceTemplate`, `oerAlignmentStatus`. Los consume la tarea 8.

Los tipos del core declaran sus tipos de recurso a fuego (`Omeka\ColumnType\Value::getResourceTypes()` devuelve `['items','item_sets','media']`) y no hay evento para ampliarlos, así que sin subclases el selector de columnas del curador quedaría vacío de los tipos del core.

- [ ] **Paso 1: crear las cinco subclases**

`src/ColumnType/Value.php` (las otras cuatro son idénticas cambiando la clase padre):

```php
<?php

namespace OERManager\ColumnType;

/**
 * Tipo de columna «Valor» disponible en la vista maestra (TASK-028).
 *
 * Los tipos del core declaran sus tipos de recurso a fuego y no hay evento para
 * ampliarlos, así que sin esta subclase el selector de columnas de la vista
 * maestra no ofrecería este tipo. Solo cambia getResourceTypes(): el
 * renderizado, el formulario de datos y la ordenación se heredan.
 */
class Value extends \Omeka\ColumnType\Value
{
    public function getResourceTypes(): array
    {
        return ['oer_items'];
    }
}
```

Repite con `IsPublic extends \Omeka\ColumnType\IsPublic`, `Modified extends \Omeka\ColumnType\Modified`, `Id extends \Omeka\ColumnType\Id` y `ResourceTemplate extends \Omeka\ColumnType\ResourceTemplate`, cada una con su propio bloque de documentación.

- [ ] **Paso 2: ampliar `AlignmentStatus`**

En `src/ColumnType/AlignmentStatus.php:26-29`:

```php
    public function getResourceTypes(): array
    {
        // 'items' se conserva: quitarlo retiraría la posibilidad, hoy
        // existente, de añadir esta columna al browse nativo de items.
        return ['items', 'oer_items'];
    }
```

- [ ] **Paso 3: registrar tipos y columnas por defecto**

En `config/module.config.php`, sustituye el bloque `column_types` y añade `column_defaults` y `browse_defaults`:

```php
    // Vista maestra (TASK-003/028): tipos propios bajo la clave `oer_items`,
    // que es independiente de la del browse nativo de items.
    'column_types' => [
        'invokables' => [
            'oerAlignmentStatus' => ColumnType\AlignmentStatus::class,
            'oerIsPublic' => ColumnType\IsPublic::class,
            'oerModified' => ColumnType\Modified::class,
            'oerId' => ColumnType\Id::class,
            'oerResourceTemplate' => ColumnType\ResourceTemplate::class,
        ],
        'factories' => [
            // Value necesita FormElementManager y ApiManager, igual que el del core.
            'oerValue' => function ($container) {
                return new ColumnType\Value(
                    $container->get('FormElementManager'),
                    $container->get('Omeka\ApiManager')
                );
            },
        ],
    ],
    // Mismas seis columnas que la v1; el reequilibrio de ADR-0013 es posterior.
    'column_defaults' => [
        'admin' => [
            'oer_items' => [
                ['type' => 'oerIsPublic'],
                ['type' => 'oerValue', 'property_term' => 'lrmi:educationalLevel', 'max_values' => 1],
                ['type' => 'oerValue', 'property_term' => 'schema:about', 'max_values' => 1],
                ['type' => 'oerAlignmentStatus'],
                ['type' => 'oerValue', 'property_term' => 'dcterms:rights', 'max_values' => 1],
                ['type' => 'oerModified'],
            ],
        ],
    ],
    'browse_defaults' => [
        'admin' => [
            'oer_items' => ['sort_by' => 'modified', 'sort_order' => 'desc'],
        ],
    ],
```

> Verifica la clave de servicio del `FormElementManager` en el contenedor antes de dar el paso por bueno; si el core la registra con otro nombre, usa el que use `Omeka\Service\ColumnType\ValueFactory`.

- [ ] **Paso 4: usar el mecanismo nativo en la plantilla**

En `view/oer-manager/admin/index/index.phtml`, elimina el array `$columns` (líneas 20-27) y la variable `$columnTypeManager` (línea 13), y sustituye cabecera y celdas:

```php
        <tr>
            <th><input type="checkbox" class="oer-select-all" aria-label="<?php echo $escape($translate('Seleccionar todo')); ?>"></th>
            <th><?php echo $translate('Título'); ?></th>
            <?php echo $this->browse()->renderHeaderRow('oer_items'); ?>
        </tr>
```

```php
            <?php echo $this->browse()->renderContentRow('oer_items', $item); ?>
```

Y añade el selector de orden en el primer `browse-controls`, antes de la paginación:

```php
    <?php echo $this->browse()->renderSortSelector('oer_items'); ?>
```

- [ ] **Paso 5: lint y tests**

Ejecuta: `make lint && make test`
Esperado: verde. `test/ModuleConfigTest.php` valida el contrato del array de configuración; si comprueba las claves de `column_types`, actualízalo para incluir los tipos nuevos.

- [ ] **Paso 6: verificar en contenedor**

Abre la vista maestra en el admin. Criterios: aparecen **las seis columnas de siempre con el mismo contenido**; aparece el selector de orden y al cambiarlo la tabla se reordena; el browse nativo de items (`/admin/item`) **no ha cambiado**.

- [ ] **Paso 7: commit**

```bash
git add src/ColumnType config/module.config.php view/oer-manager/admin/index/index.phtml test/ModuleConfigTest.php
git commit -m "refactor(ui): columnas por el mecanismo nativo bajo la clave oer_items (D5, D6)"
```

---

## Tarea 8: columnas configurables por curador

**Ficheros**
- Modificar: `Module.php` (`attachListeners`)

**Interfaces**
- Consume: la clave `oer_items` de la tarea 7.

Omeka monta la configuración de columnas del admin como elementos del formulario de usuario (`Omeka\Form\UserForm`, que declara `columns_admin_items` y compañía). El módulo añade los suyos por evento, sin tocar el core.

- [ ] **Paso 1: añadir el listener**

En `Module::attachListeners()`:

```php
        // Columnas y orden por defecto de la vista maestra, configurables por
        // cada curador desde su perfil (TASK-028). Se usa la clave propia
        // `oer_items`: compartir la de `items` haría que configurar esta tabla
        // cambiase el browse nativo del admin, y al revés.
        $sharedEventManager->attach(
            \Omeka\Form\UserForm::class,
            'form.add_elements',
            [$this, 'addBrowseConfigElements']
        );
```

Y el método:

```php
    public function addBrowseConfigElements(Event $event): void
    {
        /** @var \Omeka\Form\UserForm $form */
        $form = $event->getTarget();
        $userId = $form->getOption('user_id');

        $form->add([
            'name' => 'columns_admin_oer_items',
            'type' => \Omeka\Form\Element\Columns::class,
            'options' => [
                'label' => 'Columnas de la vista maestra de REA', // @translate
                'columns_context' => 'admin',
                'columns_resource_type' => 'oer_items',
                'columns_user_id' => $userId,
            ],
        ]);
        $form->add([
            'name' => 'browse_defaults_admin_oer_items',
            'type' => \Omeka\Form\Element\BrowseDefaults::class,
            'options' => [
                'label' => 'Orden por defecto de la vista maestra de REA', // @translate
                'browse_defaults_context' => 'admin',
                'browse_defaults_resource_type' => 'oer_items',
                'browse_defaults_user_id' => $userId,
            ],
        ]);
    }
```

> Comprueba en el contenedor el nombre exacto de la opción del formulario que lleva el id de usuario (`Omeka\Form\UserForm` la usa para sus propios elementos) y usa el mismo.

- [ ] **Paso 2: lint y tests**

Ejecuta: `make lint && make test`
Esperado: verde.

- [ ] **Paso 3: verificar en contenedor**

Edita tu usuario en el admin. Criterios: aparecen los dos campos nuevos; quitar una columna, guardar y volver a la vista maestra la refleja; **volver a añadirla es posible** (esto es lo que prueban las subclases de la tarea 7); el browse nativo de items sigue igual.

- [ ] **Paso 4: commit**

```bash
git add Module.php
git commit -m "feat(ui): columnas y orden de la vista maestra configurables por curador"
```

---

## Tarea 9: `ComputedFilter` — el filtro «parcial» deja de mentir (D4)

**Ficheros**
- Crear: `src/Service/ComputedFilter.php`, `test/Service/ComputedFilterTest.php`
- Modificar: `src/Service/MasterViewQuery.php:103-109`, `src/Controller/Admin/IndexController.php:84-116`, `config/module.config.php`

**Interfaces**
- Produce: `ComputedFilter::apply(array $ids, callable $predicate, int $page, int $perPage): array` → `['ids' => int[], 'total' => int, 'truncated' => bool]`.
- Produce: `ComputedFilter::HARD_CAP = 2000`.
- Lo consumirán los filtros computados de ADR-0013 en rebanadas siguientes.

Hoy `filterPartialAlignment()` criba **la página ya paginada**: el total es aproximado y el número de filas varía entre páginas. ADR-0013 declara obligatorio el patrón resolver ids → predicado → paginar para cualquier filtro computado.

- [ ] **Paso 1: escribir el test que falla**

`test/Service/ComputedFilterTest.php`:

```php
<?php

declare(strict_types=1);

namespace OERManager\Test\Service;

use OERManager\Service\ComputedFilter;
use PHPUnit\Framework\TestCase;

/**
 * D4 (TASK-028): un filtro computado debe dar total exacto y filas estables.
 * El patrón —ids con tope duro, predicado, paginar después— es el que ADR-0013
 * declara obligatorio para todos los filtros computados de ADR-0013.
 */
final class ComputedFilterTest extends TestCase
{
    private function evens(): callable
    {
        return static fn (int $id): bool => 0 === $id % 2;
    }

    public function testTotalIsExactNotApproximate(): void
    {
        $filter = new ComputedFilter();
        $result = $filter->apply(range(1, 10), $this->evens(), 1, 3);

        $this->assertSame(5, $result['total']);
        $this->assertSame([2, 4, 6], $result['ids']);
        $this->assertFalse($result['truncated']);
    }

    public function testPagesAreStableAndFull(): void
    {
        $filter = new ComputedFilter();
        $this->assertSame([8, 10], $filter->apply(range(1, 10), $this->evens(), 2, 3)['ids']);
    }

    public function testPageBeyondTheEndIsEmptyNotAnError(): void
    {
        $filter = new ComputedFilter();
        $this->assertSame([], $filter->apply(range(1, 10), $this->evens(), 9, 3)['ids']);
    }

    public function testPageZeroOrNegativeIsTreatedAsFirstPage(): void
    {
        $filter = new ComputedFilter();
        $this->assertSame([2, 4, 6], $filter->apply(range(1, 10), $this->evens(), 0, 3)['ids']);
    }

    public function testHardCapTruncatesAndSaysSo(): void
    {
        $filter = new ComputedFilter(4);
        $result = $filter->apply(range(1, 10), $this->evens(), 1, 10);

        // Solo se evalúan los 4 primeros ids: 1,2,3,4 → pares 2 y 4.
        $this->assertSame([2, 4], $result['ids']);
        $this->assertSame(2, $result['total']);
        $this->assertTrue($result['truncated']);
    }

    public function testDefaultCapIsTwoThousand(): void
    {
        $this->assertSame(2000, ComputedFilter::HARD_CAP);
    }
}
```

- [ ] **Paso 2: ejecutar y verificar que falla**

Ejecuta: `make test`
Esperado: FAIL — `Class "OERManager\Service\ComputedFilter" not found`.

- [ ] **Paso 3: implementar**

`src/Service/ComputedFilter.php`:

```php
<?php

namespace OERManager\Service;

/**
 * Patrón obligatorio de los filtros computados (ADR-0013): resolver los ids del
 * conjunto completo con tope duro, evaluar el predicado sobre la lista entera y
 * paginar después.
 *
 * El defecto que corrige (D4) es cribar la página ya paginada, que da un total
 * aproximado y un número de filas variable entre páginas. El tope protege el
 * crecimiento del catálogo; cuando se alcanza, el resultado se marca como
 * truncado para que la UI pueda decirlo en vez de mentir en silencio.
 */
class ComputedFilter
{
    public const HARD_CAP = 2000;

    private int $cap;

    public function __construct(int $cap = self::HARD_CAP)
    {
        $this->cap = max(1, $cap);
    }

    /**
     * @param int[] $ids Conjunto completo, sin paginar
     * @param callable(int):bool $predicate
     * @return array{ids:int[],total:int,truncated:bool}
     */
    public function apply(array $ids, callable $predicate, int $page, int $perPage): array
    {
        $truncated = count($ids) > $this->cap;
        $ids = array_slice(array_values($ids), 0, $this->cap);

        $matching = array_values(array_filter($ids, $predicate));

        $page = max(1, $page);
        $perPage = max(1, $perPage);
        $offset = ($page - 1) * $perPage;

        return [
            'ids' => array_slice($matching, $offset, $perPage),
            'total' => count($matching),
            'truncated' => $truncated,
        ];
    }
}
```

- [ ] **Paso 4: ejecutar y verificar que pasa**

Ejecuta: `make test`
Esperado: PASS, 6 tests nuevos.

- [ ] **Paso 5: registrar el servicio**

En `config/module.config.php`, dentro de `service_manager.invokables`:

```php
            // Patrón de filtros computados (ADR-0013, D4).
            Service\ComputedFilter::class => Service\ComputedFilter::class,
```

- [ ] **Paso 6: cablearlo en el controlador**

En `IndexController::indexAction()`, sustituye la criba sobre la página por el patrón completo. El filtro «parcial» pasa a: pedir **solo ids** con el filtro base, evaluar el estado sobre cada uno y paginar después.

```php
        if ($isPartialFilter) {
            // D4: se resuelve el conjunto completo por ids y se pagina después;
            // cribar la página ya paginada daba un total aproximado.
            $idsResponse = $this->api()->search('items', $searchParams + ['returnScalar' => 'id']);
            $filtered = $this->computedFilter->apply(
                array_map('intval', array_values($idsResponse->getContent())),
                fn (int $id): bool => AlignmentStatus::PARTIAL === AlignmentStatus::statusFor(
                    $this->api()->read('items', $id)->getContent()
                ),
                (int) ($query['page'] ?? 1),
                (int) $this->settings()->get('pagination_per_page', 25)
            );
            $items = array_map(
                fn (int $id) => $this->api()->read('items', $id)->getContent(),
                $filtered['ids']
            );
            $this->paginator($filtered['total']);
            $isTruncated = $filtered['truncated'];
        } else {
            $this->paginator($response->getTotalResults());
            $isTruncated = false;
        }
```

**Ojo al orden:** en el código actual `$view` se crea **después** del `paginator()`, así que este bloque no puede escribir en él todavía. Guarda el flag en una variable local y pásalo donde se asignan las demás:

```php
        $view->setVariable('isTruncated', $isTruncated);
```

Elimina también la variable `isPartialFilter` de la vista si deja de usarse en la plantilla.

Añade `ComputedFilter` al constructor del controlador y a su factoría en `module.config.php`. Elimina `MasterViewQuery::filterPartialAlignment()` y su import de `ItemRepresentation` si queda sin uso; actualiza sus tests si los hubiera.

En la plantilla, sustituye el aviso de total aproximado por el de truncado:

```php
<?php if (!empty($isTruncated)): ?>
<p class="status-info"><?php echo $translate('Resultado acotado a los primeros 2000 recursos.'); /* @translate */ ?></p>
<?php endif; ?>
```

- [ ] **Paso 7: lint y tests**

Ejecuta: `make lint && make test`
Esperado: verde.

- [ ] **Paso 8: verificar en contenedor**

Filtra por alineamiento «parcial». Criterios: el total del paginador **coincide** con el número real de resultados al recorrer todas las páginas; el número de filas por página es estable; **desaparece** el aviso de «total aproximado».

> Confirma en el contenedor que `returnScalar` es el parámetro correcto del `ItemAdapter` de 4.2 para pedir solo ids. Si no lo fuera, pide la búsqueda normal con `per_page` alto acotado al tope y quédate con los ids.

- [ ] **Paso 9: commit**

```bash
git add src/Service/ComputedFilter.php test/Service/ComputedFilterTest.php src/Service/MasterViewQuery.php src/Controller/Admin/IndexController.php config/module.config.php view/oer-manager/admin/index/index.phtml
git commit -m "fix(ui): filtros computados con total exacto y tope duro (D4)"
```

---

## Tarea 10: filtro de tipo de recurso con el CustomVocab (D1)

**Ficheros**
- Crear: `src/Service/ResourceTypeVocab.php`, `test/Service/ResourceTypeVocabTest.php`
- Modificar: `src/Service/MasterViewQuery.php:59-78`, `config/module.config.php`, `src/Controller/Admin/IndexController.php`, `view/oer-manager/admin/index/index.phtml:50`

**Interfaces**
- Produce: `ResourceTypeVocab::values(): array<string>` — lista de términos, o `[]` si degrada.
- Produce: `ResourceTypeVocab::isAvailable(): bool`.

El filtro está **muerto por construcción**: usa operador `res` (enlace) sobre valores literales, así que no puede casar nunca. El setting `GovernanceSettings::RESOURCE_TYPE_VOCAB_ID` **ya existe** desde el commit `55da9b6`; lo que falta es su primer consumidor. El vocabulario también existe (`custom_vocab` id 1, 29 términos) y **el módulo no crea nada**.

- [ ] **Paso 1: escribir el test que falla**

`test/Service/ResourceTypeVocabTest.php`:

```php
<?php

declare(strict_types=1);

namespace OERManager\Test\Service;

use OERManager\Service\ResourceTypeVocab;
use PHPUnit\Framework\TestCase;

/**
 * Degradación explícita del vocabulario de tipos (ADR-0013): si el setting está
 * vacío, CustomVocab no está activo o el vocabulario ya no existe, el campo cae
 * a texto libre y la UI lo dice. Mismo patrón que CurriculumSearch, que
 * devuelve [] cuando su setting está sin configurar.
 */
final class ResourceTypeVocabTest extends TestCase
{
    public function testDegradesWhenTheSettingIsEmpty(): void
    {
        $vocab = new ResourceTypeVocab(null, static fn (int $id): array => ['A']);

        $this->assertFalse($vocab->isAvailable());
        $this->assertSame([], $vocab->values());
    }

    public function testReturnsTheVocabularyValues(): void
    {
        $vocab = new ResourceTypeVocab(1, static fn (int $id): array => ['Vídeo', 'Ficha']);

        $this->assertTrue($vocab->isAvailable());
        $this->assertSame(['Vídeo', 'Ficha'], $vocab->values());
    }

    public function testDegradesWhenTheVocabularyNoLongerExists(): void
    {
        $vocab = new ResourceTypeVocab(99, static function (int $id): array {
            throw new \RuntimeException('not found');
        });

        $this->assertFalse($vocab->isAvailable());
        $this->assertSame([], $vocab->values());
    }

    public function testDegradesWhenTheVocabularyIsEmpty(): void
    {
        $vocab = new ResourceTypeVocab(1, static fn (int $id): array => []);

        $this->assertFalse($vocab->isAvailable());
    }

    public function testTheReaderIsCalledOnlyOnce(): void
    {
        $calls = 0;
        $vocab = new ResourceTypeVocab(1, static function (int $id) use (&$calls): array {
            $calls++;
            return ['Vídeo'];
        });

        $vocab->values();
        $vocab->values();
        $vocab->isAvailable();

        $this->assertSame(1, $calls);
    }
}
```

- [ ] **Paso 2: ejecutar y verificar que falla**

Ejecuta: `make test`
Esperado: FAIL — clase no encontrada.

- [ ] **Paso 3: implementar**

`src/Service/ResourceTypeVocab.php`:

```php
<?php

namespace OERManager\Service;

/**
 * Lector del CustomVocab de tipos de recurso (ADR-0013, «sembrar, no poseer»).
 *
 * El vocabulario NO lo crea el módulo: ya existe y se identifica por el setting
 * `oermanager_resource_type_vocab_id`, nunca por etiqueta. Si el setting está
 * vacío, CustomVocab no está activo o el vocabulario desapareció, degrada a
 * lista vacía y el filtro cae a texto libre diciéndolo.
 *
 * El lector se inyecta como callable para que el núcleo sea testeable en host
 * sin el core de Omeka.
 */
class ResourceTypeVocab
{
    private ?int $vocabId;
    /** @var callable(int):array<string> */
    private $reader;
    private ?array $values = null;

    public function __construct(?int $vocabId, callable $reader)
    {
        $this->vocabId = $vocabId;
        $this->reader = $reader;
    }

    /** @return array<string> */
    public function values(): array
    {
        if (null !== $this->values) {
            return $this->values;
        }
        if (null === $this->vocabId || $this->vocabId <= 0) {
            $this->values = [];
            return $this->values;
        }
        try {
            $this->values = array_values(array_filter(
                array_map('strval', ($this->reader)($this->vocabId)),
                static fn (string $value): bool => '' !== trim($value)
            ));
        } catch (\Throwable $e) {
            // El vocabulario ya no existe o CustomVocab no está activo: degradar,
            // no romper la vista maestra entera.
            $this->values = [];
        }
        return $this->values;
    }

    public function isAvailable(): bool
    {
        return [] !== $this->values();
    }
}
```

- [ ] **Paso 4: ejecutar y verificar que pasa**

Ejecuta: `make test`
Esperado: PASS, 5 tests nuevos.

- [ ] **Paso 5: registrar la factoría**

En `config/module.config.php`, en `service_manager.factories`:

```php
            // Vocabulario de tipos de recurso (D1). Dependencia BLANDA de
            // CustomVocab: no se declara en module.ini; si no está, degrada.
            Service\ResourceTypeVocab::class => function ($container) {
                $settings = $container->get('Omeka\Settings');
                $api = $container->get('Omeka\ApiManager');
                return new Service\ResourceTypeVocab(
                    Service\GovernanceSettings::parseId(
                        $settings->get(Service\GovernanceSettings::RESOURCE_TYPE_VOCAB_ID)
                    ),
                    static function (int $id) use ($api): array {
                        return $api->read('custom_vocabs', $id)->getContent()->listValues();
                    }
                );
            },
```

- [ ] **Paso 6: corregir el operador en `MasterViewQuery`**

Saca `resource_type` del array `$resourceFilters` (deja de ser un filtro `res`) y añádelo junto al de licencia:

```php
        // D1: lrmi:learningResourceType son literales del CustomVocab, no
        // enlaces a item. Con operador `res` este filtro no podía casar nunca.
        if (!empty($query['resource_type'])) {
            $params['property'][] = [
                'property' => 'lrmi:learningResourceType',
                'type' => 'eq',
                'text' => $query['resource_type'],
            ];
        }
```

- [ ] **Paso 7: pasar el vocabulario a la vista**

En `IndexController::indexAction()`, inyecta `ResourceTypeVocab` y añade:

```php
        $view->setVariable('resourceTypeValues', $this->resourceTypeVocab->values());
```

Y en la plantilla, sustituye el `<input type="number" name="resource_type">` (línea 50):

```php
        <?php if ($resourceTypeValues): ?>
        <select name="resource_type">
            <option value=""><?php echo $translate('Tipo de recurso (todos)'); ?></option>
            <?php foreach ($resourceTypeValues as $value): ?>
            <option value="<?php echo $escape($value); ?>" <?php echo ($query['resource_type'] ?? '') === $value ? 'selected' : ''; ?>><?php echo $escape($value); ?></option>
            <?php endforeach; ?>
        </select>
        <?php else: ?>
        <input type="text" name="resource_type" value="<?php echo $escape($query['resource_type'] ?? ''); ?>"
               placeholder="<?php echo $escape($translate('Tipo de recurso (texto libre)')); ?>"
               title="<?php echo $escape($translate('Sin vocabulario de tipos configurado: se busca por texto exacto.')); ?>">
        <?php endif; ?>
```

- [ ] **Paso 8: lint y tests**

Ejecuta: `make lint && make test`
Esperado: verde.

- [ ] **Paso 9: verificar en contenedor**

Con el setting apuntando al `custom_vocab` id 1: el desplegable muestra los 29 términos. Elige uno que algún REA use y comprueba que **devuelve resultados** — hoy es imposible. Vacía el setting y comprueba que el campo cae a texto libre con su aviso.

- [ ] **Paso 10: commit**

```bash
git add src/Service/ResourceTypeVocab.php test/Service/ResourceTypeVocabTest.php src/Service/MasterViewQuery.php src/Controller/Admin/IndexController.php config/module.config.php view/oer-manager/admin/index/index.phtml
git commit -m "fix(ui): filtro de tipo de recurso con el CustomVocab y operador eq (D1)"
```

---

## Tarea 11: linaje en el autocompletado de términos

**Ficheros**
- Modificar: `src/Service/CurriculumSearch.php` (`mapResults()`, `searchDimension()`, `searchEtapas()`, `searchAxes()`)

**Interfaces**
- Produce: cada resultado de `search-terms` gana `parentId:int` y `parentTitle:string` (vacíos cuando la dimensión no tiene padre). Lo consume la tarea 13.

Sin el linaje, los siete «Matemáticas» del catálogo son indistinguibles en el desplegable, exactamente igual que en la tabla. El padre depende de la dimensión, y el mapeo ya está codificado en `contextFilter()`: un **Curso** cuelga de su Etapa por `schema:inDefinedTermSet`; una **Asignatura** de su Curso por `lrmi:educationalLevel`; saberes y criterios de su Curso por `lrmi:educationalAlignment`.

- [ ] **Paso 1: añadir el mapa de padres**

Junto a `TYPE_SETTINGS`, en `CurriculumSearch`:

```php
    /**
     * Property por la que cada dimensión cuelga de su ancestro (ADR-0009). Es
     * el inverso del filtro de contexto: aquí no se acota, se muestra el linaje
     * para desambiguar homónimos («Matemáticas» aparece 7 veces con distinto
     * curso). Etapas y ejes no tienen padre que mostrar.
     */
    private const PARENT_TERMS = [
        'lrmi:educationalLevel' => self::IN_TERMSET_TERM,
        'schema:about' => 'lrmi:educationalLevel',
        'lrmi:teaches' => 'lrmi:educationalAlignment',
        'lrmi:assesses' => 'lrmi:educationalAlignment',
    ];
```

- [ ] **Paso 2: enriquecer `mapResults()`**

```php
    private function mapResults($items, ?string $parentTerm = null): array
    {
        $results = [];
        foreach ($items as $item) {
            $parent = $parentTerm ? $this->firstResourceRef($item, $parentTerm) : ['id' => 0, 'title' => ''];
            $results[] = [
                'id' => (int) $item->id(),
                'title' => (string) $item->displayTitle(),
                'description' => $this->firstLiteralValue($item, 'dcterms:description'),
                'block' => $this->firstLiteralValue($item, 'dcterms:subject'),
                'parentId' => (int) $parent['id'],
                'parentTitle' => (string) $parent['title'],
            ];
        }
        return $results;
    }
```

- [ ] **Paso 3: pasar el padre desde `searchDimension()`**

En la última línea de `searchDimension()`:

```php
        return $this->mapResults(
            $this->api->search('items', $query)->getContent(),
            self::PARENT_TERMS[$dimension] ?? null
        );
```

Deja `searchEtapas()` y `searchAxes()` llamando a `mapResults()` sin segundo argumento: no tienen padre que mostrar y devolverán `parentId: 0`, `parentTitle: ''`.

- [ ] **Paso 4: lint y tests**

Ejecuta: `make lint && make test`
Esperado: verde. Si algún test existente fija la forma exacta del resultado de `search-terms`, actualízalo para incluir las dos claves nuevas.

- [ ] **Paso 5: verificar en contenedor**

Llama a `search-terms` con `dimension=schema:about&q=Matem`. Criterio: cada resultado trae `parentTitle` con su curso, y los homónimos se distinguen entre sí.

- [ ] **Paso 6: commit**

```bash
git add src/Service/CurriculumSearch.php
git commit -m "feat(search): linaje en los resultados de search-terms para desambiguar homónimos"
```

---

## Tarea 12: `config.js`, `main.js` y las dos superficies simples

**Ficheros**
- Crear: `asset/js/config.js`, `asset/js/main.js`, `asset/js/ui/visibility.js`, `asset/js/ui/drawer.js`
- Modificar: `asset/js/oer-master-view.js` (se le quitan las partes movidas), `view/oer-manager/admin/index/index.phtml:18`

**Interfaces**
- Produce: `readConfig()` → objeto congelado con `setVisibilityUrl`, `csrfToken`, `searchTermsUrl`, `recatalogPreviewUrl`, `recatalogApplyUrl`, `aiProposeUrl`, `aiProposeStatusUrl`, `aiProposeCancelUrl`, `aiEnabled`, `canRecatalog`, `recatalogCsrf`.
- Produce: `initVisibility(config)`, `initDrawer(config)`.
- Los consumen las tareas 13 y 14.

El JS usa delegación de eventos sobre `document`, así que el fichero viejo y los módulos nuevos **pueden convivir** mientras dura la transición: cada tarea mueve un grupo de responsabilidades y borra su código del fichero viejo, que desaparece en la tarea 14.

- [ ] **Paso 1: implementar `config.js`**

```js
/**
 * Configuración de la vista maestra, leída UNA sola vez (TASK-028). Antes se
 * leía con $('#oer-master-view-table').data(...) en más de diez puntos
 * dispersos, de modo que un dato que la plantilla dejara de emitir no se
 * detectaba hasta fallar en producción.
 */
export function readConfig() {
    const table = document.getElementById('oer-master-view-table');
    if (!table) {
        return null;
    }
    const d = table.dataset;
    const flag = (value) => '1' === value;
    return Object.freeze({
        setVisibilityUrl: d.setVisibilityUrl,
        csrfToken: d.csrfToken,
        searchTermsUrl: d.searchTermsUrl,
        recatalogPreviewUrl: d.recatalogPreviewUrl,
        recatalogApplyUrl: d.recatalogApplyUrl,
        aiProposeUrl: d.aiProposeUrl,
        aiProposeStatusUrl: d.aiProposeStatusUrl,
        aiProposeCancelUrl: d.aiProposeCancelUrl,
        aiEnabled: flag(d.aiEnabled),
        canRecatalog: flag(d.canRecatalog),
        recatalogCsrf: d.recatalogCsrf
    });
}
```

- [ ] **Paso 2: crear `main.js`**

```js
import { readConfig } from './config.js';
import { initVisibility } from './ui/visibility.js';
import { initDrawer } from './ui/drawer.js';

const config = readConfig();
if (config) {
    initVisibility(config);
    initDrawer(config);
}
```

- [ ] **Paso 3: mover visibilidad y drawer**

Traslada a `ui/visibility.js` la función `setVisibility` y los tres manejadores de `oer-master-view.js:202-262` (fila, «seleccionar todo» y lote), y a `ui/drawer.js` las funciones `renderDrawer`, `openDrawer` y `closeDrawer` de `:147-200` más sus manejadores. Cada módulo exporta una función `init<Nombre>(config)` que registra sus manejadores delegados; las URLs y el token salen de `config`, no del dataset.

`ui/drawer.js` construye su marcado a partir de `drawerRows()` y `drawerTitle()` de la tarea 3, en lugar de recorrer `DRAWER_FIELDS` a mano.

Borra de `oer-master-view.js` todo lo movido, incluidas `DRAWER_FIELDS` y `valueText`, e importa lo que le siga haciendo falta desde `core/`.

- [ ] **Paso 4: encolar el módulo en la plantilla**

En `view/oer-manager/admin/index/index.phtml:18`, sustituye el `appendFile` del fichero viejo por:

```php
$this->headScript()->appendFile($this->assetUrl('js/main.js', 'OERManager'), 'module');
$this->headScript()->appendFile($this->assetUrl('js/oer-master-view.js', 'OERManager'));
```

Las dos líneas conviven durante la transición; la segunda desaparece en la tarea 14.

- [ ] **Paso 5: comprobar sintaxis y lint**

Ejecuta: `for f in asset/js/*.js asset/js/core/*.js asset/js/ui/*.js; do node --check "$f" || echo "FALLA $f"; done && make lint && make test-js`
Esperado: sin errores.

- [ ] **Paso 6: verificar en contenedor**

Abre la vista maestra. Criterios: **la consola no muestra errores** y el `type="module"` aparece en el `<script>`; el drawer abre, pinta los nueve campos y cierra; cambiar visibilidad individual y en lote sigue funcionando.

> Si el `type="module"` no se emitiera o el import fallara, aplica la alternativa del spec §4.6: un fichero de entrada clásico que haga `import()` dinámico. No cambies el resto del diseño.

- [ ] **Paso 7: commit**

```bash
git add asset/js view/oer-manager/admin/index/index.phtml
git commit -m "refactor(js): entrada por módulos ES, config única, drawer y visibilidad"
```

---

## Tarea 13: `ui/termPicker.js` y `ui/recatalog.js`

**Ficheros**
- Crear: `asset/js/ui/termPicker.js`, `asset/js/ui/recatalog.js`
- Modificar: `asset/js/oer-master-view.js`, `asset/js/main.js`

**Interfaces**
- Consume: `readConfig()` (tarea 12), `diffRows()` (tarea 6), `messageFor()` (tarea 1), el linaje de la tarea 11 y los títulos de la tarea 5.
- Produce: `initTermPicker(config)`, `initRecatalog(config)`, y `chipIdsByTerm($panel)` → `{ [term]: string[] }`, que consume la tarea 14.

- [ ] **Paso 1: mover el widget de términos**

Traslada a `ui/termPicker.js` `buildSearchChoice`, `buildDimensionSelector`, `showDrop`, el temporizador de búsqueda y los manejadores de escritura, selección y borrado de chips (`oer-master-view.js:54-113` y `:327-399`).

Aprovecha el linaje de la tarea 11 al pintar cada resultado: si `parentTitle` no está vacío, se muestra como segunda línea junto a `description`/`block`. Es lo que distingue los siete «Matemáticas».

Exporta además:

```js
export function chipIdsByTerm($panel) {
    const ids = {};
    $panel.find('.oer-recatalog-dim').each(function () {
        const $dim = $(this);
        ids[$dim.data('term')] = $dim.find('.chosen-choices .search-choice')
            .map(function () { return String($(this).attr('data-id')); })
            .get();
    });
    return ids;
}
```

- [ ] **Paso 2: mover el panel de re-catalogación**

Traslada a `ui/recatalog.js` `buildRecatalogPanel`, `disableApply`, `markDirty`, `firstChipId`, `getContext`, `collectAlignmentPairs` y los manejadores de preview y apply.

Sustituye `renderDiff` por el consumo del modelo de la tarea 6:

```js
import { diffRows } from '../core/diffModel.js';
import { messageFor } from '../core/messages.js';

function renderDiff($panel, diff) {
    const { rows, hasInvalid } = diffRows(diff);
    const $diff = $panel.find('.oer-recatalog-diff').empty();
    rows.forEach((row) => {
        const $row = $('<p>').addClass('oer-diff-row');
        $row.append($('<strong>').text(row.label + ': '));
        if (row.unchanged) {
            $row.append($('<span>').text(Omeka.jsTranslate('sin cambios')));
        }
        if (row.added.length) {
            $row.append($('<span>').addClass('oer-diff-added')
                .text('+ ' + row.added.join(', ')));
        }
        if (row.removed.length) {
            $row.append($('<span>').addClass('oer-diff-removed')
                .text('− ' + row.removed.join(', ')));
        }
        if (row.invalid.length) {
            $row.append($('<span>').addClass('oer-diff-invalid')
                .text(Omeka.jsTranslate('inválidos: ') + row.invalid.join(', ')));
        }
        $diff.append($row);
    });
    return !hasInvalid;
}
```

Los mensajes de error de preview y apply pasan a `messageFor(response.error, Omeka.jsTranslate('Error inesperado; inténtalo de nuevo.'))`.

- [ ] **Paso 3: cablear en `main.js`**

```js
import { initTermPicker } from './ui/termPicker.js';
import { initRecatalog } from './ui/recatalog.js';
// …
    initTermPicker(config);
    initRecatalog(config);
```

- [ ] **Paso 4: comprobar sintaxis, lint y tests**

Ejecuta: `for f in asset/js/*.js asset/js/core/*.js asset/js/ui/*.js; do node --check "$f" || echo "FALLA $f"; done && make lint && make test && make test-js`
Esperado: todo verde.

- [ ] **Paso 5: verificar en contenedor**

Sobre el item **#3181**, con rol `editor`: abre el panel, busca un término y comprueba que **el desplegable muestra el curso** junto al título; añade y quita chips; pulsa «Previsualizar». Criterio de D7: el diff muestra **los títulos** de lo que se añade y lo que se quita, no `+N/−M`. Confirma con «Aplicar» y verifica en el JSON-LD del item que el alineamiento y las anotaciones se escriben como antes.

- [ ] **Paso 6: commit**

```bash
git add asset/js
git commit -m "refactor(js): selector de términos y panel de re-catalogación en módulos; diff con títulos (D7)"
```

---

## Tarea 14: `ui/aiPropose.js` y retirada del fichero viejo

**Ficheros**
- Crear: `asset/js/ui/aiPropose.js`
- Eliminar: `asset/js/oer-master-view.js`
- Modificar: `asset/js/main.js`, `view/oer-manager/admin/index/index.phtml:18`

**Interfaces**
- Consume: `decide()`, `POLL_MS`, `POLL_MAX` (tarea 2), `pendingChips()` (tarea 4), `extractionSummary()` (tarea 4), `chipIdsByTerm()` (tarea 13), `messageFor()` (tarea 1).

- [ ] **Paso 1: mover el propose**

Traslada a `ui/aiPropose.js` `logAiDebug`, `buildAiDebugPanel`, `applyAiProposal`, `jobKey`, `pollStatus`, `handleProposalPayload`, `startProposal` y los manejadores de los botones de proponer y cancelar.

El sondeo pasa a delegar la decisión en el núcleo:

```js
import { decide, POLL_MS, POLL_MAX } from '../core/proposalState.js';

function pollStatus(ctx, jobId, attempt) {
    $.post(ctx.config.aiProposeStatusUrl, { jobId, csrf: ctx.config.recatalogCsrf })
        .done((r) => {
            const next = decide({
                status: r.status, error: r.error, payload: r.payload,
                attempt, maxAttempts: POLL_MAX
            });
            handleDecision(ctx, jobId, attempt, next);
        })
        .fail(() => {
            handleDecision(ctx, jobId, attempt, decide({
                status: 'network_error', attempt, maxAttempts: POLL_MAX
            }));
        });
}

function handleDecision(ctx, jobId, attempt, next) {
    if ('retry' === next.action) {
        setTimeout(() => pollStatus(ctx, jobId, attempt + 1), POLL_MS);
        return;
    }
    localStorage.removeItem(jobKey(ctx.itemId));
    ctx.$button.prop('disabled', false);
    if ('done' === next.action) {
        handleProposalPayload(ctx, next.payload);
        return;
    }
    ctx.$diff.text(Omeka.jsTranslate(next.message));
}
```

Y el pre-relleno del panel delega en `pendingChips()`, usando `chipIdsByTerm()` para saber qué hay ya puesto. La justificación se sigue guardando como `data-justification` oculto en el chip: **no se pinta** (TASK-023).

- [ ] **Paso 2: retirar el fichero viejo**

Comprueba que `asset/js/oer-master-view.js` ha quedado sin código propio y elimínalo. Quita su `appendFile` de la plantilla, dejando solo el de `main.js` con `type="module"`.

- [ ] **Paso 3: comprobar que no queda ninguna referencia**

Ejecuta: `grep -rn "oer-master-view.js" view/ asset/ src/ Module.php`
Esperado: sin resultados (el `.css` sí se mantiene: es `oer-master-view.css`, otro fichero).

- [ ] **Paso 4: sintaxis, lint y tests**

Ejecuta: `for f in asset/js/*.js asset/js/core/*.js asset/js/ui/*.js; do node --check "$f" || echo "FALLA $f"; done && make lint && make test && make test-js`
Esperado: todo verde.

- [ ] **Paso 5: verificar en contenedor**

Con la IA configurada, sobre un item del corpus: pulsa «Proponer con IA». Criterios: el progreso se pinta por fases; «Cancelar» funciona; al terminar, los chips propuestos se añaden y el panel de debug muestra el resumen de extracción; recarga la página a mitad de propose y comprueba que **reengancha** por `localStorage`.

- [ ] **Paso 6: commit**

```bash
git add -A asset/js view/oer-manager/admin/index/index.phtml
git commit -m "refactor(js): propose IA en módulo y retirada del IIFE de 680 líneas"
```

---

## Tarea 15: disposición híbrida — barra rápida, búsqueda avanzada y chips

**Ficheros**
- Crear: `view/oer-manager/admin/index/search.phtml`
- Modificar: `view/oer-manager/admin/index/index.phtml:31-53`, `src/Controller/Admin/IndexController.php`, `Module.php`

**Interfaces**
- Consume: `ResourceTypeVocab` (tarea 10) y el autocompletado con linaje (tarea 11).

Con 9 filtros la barra actual ya va justa y ADR-0013 añadirá al menos 4 más. La barra rápida conserva lo de uso diario; el resto va a una pantalla propia, con chips que digan siempre por qué estás filtrando.

- [ ] **Paso 1: reducir la barra rápida**

Deja en `index.phtml` solo título, visibilidad y alineamiento, más un enlace a la búsqueda avanzada al estilo nativo:

```php
        <?php echo $this->hyperlink(
            $translate('Búsqueda avanzada'),
            $this->url('admin/oer-manager', ['action' => 'search'], ['query' => $query]),
            ['class' => 'advanced-search']
        ); ?>
```

- [ ] **Paso 2: crear la acción y la pantalla de búsqueda avanzada**

En `IndexController`:

```php
    /**
     * Búsqueda avanzada de la vista maestra (TASK-028): los filtros que no
     * caben en la barra rápida. Solo pinta el formulario; el filtrado lo hace
     * indexAction con los mismos parámetros GET.
     */
    public function searchAction()
    {
        $view = new ViewModel();
        $view->setTemplate('oer-manager/admin/index/search');
        $view->setVariable('query', $this->params()->fromQuery());
        $view->setVariable('resourceTypeValues', $this->resourceTypeVocab->values());
        return $view;
    }
```

`search.phtml` monta un formulario `method="get"` que apunta a la vista maestra con los campos de etapa, materia, proyecto, eje, tipo de recurso y licencia. Los cuatro curriculares usan el mismo marcado de autocompletado que el drawer, para que `ui/termPicker.js` los gobierne sin código nuevo.

- [ ] **Paso 3: chips de filtros activos**

En `Module::attachListeners()`:

```php
        // Chips de filtros activos: el helper nativo solo conoce los parámetros
        // del core, así que el módulo añade los suyos por el evento que expone.
        $sharedEventManager->attach(
            'Omeka\Controller\Admin\Index',
            'view.search.filters',
            [$this, 'addSearchFilters']
        );
```

```php
    public function addSearchFilters(Event $event): void
    {
        $filters = $event->getParam('filters');
        $query = $event->getParam('query', []);
        $labels = [
            'alignment' => 'Alineamiento', // @translate
            'resource_type' => 'Tipo de recurso', // @translate
            'licence' => 'Licencia', // @translate
        ];
        foreach ($labels as $key => $label) {
            if ('' !== (string) ($query[$key] ?? '')) {
                $filters[$label][] = $query[$key];
            }
        }
        $event->setParam('filters', $filters);
    }
```

Y en `index.phtml`, encima de los controles: `<?php echo $this->searchFilters(); ?>`.

> Verifica en el contenedor el identificador exacto con el que hay que registrar el listener para que el evento llegue desde **tu** controlador, y los nombres de parámetro que usa `SearchFilters` (`filters` y `query`). Ajusta el `attach` a lo que veas; el resto del método no cambia. Para los filtros curriculares, que llevan ids, muestra el título del término resolviéndolo por API en vez del id crudo.

- [ ] **Paso 4: lint y tests**

Ejecuta: `make lint && make test && make test-js`
Esperado: todo verde.

- [ ] **Paso 5: verificar en contenedor**

Criterios: la barra rápida filtra sin salir de la página; «Búsqueda avanzada» abre la pantalla nueva, y al enviar vuelve a la tabla filtrada; los chips reflejan los filtros activos y quitar uno lo elimina de la consulta; los filtros curriculares se rellenan por autocompletado y **ya no piden ids numéricos**.

- [ ] **Paso 6: commit**

```bash
git add view/oer-manager/admin/index src/Controller/Admin/IndexController.php Module.php
git commit -m "feat(ui): barra rápida, búsqueda avanzada y chips de filtros activos"
```

---

## Tarea 16: cierre — verificación comparativa y gobierno

**Ficheros**
- Modificar: `docs/backlog.md`, `docs/traceability.md`, `docs/project-memory.md`

- [ ] **Paso 1: pasar la verificación comparativa completa**

Recorre la tabla del spec §6 entera, en el contenedor, y anota el resultado de cada criterio. Lo que no se pueda verificar, se declara como no verificado — no se da por bueno.

- [ ] **Paso 2: confirmar que no hay regresión en el componente de riesgo**

Sobre el item **#3181** con rol `editor`: propose → preview → apply completo, y comprobación en el JSON-LD de que `dcterms:description` sigue apareciendo en el `@annotation` de `lrmi:teaches` y `lrmi:assesses`, y **solo** ahí, y de que el título y la descripción del item se conservan.

- [ ] **Paso 3: actualizar el gobierno**

Actualiza la fila de TASK-028 en `docs/backlog.md` con el resultado de la rebanada 1 y lo que queda; añade las entradas correspondientes en `docs/traceability.md` y `docs/project-memory.md`. Registra explícitamente las **dos correcciones al estudio de TASK-027** (el preview no devolvía títulos; el setting del vocabulario de tipos ya existía), porque las rebanadas siguientes se apoyan en ese mismo estudio.

- [ ] **Paso 4: commit**

```bash
git add docs/
git commit -m "docs: TASK-028 rebanada 1 verificada y gobierno actualizado"
```

---

## Verificación final

- [ ] `make lint` en verde
- [ ] `make test` en verde
- [ ] `make test-js` en verde
- [ ] `node --check` sobre todos los ficheros de `asset/js/`
- [ ] `grep -rn "\$\|document\|fetch\|localStorage" asset/js/core/` sin resultados — la frontera dura se sostiene
- [ ] Tabla del spec §6 recorrida entera en contenedor
- [ ] `asset/js/oer-master-view.js` eliminado y sin referencias
