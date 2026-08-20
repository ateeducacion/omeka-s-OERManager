# TASK-034 — El panel de detalle en el sidebar nativo de Omeka

> Diseño. Decisión de arquitectura en **ADR-0017** (propuesto 2026-08-20). Este documento decide lo que aquel ADR deja explícitamente fuera: anchura, composición, qué se apila, y qué pasa con el riel.

## 1. Qué cambia y qué no

Cambia **cómo se muestra** el panel, no qué muestra ni cómo se ve por dentro. El contenido lo sigue mandando ADR-0013; el lenguaje visual, ADR-0014; la unificación de la zona de currículo, TASK-033. Lo que se sustituye es el continente: la fila expandida de TASK-032 pasa a ser el sidebar del admin.

## 2. El core, medido (Omeka-S 4.2, leído del contenedor)

| Pieza | Hecho | Consecuencia para nosotros |
| --- | --- | --- |
| `admin.js:19-28` | Delega en `#content` sobre `a.sidebar-content`. Lee `data-sidebar-selector` (por defecto `#content > .sidebar`) y `data-sidebar-content-url` | El disparador debe vivir dentro de `#content` — lo está — y basta con marcarlo |
| `global.js:30-44` | `populateSidebarContent()` hace `$.get(url)` y `.html(data)`; al terminar dispara **`o:sidebar-content-loaded`** sobre el sidebar; si falla pinta «Something went wrong» | El endpoint devuelve **HTML**, no JSON. El montaje de lo que siga siendo cliente cuelga de ese evento |
| `global.js:1-22` | `openSidebar` / `closeSidebar` gestionan `.active`, `z-index` apilado y `body.sidebar-open` | No reimplementamos apertura ni cierre |
| `style.css` | `.sidebar { position: fixed; top: 48px; **left: 100%**; width: 25%; visibility: hidden; transition: left .5s }` y `.sidebar.active { **left: 75%**; visibility: visible }` | ⚠️ **`left` y `width` están acoplados**: el panel se abre deslizando `left`, no cambiando anchura. Ensanchar exige tocar **las dos**, o queda un hueco a la derecha |
| `style.css` | `.sidebar.loading * { visibility: hidden }` + spinner `:after` | El estado de carga lo pone el core. **Se retira** nuestro «Cargando detalle…» |
| `style.css` | `.sidebar > .sidebar-content { display: flex; flex-direction: column; height: 100% }` | Nuestro contenido es un **flex item** en columna, no un bloque suelto |
| `style.css` | `body.sidebar-open #content { width: 56.25% }` | Está calibrado para un sidebar del 25%. Si el nuestro mide más, hay que recalcular o la tabla queda tapada |
| `item/browse.phtml:106-112` | `#sidebar` lo declara **la vista**, no `layout-admin.phtml` | Declaramos el nuestro y le damos nuestra anchura sin tocar el core (base de la excepción de ADR-0017 §3) |
| `admin.js` completo | **No hay ningún manejador de teclado para el sidebar** | Cerrar con `Escape` se perdería. Ver §7 |

## 3. Anchura

```css
#oer-detail-sidebar { width: clamp(26rem, 40vw, 44rem); left: 100%; }
#oer-detail-sidebar.active { left: auto; right: 0; }
```

Usar `right: 0` en vez de calcular `left` evita duplicar la anchura en dos sitios y que se descuadren. El estado cerrado conserva `left: 100%` del core, así que **la transición de deslizamiento se mantiene**.

A 1400px de viewport: **560px de panel** frente a los 350px nativos, y la tabla conserva ~840px en vez de los ~790px que dejaría el 56,25% del core. Por debajo de 640px no se toca nada: manda el overlay al 100% del core.

Y hay que recalcular la compensación del contenido, acotada a nuestra vista:

```css
body.oer-master-view.sidebar-open #content { width: calc(100% - clamp(26rem, 40vw, 44rem) - 2rem); }
```

La clase `oer-master-view` la pone nuestro JS sobre `<body>` al arrancar. Sin ella no hay forma de acotar la regla a esta pantalla sin recurrir a `:has()`.

## 4. Composición

El contenedor cambia de forma: era ancho y bajo, ahora es **estrecho y alto**. La rejilla 2fr/1fr de TASK-032 se invierte, y no por capricho:

- El **anclaje curricular** es lo más ancho que hay —términos como «Autorregulación del esfuerzo», y seis selectores Chosen en modo edición—, así que se queda con **todo el ancho**.
- **Medios** e **Información** son bloques cortos de pares etiqueta/valor: caben en **dos columnas de 1fr**, y ponerlos lado a lado ahorra la mitad del recorrido vertical.

```
┌ #oer-detail-sidebar ──────────────────────────┐
│                                          [×]  │  ← .sidebar-close del core
│ [thumb] Circuito de fuerza      Público ✓     │
│ ⚠ INTEGRIDAD · 1 ERROR, 2 AVISOS              │
│    ⛔ Materia: literal en property de enlace   │
│    ⚠ Licencia: fuera del vocabulario          │
│                                               │
│ ANCLAJE CURRICULAR                            │
│   3.º ESO · Educación Física                  │
│     Saberes básicos: Condición física · …     │
│     Criterios de evaluación: 2.1 · 2.2        │
│   Eje temático: Salud y bienestar             │
│   Sin encajar                                 │
│     Saberes básicos: Primeros auxilios (…)    │
│   [Re-catalogar]     [Deshacer…]              │
│                                               │
│ MEDIOS               │ INFORMACIÓN            │
│  guion-sesion.pdf    │  Descripción           │
│  412 KB      Abrir   │  Sesión de …           │
│  circuito.jpg        │  Licencia              │
│  88 KB       Abrir   │  CC BY 4.0             │
│                                               │
│ ▸ HISTORIAL DE CURACIÓN                       │
└───────────────────────────────────────────────┘
```

Por debajo de ~34rem de panel, la banda Medios|Información cae también a una columna. Se resuelve con **container query** sobre el sidebar, no con media query de viewport: lo que decide es el ancho del panel, no el de la pantalla.

La jerarquía de TASK-033 se conserva entera: cabecera con veredicto, integridad como banda solo si falla, una sola zona para el currículo con `data-mode` read/edit, y el historial como pie plegado y perezoso.

## 5. El riel encuentra su sitio

TASK-033 hizo continuar el riel desde el canto de la fila hasta el canto del área de anclaje. **En un panel despegado de la tabla ese recorrido ya no existe**, y era el elemento memorable de aquella pasada.

No se sustituye por otra cosa: **se muda**. El sidebar del core ya tiene `border-left: 1px solid #dfdfdf`; el nuestro lo engorda a 3px y lo tiñe con el estado del REA:

```css
#oer-detail-sidebar[data-integrity="warning"] { border-left: 3px solid var(--oer-rail-warn); }
```

Sigue siendo el mismo mecanismo que norma ADR-0014 §4 —un canal periférico continuo anclado a la señal que define la excepción, con la saturación invertida— y sigue sin coste: los tokens ya existen. Cambia lo que tiñe: antes el canto de una fila, ahora el canto del panel entero. **El REA se anuncia por el borde antes de que se lea una sola palabra.**

El `data-integrity` lo escribe el partial en su raíz, no el JS: el servidor ya lo sabe.

## 6. Cambios por fichero

### `src/Service/PanelAreas.php` — nuevo, puro

Porta `asset/js/core/detailAreas.js`: orden de áreas, etiquetas y los tres estados `ready|empty|unknown`. Vive junto a `ItemPanelData`, que es de donde salen sus datos.

**Lo que protege, y por lo que existe:** «no se pudo leer» y «no hay nada» **no comparten pantalla**. Hoy esa distinción está partida entre el controlador (que decide `panel: null` / `integrity: null`) y el modelo JS (que la vuelve a derivar). Los 13 casos de `test/js/detailAreas.test.js` se reproducen en `test/Service/PanelAreasTest.php`.

`detailAreas.js` y su test se retiran **en el mismo commit** que introduce el sustituto, no antes.

### `src/Controller/Admin/IndexController.php`

`drawerDetailsAction()` deja de devolver `JsonModel` y devuelve un `ViewModel` con `setTerminal(true)`, igual que `ItemController::showDetailsAction()`. Variables: el panel de `ItemPanelData`, las áreas de `PanelAreas`, la integridad y el `data-integrity` de la raíz.

`drawerHistoryAction()` **no cambia**: sigue siendo JSON, porque el historial se pide aparte y solo al desplegarlo.

### `view/oer-manager/admin/index/drawer-details.phtml` — nuevo

Todo el markup que hoy produce `drawerDetails.js`, con `$this->translate()` y `$escape`. Termina con `$this->trigger('view.details', ['entity' => $item])`, la costura que ofrece el core para que otro módulo pueda ampliarlo.

Deja **dos huecos vacíos** que rellena el cliente: `.oer-anchor-slot` (re-catalogador) y el `<details>` del historial.

### `view/oer-manager/admin/index/index.phtml`

- El disparador pasa de `<a class="oer-open-drawer" data-item-id>` a `<a class="oer-open-drawer sidebar-content" data-sidebar-selector="#oer-detail-sidebar" data-sidebar-content-url="…/drawer-details?id=N">`. Se conserva `oer-open-drawer` porque hay CSS y JS colgando de ella.
- Se retira `aria-expanded`: ya no es un desplegable.
- Se añade el `<div id="oer-detail-sidebar" class="sidebar">` con `.sidebar-close` y `.sidebar-content`, copiando el markup de `item/browse.phtml`.

### `asset/js/ui/drawer.js` — encoge mucho

Se van `openDrawer`, `closeDetail`, `detailRowFor`, el conmutador y el manejo de foco: lo hace el core. Queda:

- re-emitir `o:sidebar-content-loaded` como el evento del módulo, con `{ itemId, itemJson, slot, bar }`, tras pedir el `fetch` anónimo a `/api` que el re-catalogador necesita;
- **`reopenDrawer()`**, que sigue haciendo falta tras aplicar o deshacer, y que ahora es `Omeka.populateSidebarContent($sidebar, url)` — más simple que hoy, porque el sidebar ya está abierto y no hay conmutador que desambiguar;
- lo de §7.

### `asset/js/ui/drawerDetails.js` — se retira casi entero

Solo sobrevive `renderHistoryArea()` (perezoso) y el pintado del historial, que se piden aparte. El resto lo hace el partial.

### `asset/js/ui/recatalog.js` — cambio mínimo

Escucha el evento re-emitido en vez de `oer:anchor-slot`. Su contrato (`slot`, `bar`, `.oer-anchor-edit`) no cambia.

## 7. Lo que el core no da y hay que poner igual

`Escape` **no cierra el sidebar en Omeka 4.2** —no hay manejador de teclado— y el core tampoco mueve el foco al abrir ni lo devuelve al cerrar. TASK-032 sí hacía las tres cosas.

NFR-006 fija el listón en «mantener el nivel del admin de Omeka 4.x», así que ceñirse al core sería defendible. Aun así **se conservan**, porque el criterio es no regresar sobre lo que ya funcionaba:

- `Escape` cierra vía `Omeka.closeSidebar()`;
- al cargar el contenido, el foco entra en el panel;
- al cerrar, vuelve al título de la fila que lo abrió.

Son ~15 líneas y solo actúan sobre `#oer-detail-sidebar`: no cambian el comportamiento de ningún otro sidebar de Omeka.

## 8. i18n — lo que esto arregla, y lo que no

Al pasar el markup a `.phtml`, su copy entra por primera vez en `make generate-pot`, que solo escanea `*.php`/`*.phtml`. **Arregla NFR-005 para el grueso del panel.**

No lo arregla para lo que siga en JS: el historial perezoso y los mensajes del re-catalogador siguen en `Omeka.jsTranslate()` y siguen sin extraerse. La deuda encoge mucho, pero no se cierra, y hay que decirlo así al cerrar la tarea.

## 9. Riesgos

1. **El punto de montaje del re-catalogador cambia de dueño.** Es el componente de alto riesgo del módulo. Un fallo deja al curador sin poder corregir el anclaje. Mitigación: el contrato (`slot`, `bar`) no cambia; solo cambia quién dispara. Se prueba con los dos caminos degradados que ya cubrió TASK-033.
2. **`left`/`width` acoplados.** Si se cambia solo `width`, el panel abre descuadrado y con hueco. Es el fallo más probable de esta tarea y por eso §3 usa `right: 0`.
3. **Sin camino degradado propio.** Si `drawer-details` falla, el core pinta «Something went wrong» y **no dispara `o:sidebar-content-loaded`**, así que el re-catalogador no se monta. Hoy sí sobrevivía. Hay que decidir si se acepta (el core manda) o si se detecta el fallo aparte. **Recomendación: aceptarlo** — sin poder leer el estado, no conviene ofrecer una escritura a ciegas sobre el catálogo.
4. **La tabla se encoge al abrir.** Es inherente al sidebar y contradice el «no perder el sitio en el listado» de TASK-032. Es el coste que ADR-0017 ya declara aceptado.

## 10. Verificación

1. `make lint`, `make test` (con `PanelAreasTest` nuevo), `make test-js` (con `detailAreas.test.js` retirado y el resto verde).
2. `php test/container/detail-panel-check.php` — mide datos, no UI; debe seguir verde sin tocarlo.
3. `make generate-pot` — comprobar que las cadenas del partial **aparecen** en el `.pot`. Es la prueba de que §8 se cumplió.
4. **Sesión en el admin real con sesión iniciada. BLOQUEANTE para cerrar** (ADR-0017): #40437 (4 grupos) · un REA con curso huérfano de `4676, 5047, 37129, 37132, 40425, 40427` · sin medios · sin miniatura · privado (sin `[Re-catalogar]`) · rol sin `canRecatalog` · `drawer-details` caído · el ciclo `Re-catalogar → Previsualizar → Confirmar` y que el panel vuelva a reposo · `Cancelar` descarta · abrir un REA con el panel ya abierto en otro · los tres estados del veredicto · `Escape` y el retorno de foco · por debajo de 640px · y que **ningún otro sidebar de Omeka** (borrar, borrar seleccionados) haya cambiado de anchura ni de comportamiento.
