# Estadísticas visuales del catálogo — diseño (TASK-006)

> **Estado:** aprobado por el propietario (2026-08-27).
> **Fija el QUÉ ya decidido en ADR-0004/RF-007 y añade el CÓMO** (arquitectura, agregación, renderizado, export), que ninguno de los dos fijaba.
> **Base:** patrones ya existentes en el módulo — `MasterViewQuery`, `ComputedFilter` (tope de catálogo), `IntegrityChecker`, ACL por privilegio (`Module.php`), y el hecho verificado de que `package.json` no declara ninguna librería de gráficos.

---

## 1. Lo que ya estaba fijado (ADR-0004, RF-007)

- **Dimensiones:** etapa (`lrmi:educationalLevel`), materia (`schema:about`), eje temático (`dcterms:relation`), proyecto (`schema:isPartOf`), licencia (`dcterms:rights`).
- **Catálogo de gráficos:** conteo simple por dimensión; cruce de 2 dimensiones (ejemplo del RF: materia×etapa); % de completitud (RF-006, `IntegrityChecker`).
- **Export:** CSV de los datos agregados detrás de cada gráfico. PDF queda **fuera** (RF-007 lo aplaza a una fase posterior; no se aborda aquí).

## 2. Decisiones de este diseño

Resueltas en la sesión de brainstorming del 2026-08-27, con el propietario:

1. **ACL:** `editor`+ (mismo nivel que la curación — vista maestra, re-catalogador). Son datos agregados de solo lectura, no gobernanza sensible como la config del módulo.
2. **Cruce de 2 dimensiones:** selector **genérico** entre las 5 dimensiones (hasta 10 pares), no solo el ejemplo materia×etapa del RF.
3. **Alcance de los datos:** **todo el catálogo, siempre**. La página de estadísticas es independiente de los filtros activos de la vista maestra — no hay acoplamiento con `MasterViewQuery`.
4. **Controlador:** `StatsController` **nuevo**, separado de `IndexController`. Es una decisión consciente de romper el patrón de "un único controlador con muchas acciones" que el módulo ha mantenido hasta ahora (`IndexController` ya tiene 15 acciones y mezcla curación, re-catalogador, IA y config): Estadísticas es un concern distinto — lectura agregada, no toca el catálogo — y no gana nada compartiendo controlador.

## 3. Arquitectura: rutas, navegación, ACL

Ruta nueva, hija de `admin`, mismo prefijo `/oer-manager` pero controlador distinto:

```php
'oer-manager-stats' => [
    'type' => Segment::class,
    'options' => [
        'route' => '/oer-manager/stats[/:action]',
        'constraints' => ['action' => '[a-zA-Z][a-zA-Z0-9_-]*'],
        'defaults' => [
            '__NAMESPACE__' => 'OERManager\Controller\Admin',
            'controller' => Controller\Admin\StatsController::class,
            'action' => 'index',
        ],
    ],
    'may_terminate' => true,
],
```

Navegación: nueva entrada bajo "OER Manager", junto a "Configuración", mismo patrón de `resource`/`privilege`:

```php
[
    'label' => 'Estadísticas', // @translate
    'route' => 'admin/oer-manager-stats',
    'resource' => Controller\Admin\StatsController::class,
    'privilege' => 'index',
],
```

ACL en `Module.php::onBootstrap()`, mismo mecanismo que ya existe, bloque nuevo:

```php
$acl->allow(
    ['editor', 'site_admin'],
    [Controller\Admin\StatsController::class],
    ['index', 'export']
);
```

## 4. Agregación (`Service\Stats\`)

Namespace nuevo bajo `Service\`, mismo patrón que `Service\Ai\`/`Service\Content\`/`Service\Governance\`/`Service\Llm\`.

- **`CatalogSnapshot`** — trae hasta `ComputedFilter::HARD_CAP` (2000) items `lrmi:LearningResource` en una sola llamada (`$api->search('items', [...])`, `per_page = HARD_CAP`), mismo patrón que ya usa la rama computada de `IndexController::indexAction()`. Expone si el catálogo real supera el tope, para que la vista pinte el mismo aviso de "resultado acotado" que ya existe en la vista maestra (ADR-0013: nunca mentir en silencio sobre un total).
- **`DimensionFacts`** — única pieza que toca `ItemRepresentation` directamente: por cada item, extrae los "hechos" de las 5 dimensiones a un array plano (`['etapa' => [12, 34], 'materia' => [56], 'eje' => [], 'proyecto' => [78], 'licencia' => 'ccbysa']`). Etapa/materia/eje/proyecto son `resource:item` (ids); licencia es literal. Esta es la frontera puerto/adaptador: todo lo que sigue no vuelve a tocar el core.
- **`DimensionCounter`** — **puro**. Recibe la lista de hechos ya extraídos y devuelve `{valor => conteo}` para una dimensión.
- **`DimensionCrosser`** — **puro**. Recibe la lista de hechos y dos claves de dimensión, devuelve la tabla cruzada `{valorA => {valorB => conteo}}`.
- **Semántica de cardinalidad múltiple** (PEND-007, confirmada en TASK-036): un item con varios valores en una dimensión cuenta **una vez por cada valor que tiene** — como un facetado por etiquetas. La suma de las barras de un gráfico puede superar el número de items del catálogo; no es un error, es la cardinalidad real.
- **Resolución de títulos:** los ids de término que aparecen en los hechos (etapa/materia/eje/proyecto) se resuelven a título en **una sola llamada** (`$api->search('items', ['id' => [...ids distintos...]])`), nunca N lecturas.
- **Completitud:** reusa `IntegrityChecker::check($item, false)` (mismo `false` que ya usa el browse, por coste — sin comprobación de enlaces) agregado en `ok`/`warning`/`error`. El gráfico usa los mismos tokens de color que ya definió ADR-0014 (`--oer-ok`, `--oer-warn`, y el tono de error), coherencia visual con el resto del panel.

## 5. Renderizado (JS)

**Composición de la página:** los 5 gráficos de conteo simple (uno por dimensión) se muestran todos a la vez; el cruce tiene su propio selector de par de dimensiones (10 combinaciones posibles) que repinta un único gráfico; la completitud es un gráfico más, fijo, sin selector. Tres bloques en la misma página: conteos, cruce, completitud.

Sin librería de terceros — el módulo no tiene ninguna declarada y no toca añadir una dependencia nueva solo para esto. PHP hace toda la agregación y sirve un JSON pequeño embebido en la vista (mismo patrón que otros datos servidos al JS del módulo); `asset/js/stats/charts.js` es una función **pura** dato→SVG:

- Conteo simple → barras horizontales.
- Cruce de 2 dimensiones → rejilla (celda = conteo, tamaño/opacidad como señal, sin inventar una librería de heatmaps).
- Completitud → barras apiladas de 3 segmentos (ok/warning/error) con los tokens de ADR-0014.

Al ser una función pura (datos de entrada → nodos SVG), es testeable con `node --test` igual que el resto del JS puro del módulo (`core/*.js`).

## 6. Export CSV

Una única acción `StatsController::exportAction()`, parametrizada por query string:

- `?dimension=materia` → CSV de conteo simple.
- `?dimension1=materia&dimension2=etapa` → CSV del cruce.
- `?type=completeness` → CSV de ok/warning/error.

Reusa exactamente los mismos agregadores que pintan la página — los números del CSV son los mismos que los del gráfico, no un cálculo aparte. Content-Type `text/csv`, `Content-Disposition: attachment`.

## 7. Testing

- `DimensionCounter`, `DimensionCrosser` y la lógica de agregación de completitud (dado un array de `IntegrityResult::getStatus()`): **puros, TDD real en host** — mismo patrón que `CurationEvent` (TASK-007) y los agregadores de TASK-010.
- `CatalogSnapshot`, `DimensionFacts`, `StatsController`: tocan el core (`ItemRepresentation`, `Omeka\Api\Manager`) → verificación en contenedor con un arnés de solo lectura (`test/container/stats-check.php`), mismo patrón que `columns-check.php`/`detail-panel-check.php`. Limitación conocida y ya documentada en `project-memory.md`.
- `asset/js/stats/charts.js`: `node --test`, dato de entrada fijo → SVG esperado.

## 8. Fuera de alcance

- **Export PDF** (RF-007 lo aplaza explícitamente).
- **Caché de la agregación**: el catálogo actual es pequeño; el coste de recorrer hasta 2000 items en memoria es aceptable hoy (mismo criterio que ya aceptó ADR-0016 para `statusFor()`). Deuda de rendimiento a escala, si aparece, es TASK-030.
- **Drill-down**: clicar una barra para filtrar la vista maestra por ese valor. No se ha discutido y añadiría superficie nueva de acoplamiento Stats↔MasterViewQuery; se deja como mejora futura si se pide.
- **Cualquier acoplamiento con los filtros activos de la vista maestra** (decisión §2.3): la página es independiente.

## Fuentes

- ADR-0004 (`docs/decisions/0004-mapeo-rdf-alineamiento-tags.md`), RF-007 (`docs/requirements.md`).
- Patrones existentes: `src/Service/MasterViewQuery.php`, `src/Service/ComputedFilter.php`, `src/Service/IntegrityChecker.php`, ACL en `Module.php`, rutas en `config/module.config.php`.
- Brainstorming con el propietario, 2026-08-27 (este chat).
