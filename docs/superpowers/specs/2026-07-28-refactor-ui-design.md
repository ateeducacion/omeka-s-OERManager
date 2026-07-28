# Refactor de la UI del módulo — diseño (TASK-028, rebanada 1: refactor puro)

> **Estado:** propuesta para revisión del propietario.
> **Tarea:** TASK-028, **primera de varias rebanadas**. Fija el **CÓMO** estructural; el **QUÉ** funcional lo fijó TASK-027 (`2026-07-27-campos-ui-design.md`, aprobado por ADR-0013).
> **No deroga** ADR-0005 ni ADR-0013: los implementa por partes.
> **Base:** código actual medido el 2026-07-28 e inspección del core de Omeka 4.2 en el contenedor.

---

## 1. Por qué esta rebanada existe

TASK-028 nació (2026-07-27) como una petición de **refactor**: la tabla de la vista maestra y el JS de 680 líneas. TASK-027 y ADR-0013 añadieron encima una **ampliación funcional** grande —gobernanza legal y autoría editable, historial de 340 anotaciones, columna de integridad, cola de calidad, tres acciones de lote, siembra de vocabularios—, con partes bloqueadas por PEND-011 y PEND-012.

Las dos cosas juntas no caben en un spec revisable. **Decisión del propietario (2026-07-28): refactor puro primero.** Misma funcionalidad, mejor estructura; la ampliación se apoya después sobre cimientos sanos y sin arrastrar bloqueos.

La regla de corte es literal: **esta rebanada no añade ni un campo nuevo a la UI.** La única excepción es un setting, y entra solo porque sin él un defecto no se puede cerrar (§3.3).

---

## 2. Decisiones tomadas

Todas del propietario, 2026-07-28.

| # | Decisión |
| --- | --- |
| D-1 | **Refactor puro primero**; la ampliación de ADR-0013 va en rebanadas propias con su spec |
| D-2 | El JS se trocea en **módulos ES nativos** con **núcleo puro testeado** por `node --test`; `package.json` mínimo, **cero dependencias**, sin bundler |
| D-3 | La tabla usa **clave de configuración propia** (`oer_items`) y las columnas son **configurables por curador** |
| D-4 | El filtro de tipo de recurso se arregla **con el desplegable del CustomVocab ya**, adelantando solo el setting que lo habilita |
| D-5 | Disposición **híbrida**: barra rápida en línea + búsqueda avanzada + chips de filtros activos |
| D-6 | **Autorizada la edición del `Makefile`** para añadir el target de tests de JS (levanta puntualmente la prohibición de CLAUDE.md) |

---

## 3. Capa PHP

### 3.1 Columnas

Hoy las seis columnas están instanciadas a mano en `view/oer-manager/admin/index/index.phtml:20-27`: el módulo usa los `column_types` del core pero **saltándose el mecanismo que decide cuáles se muestran** (defecto D6).

La vista maestra pasa a tener **clave de configuración propia**, `oer_items`. En Omeka, esa clave (`resourceType` en el core) es lo que determina de dónde salen las columnas de una tabla:

| Qué busca el core | Dónde | Evidencia |
| --- | --- | --- |
| Columnas elegidas por el usuario | setting de usuario `columns_admin_oer_items` | `Stdlib/Browse.php:181-190` |
| Fallback de fábrica | `column_defaults['admin']['oer_items']` | `Stdlib/Browse.php:192` |

Clave propia y no `items` porque el browse nativo de items usa esa misma clave: compartirla haría que configurar las columnas de la vista maestra cambiase el listado de items de todo el admin, y al revés.

**Juego por defecto:** las **mismas seis columnas de hoy**, declaradas en `module.config.php`. El reequilibrio de ADR-0013 (retirar «Alineamiento», fusionar Etapa+Materia en «Curricular», añadir integridad, autoría y tipo) es de la rebanada siguiente.

**Subclases de tipo de columna.** Los tipos del core declaran sus tipos de recurso a fuego —`ColumnType/Value.php:28` devuelve `['items','item_sets','media']`— y **no hay evento para ampliarlos**. Como `getColumnTypeSelect()` filtra el selector por esa lista, un curador podría quitar una columna del core y no poder volver a ponerla. Se resuelve con subclases finas que solo redefinen `getResourceTypes()` devolviendo `['oer_items']`, heredando renderizado, formulario de datos y `getSortBy()`:

`Value`, `IsPublic`, `Modified`, `Id`, `ResourceTemplate`.

Las tres primeras cubren el juego por defecto; `Id` y `ResourceTemplate` se registran porque el catálogo de campos de ADR-0013 las pide y cuestan lo mismo ahora que después. Declaran **solo** `oer_items`, así que no aparecen en el browse nativo de items. Añadir otro tipo en el futuro es una clase de ocho líneas.

**El tipo propio del módulo también se toca:** `ColumnType\AlignmentStatus::getResourceTypes()` devuelve hoy `['items']` y pasa a `['items', 'oer_items']`. Se conservan ambos a propósito: declarar solo el nuevo le quitaría a un administrador la posibilidad —hoy existente— de añadir esa columna al browse nativo de items, y esta rebanada no retira funcionalidad.

**Plantilla y orden.** La plantilla pasa a `renderHeaderRow('oer_items')` / `renderContentRow('oer_items', $item)`. El selector de orden se genera solo: `getSortConfig()` (`Stdlib/Browse.php:88-105`) recorre las columnas configuradas y toma el `getSortBy()` de cada una. **D5 y D6 quedan resueltos por construcción.**

Nota sobre D5: el estudio pedía «cabeceras ordenables», pero el browse nativo de Omeka 4.2 no las tiene —usa un selector—. Se adopta el mecanismo nativo, que es lo coherente con NFR-006 y sale gratis.

`AlignmentStatus::getSortBy()` sigue devolviendo `null`: un estado calculado en PHP no es ordenable en SQL, y esa columna se retira en la rebanada siguiente.

**Personalización por curador.** El módulo añade por evento al formulario de usuario nativo (el que ya monta `columns_admin_items` en `Form/UserForm.php:215`) dos elementos: `columns_admin_oer_items` y `browse_defaults_admin_oer_items`.

### 3.2 Consulta y filtros computados

`MasterViewQuery` hace hoy dos trabajos acoplados: traducir filtros a parámetros de API **y** cribar en memoria sobre la página ya paginada (`MasterViewQuery.php:103-109`). Eso último es el defecto **D4**: total aproximado y número de filas variable entre páginas.

Se parte en dos piezas con contratos independientes:

| Pieza | Responsabilidad | Test en host |
| --- | --- | --- |
| `MasterViewQuery` | Filtros → parámetros de API. Sin estado, sin paginar | Sí (ya lo es) |
| `ComputedFilter` (nuevo) | Resolver ids con tope duro → evaluar predicado → paginar en memoria | Sí, con predicado y proveedor de ids inyectados |

`ComputedFilter` implementa el patrón que ADR-0013 declara **obligatorio** para cualquier filtro computado: pide solo ids del conjunto completo con tope duro, evalúa el predicado sobre la lista entera y pagina después. Devuelve **total exacto**, filas estables y una marca `truncated` que la vista comunica al usuario.

El filtro «parcial» de alineamiento se convierte en su primer predicado: **D4 pasa de heredarse a estar arreglado**, y los filtros computados de ADR-0013 encuentran el patrón ya construido.

Tope duro por defecto: **2 000 ids**. Elegido por consistencia con los topes ya existentes del módulo, no por medición: con 19 REA no hay nada que medir y su función es proteger el crecimiento.

### 3.3 Filtro de tipo de recurso (D1)

El filtro está **muerto por construcción**: usa operador `res` (enlace a item) sobre valores que son literales libres (`MasterViewQuery.php:66-68`), así que no puede casar nunca; además pide un ID numérico.

Arreglo: operador `res` → `eq`, y control numérico → **desplegable del CustomVocab**. El vocabulario **ya existe** (`custom_vocab` id 1, «Tipos de recursos», 29 términos en 7 familias) y **el módulo no crea nada**: solo lo consume.

Entra por tanto un único setting de ADR-0013, `oermanager_resource_type_vocab_id`, que es el único de los cuatro **sin bloqueos** —no depende de PEND-011 ni de sembrar artefacto alguno—.

**Degradación explícita**, siguiendo el patrón de `CurriculumSearch`: si CustomVocab no está activo, el setting está vacío o el vocabulario ya no existe, el campo cae a texto libre **y la UI lo dice**. Dependencia blanda, como fija ADR-0013: `module.ini` no la declara.

### 3.4 Autocompletado en los filtros curriculares

Etapa, materia, proyecto y eje dejan de pedir IDs numéricos en crudo —inservibles para un curador, que tendría que saberse de memoria el id del item-término— y reutilizan el endpoint `search-terms` existente, que ya respeta NFR-004 (búsqueda incremental, nunca el árbol completo).

Dos cambios en el contrato del endpoint, ambos imprescindibles para que el filtro sea usable:

1. **Devolver el linaje** (`courseId`/`courseTitle`). `searchLeaves()` ya lo calcula y `mapResults()` lo descarta. Sin él, los siete casos de «Matemáticas» son indistinguibles en el desplegable.
2. **Aprovechar `description` y `block`**, que el endpoint ya devuelve y el JS tira, como segunda línea del resultado para desambiguar homónimos.

### 3.5 Disposición

Híbrida, sobre el esqueleto nativo:

- **Barra rápida en línea**: título, visibilidad y alineamiento.
- **Búsqueda avanzada**: el resto de filtros, en pantalla propia al estilo del browse nativo.
- **Chips de filtros activos**: helper `searchFilters()` del core, ampliado con un listener del evento que dispara en `View/Helper/SearchFilters.php:241` para los parámetros propios del módulo.
- `browse-controls` (paginación + selector de orden) y el patrón `batch-form` del core para las acciones de lote.

Motivo del híbrido: con 9 filtros la barra actual ya va justa y ADR-0013 añadirá al menos 4 más; mover todo a búsqueda avanzada, en cambio, cobraría un viaje a otra pantalla por cada filtrado.

---

## 4. Capa JavaScript

### 4.1 Estado actual

Un IIFE de 680 líneas, sin módulos, sin build y sin tests, con al menos ocho responsabilidades mezcladas. Dos problemas concretos medidos al leerlo:

- La configuración se lee con `$('#oer-master-view-table').data(...)` en **más de diez puntos dispersos** (URLs, tokens CSRF, flags de permiso).
- Hay **tres mapas de mensajes de error duplicados** —preview, apply y propose— con distinto juego de claves cada uno.

### 4.2 Estructura

```
asset/js/
  main.js              entrada única (type="module")
  config.js            lee el dataset de la tabla una sola vez
  core/                sin DOM, sin jQuery, sin red — todo testeado
    values.js          valor RDF → texto mostrable
    messages.js        código de error → mensaje, con fallback
    extraction.js      content.sources/skipped → resumen legible
    proposalState.js   máquina de estados del sondeo
    proposalMerge.js   qué candidatos de la IA son nuevos
    diffModel.js       respuesta de preview → modelo de vista
    drawerModel.js     JSON del item + catálogo de campos → filas
  ui/                  jQuery y DOM — sin decisiones propias
    drawer.js  visibility.js  recatalog.js
    termPicker.js  aiPropose.js  filters.js
```

**La frontera es literal y verificable:** si un fichero de `core/` menciona `$`, `document`, `fetch` o `localStorage`, está mal cortado. Es el mismo patrón puertos/adaptadores que el módulo ya usa en PHP desde ADR-0011 (núcleo puro testeable en host, glue del core aislado).

### 4.3 Las piezas que ganan test

**`proposalState.js`** justifica el ejercicio por sí sola. Hoy la decisión de qué hacer con cada respuesta del sondeo vive enredada con `setTimeout`, `localStorage` y jQuery, y solo puede ejercitarse lanzando trabajos reales contra el LLM. Pasa a función pura:

```js
decide({ status, error, attempt, maxAttempts })
  → { action: 'retry'|'done'|'error'|'stopped'|'timeout', message?, payload? }
```

Testable sin red ni proveedor: el techo de 240 intentos, que `in_progress` reintenta, que `stopped` no se confunde con `error`, y el caso hoy invisible de que **un fallo de red reintenta en vez de abortar** —decisión deliberada del código actual que ningún test protege—.

**`proposalMerge.js`**: dados el alineamiento propuesto y los ids ya presentes, devuelve por dimensión los que faltan y su justificación. Es donde vive la invariante de TASK-023 (la justificación viaja oculta en el chip y no se pinta) y donde un descuido futuro la rompería en silencio.

**`messages.js`**: unifica los tres mapas duplicados en uno, con un solo fallback.

**`diffModel.js`**: aquí entra **D7**. Hoy el diff pinta `+N/−M` aunque el backend ya devuelve `added`, `removed` e `invalid` **con títulos**. El modelo los expone por dimensión y `ui/recatalog.js` los pinta. Confirmar una escritura RDF viendo solo un recuento era el punto débil del flujo preview→confirmar, y se arregla **sin tocar el backend**.

**`drawerModel.js`**: traduce el JSON del item a filas. **Mantiene el comportamiento actual** —los nueve campos fijos, y omitir los vacíos— sin cambiarlo. El estado vacío explícito que pide ADR-0013 §1.2 es de la rebanada siguiente, y este modelo es exactamente el punto donde entrará.

### 4.4 Configuración

`config.js` sustituye las más de diez lecturas dispersas del dataset por **una lectura única al arrancar**, que devuelve un objeto congelado con URLs, tokens y flags. Deja de ser posible que un módulo lea un dato que la plantilla dejó de emitir sin que nadie se entere hasta que falle en producción.

### 4.5 Lo que no cambia

El flujo del re-catalogador (propone → previsualiza → confirma), el widget de selección de términos con el marcado de Chosen, el CSRF de cada POST, la guardia de doble arranque y el reenganche por `localStorage` se conservan **tal cual**. Es un refactor: el comportamiento observable debe ser idéntico.

### 4.6 Carga

La plantilla encola `main.js` con `type="module"`. jQuery y `Omeka.jsTranslate` siguen llegando como globales del admin; los módulos ES conviven con ellos. Sin bundler, sin `node_modules`, sin cambios en `make package`.

**A verificar en contenedor:** que `headScript()->appendFile()` emita el `type="module"` en Omeka 4.2 y que el admin no sirva el fichero con cabeceras que rompan el import. Si fallara, la alternativa es un fichero de entrada clásico que importe dinámicamente, sin tocar el resto del diseño.

---

## 5. Tests

**PHP (host, PHPUnit).** `MasterViewQuery` gana cobertura del operador corregido de D1 y de la degradación del vocabulario. `ComputedFilter` se testea con predicado y proveedor de ids inyectados: paginación sobre la lista ya filtrada, total exacto, y tope duro marcando `truncated`. Ninguno depende del core de Omeka: caben en el arnés de host existente.

**JavaScript (host, `node --test`).** Los siete módulos de `core/`, sin DOM ni red. Cubre lo que hoy no tiene red de seguridad alguna: techo de sondeo, `stopped` frente a `error`, reintento ante fallo de red, la justificación oculta en el chip, y el diff con títulos de D7.

**Sin tests para `ui/`.** Contrapartida deliberada de no meter jsdom: el pegamento se sigue verificando a mano en contenedor, como hoy.

**Enganche.** El `Makefile` gana un target de tests de JS (edición **autorizada por el propietario**, D-6) y `ci.yml` el paso correspondiente. Sin el target, el Stop hook —que corre `make lint` + `make test`— nunca ejercitaría los tests de JS: quedarían verdes en CI y silenciosos en local.

---

## 6. Verificación en contenedor

El refactor no debe cambiar nada observable salvo lo que arregla, así que la verificación es **comparativa**:

| Qué | Criterio de aceptación |
| --- | --- |
| Tabla y columnas | Las seis columnas de hoy, mismo contenido |
| Orden | El selector aparece y ordena por las columnas que lo admiten |
| Configuración por curador | Quitar y volver a poner una columna desde el perfil, **sin** que cambie el browse nativo de items |
| Autocompletado | El linaje distingue los siete «Matemáticas» |
| Tipo de recurso | El filtro **devuelve resultados**, que hoy es imposible |
| Alineamiento «parcial» | Total exacto y nº de filas estable entre páginas (hoy no lo es) |
| Re-catalogador e IA | Preview, apply y propose asíncrono se comportan igual; el diff muestra títulos |
| Carga de módulos | La página carga `main.js` sin error de consola |

---

## 7. Defectos del estudio

| Defecto | Estado en esta rebanada |
| --- | --- |
| **D1** filtro de tipo muerto por construcción | **Cerrado** (§3.3) |
| **D4** filtro «parcial» con total aproximado | **Cerrado** (§3.2) |
| **D5** sin ordenación | **Cerrado** por el mecanismo nativo (§3.1) |
| **D6** columnas hardcodeadas en el `.phtml` | **Cerrado** (§3.1) |
| **D7** preview con solo recuentos | **Cerrado** (§4.3) |
| **D2** literales invisibles al filtro y a integridad | **Fuera**: es de `IntegrityChecker` |
| **D3** «completo» con materia literal rota | **Fuera**: es de la columna que ADR-0013 retira |

D2 y D3 se dejan a propósito: arreglarlos en una columna y un comprobador que la rebanada siguiente cambia sería trabajo tirado.

---

## 8. Fuera de alcance

Todo lo funcional de ADR-0013: columnas de integridad, autoría y curricular fusionada; retirada de la columna de alineamiento; secciones editables del drawer (ficha descriptiva, gobernanza legal y autoría); historial de las 340 anotaciones; cola de calidad y sus contadores; acciones de lote nuevas; siembra del vocabulario de licencias y de la plantilla REA.

También los settings de ADR-0013 **salvo** `oermanager_resource_type_vocab_id`, que entra únicamente porque sin él D1 no se puede cerrar.

---

## 9. Riesgos

| Riesgo | Mitigación |
| --- | --- |
| **Regresión silenciosa en el re-catalogador**, que funciona, está auditado y es el componente de ALTO RIESGO del módulo | Frontera dura: su flujo se reorganiza en `ui/recatalog.js` y `ui/aiPropose.js` **sin reescribir la lógica**; los tests del núcleo capturan sus decisiones; verificación comparativa en contenedor antes de cerrar |
| `type="module"` no soportado como se espera | Alternativa conocida (entrada clásica con import dinámico) que no toca el resto del diseño (§4.6) |
| El curador se configura columnas que dejan la tabla inservible | El fallback de `column_defaults` sigue vivo; restablecer es vaciar el ajuste |

---

## 10. Consecuencias de gobierno

- **Un setting nuevo** en `ConfigForm`: `oermanager_resource_type_vocab_id`. Los otros tres de ADR-0013 esperan a su rebanada.
- **`Makefile` editable para este fin**, por autorización expresa del propietario (2026-07-28), levantando puntualmente la prohibición de CLAUDE.md. Alcance: añadir el target de tests de JS, nada más.
- **`ci.yml` gana un paso** de tests de JS (TASK-014 está cerrada; esto es una adición, no un rediseño del workflow).
- **`package.json` nuevo**, mínimo, con `type: module` y **cero dependencias**. No cambia `make package`: los tests viven bajo `test/js/` y `composer.json` ya excluye `/test` del ZIP de release. Queda una decisión menor de empaquetado —si `package.json` se excluye también o viaja en el ZIP, donde es inofensivo—, que se resuelve al implementar.
- **`Module::install()` sigue vacío** y `upgrade()` sin migraciones: esta rebanada no siembra nada.
- **La ACL no se toca.** El roce de NFR-003 registrado en ADR-0013 §8.3 (el controlador entero concedido a `editor`) se aborda en TASK-029, que ya lo tiene en su alcance.

---

## 11. Dependencias con otras tareas

| Tarea | Relación |
| --- | --- |
| **TASK-027** | Aporta el catálogo de campos; esta rebanada implementa solo su parte estructural |
| **TASK-029** | Arregla la ACL por privilegio; esta rebanada no la duplica |
| **Rebanadas siguientes de TASK-028** | Consumen `ComputedFilter`, el registro de columnas y `drawerModel.js` como puntos de extensión ya construidos |
| **TASK-013** (`dcterms:type` como `skos:Concept`) | Cambiaría el filtro de tipo de `eq` a `res`. Al concentrarlo en `MasterViewQuery`, es un cambio de una línea |
| **TASK-006** (estadísticas) | No se toca aquí; los contadores compartidos llegan con la cola de calidad |

---

## 12. Resumen en una frase

Esta rebanada no añade un solo campo: cambia columnas hardcodeadas por columnas registradas y configurables, parte la consulta en dos piezas testeables que arreglan el filtro con total aproximado, sustituye los IDs numéricos por autocompletado y desplegable, y trocea 680 líneas de JavaScript en un núcleo puro con tests y un pegamento fino — dejando el terreno preparado para la gobernanza de ADR-0013.
