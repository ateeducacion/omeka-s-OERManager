# ADR-0017: El panel de detalle vuelve al lateral, sobre el sidebar nativo de Omeka

## Estado

**Aceptado (2026-08-20)** por el propietario. Redactado a petición suya ese mismo día (`Propuesto`) antes de tocar código —tal como pidió— y aceptado sin cambios tras el diseño de TASK-034. Reemplaza la colocación decidida en TASK-032 (fila expandida dentro de la tabla) y **restaura en parte ADR-0005 §6**, que describía el detalle como panel lateral; no restaura su implementación (cajón propio de `28em` con `role="dialog"`), sino la del anfitrión. Complementa ADR-0014, no lo deroga: sus cuatro reglas siguen mandando sobre lo que se pinte dentro.

## Contexto

TASK-032 (2026-08-13) sacó el detalle del lateral y lo metió **dentro de la tabla**, como fila expandida a todo el ancho. El motivo registrado era bueno: el curador necesita comparar **lo que el REA dice** (anclaje curricular) con **lo que sus archivos son** (medios), y a `28em` esas dos mitades no caben lado a lado. TASK-033 (2026-08-18) construyó encima: rejilla 2fr/1fr, una sola zona para el currículo con lectura y edición alternando, y el riel de ADR-0014 §4 continuado desde la fila hasta el control que arregla lo que denuncia.

El propietario pide ahora (2026-08-20) volver al lateral, y da dos razones: **usabilidad** y **coherencia con Omeka**. La segunda es verificable y pesa: el admin de Omeka ya tiene un panel de detalle lateral, es el que abre el enlace «Detalles» del browse de items, y el módulo hoy no lo usa.

Las fuerzas que obligan a decidir, y no a limitarse a mover el panel:

1. **El sidebar nativo es un mecanismo de HTML servido, no de JSON.** Medido en el core 4.2 del contenedor: `admin.js:19-28` delega en `#content` sobre `a.sidebar-content`; `global.js:30-44` (`populateSidebarContent`) hace `$.get(url)` y `.html(data)` sobre `.sidebar-content`, y al terminar dispara `o:sidebar-content-loaded`. El panel del módulo, en cambio, se pinta **entero en cliente** desde el JSON de `drawer-details`. Adoptar el sidebar sin adoptar su contrato sería usarlo a contrapelo.
2. **La costura de extensión de Omeka vive en la plantilla.** `application/view/omeka/admin/item/show-details.phtml` termina en `$this->trigger('view.details', ['entity' => $resource])`. Es así como un módulo amplía el panel nativo. Un panel pintado desde JSON no ofrece esa costura: nadie puede extender el nuestro.
3. **La anchura nativa no da para lo construido.** Medido en `application/asset/css/style.css`: `.sidebar { position: fixed; width: 25% }` y `body.sidebar-open #content { width: 56.25% }`; por debajo de 640px el sidebar pasa a `width: 100%` como overlay. A 1400px de viewport son ~350px, que no sostienen ni la rejilla 2fr/1fr ni seis selectores Chosen con términos como «Autorregulación del esfuerzo». Además la tabla maestra **se encoge al 56%** cada vez que se abre el panel.
4. **`#sidebar` lo declara cada vista, no el layout del admin.** En el core aparece en `item/browse.phtml:106-112`, no en `layout-admin.phtml`. Es decir: el módulo declara el suyo, y por tanto **puede darle su propia anchura sin tocar el core ni afectar a ninguna otra pantalla de Omeka**. Esto es lo que hace viable la fuerza 3.
5. **Hay un agujero de i18n que esta decisión puede cerrar de paso.** `make generate-pot` solo escanea `*.php` y `*.phtml`, así que **ninguna** cadena de `Omeka.jsTranslate()` se extrae: todo el copy del panel es intraducible de facto contra NFR-005. Si el markup pasa a `.phtml`, esas cadenas empiezan a extraerse solas.

Este ADR **no** decide qué campos muestra el panel ni con qué prioridad —eso es ADR-0013 y su spec— ni el lenguaje visual de lo que se pinta dentro, que sigue siendo ADR-0014.

## Alternativas consideradas

- **A) Dejarlo como está** (fila expandida). Coste cero y conserva la comparación lado a lado. Contra: el módulo tiene un panel de detalle que no se parece ni se comporta como el de la aplicación que lo aloja, y el curador que trabaja también en el browse de items aprende dos gestos para lo mismo.
- **B) Sidebar nativo como contenedor, pintado en cliente.** Declarar `#sidebar` con el markup del core y llamar a `Omeka.openSidebar()`, pero seguir montando el DOM actual dentro de `.sidebar-content` desde el JSON. Para el curador es indistinguible del nativo, y no toca `drawerDetails.js`, `recatalog.js` ni `detailAreas.js`. Contra: adopta la caja y no el contrato — sin `data-sidebar-content-url`, sin costura `view.details`, y el agujero de i18n sigue abierto.
- **C) Sidebar nativo con partial servido.** `drawer-details` devuelve un `ViewModel` terminal con plantilla `.phtml`, y el disparador de la tabla usa `data-sidebar-content-url` sin JS propio. Contra: hay que portar el pintado (461 líneas de `drawerDetails.js`) y re-enganchar el re-catalogador y el historial al evento del core; toca el punto de montaje del componente de alto riesgo del módulo.
- **D) No tener panel propio: ampliar el nativo vía `view.details`.** Máxima coherencia. Contra: `view.details` cuelga de `admin/item`, no de la vista maestra; y el panel del módulo tiene acciones de curación que no pintan en un panel de solo lectura del core.

## Decisión

Se adopta la **opción C**, con una excepción explícita de anchura.

### 1. El detalle se sirve como HTML, no como JSON

`drawer-details` pasa a devolver un `ViewModel` con `setTerminal(true)` y plantilla propia en `view/oer-manager/`, igual que `ItemController::showDetailsAction()`. El disparador de la tabla es un `a.sidebar-content` con `data-sidebar-selector` y `data-sidebar-content-url`, y el módulo **no reimplementa** apertura, cierre, animación ni estado de carga: los pone el core.

Corolario: el punto de montaje de lo que siga siendo cliente —el re-catalogador y el historial perezoso— es **`o:sidebar-content-loaded`**, el evento del core, y no un evento propio.

### 2. Los tres estados de cada área se deciden una sola vez, en el servidor

La lógica que hoy vive partida entre el controlador (`panel: null` / `integrity: null`) y `detailAreas.js` (`ready` / `empty` / `unknown`) se unifica en una clase pura PHP junto a `ItemPanelData`, con su suite PHPUnit. `detailAreas.js` se retira.

La distinción que se protege es la que la rebanada 3a de TASK-028 pagó cara: **«no se pudo leer» y «no hay nada» no comparten pantalla**. Que esté definida en un solo sitio es el punto; que ese sitio sea PHP es consecuencia de que ahí ya viven los datos.

### 3. El módulo declara su sidebar, y por tanto su anchura

`#sidebar` lo declara cada vista en Omeka, no el layout. El módulo declara el suyo en la vista maestra y le da una anchura acorde a su contenido, acotada a esa vista. **Ninguna otra pantalla de Omeka se ve afectada, y el core no se toca.**

Es una excepción consciente a ADR-0014 §1 («el chrome se hereda»): se hereda el mecanismo, el estilo, la posición y el comportamiento, y se aparta **solo la medida**, porque el contenido del panel no es el del panel nativo — el nativo lista metadatos, este sostiene una comparación y una superficie de edición.

### Lo que este ADR NO norma

La anchura concreta, la composición interna del panel, qué se apila y qué se mantiene en dos columnas a cada medida, y el destino del riel de ADR-0014 §4 dentro de un panel que ya no toca la fila. Todo eso es diseño de TASK-034 y revisable sin ADR.

## Consecuencias

**Más fácil.** El módulo deja de mantener apertura, cierre y estado de carga propios: los pone el core y siguen sus cambios. El copy del panel entra en el circuito de traducción por primera vez (NFR-005). Y el panel gana la costura `view.details` que hoy no tiene, así que otro módulo podría ampliarlo.

**Más difícil.** Se pierde la comparación a todo el ancho que era la razón de ser de TASK-032, salvo en la medida que TASK-034 decida para el sidebar propio. El pintado se parte en dos lenguajes durante la transición. Y el módulo queda atado al contrato del sidebar del core: si Omeka cambia `populateSidebarContent` o los nombres de sus clases, el panel se rompe sin aviso — que es el precio de heredar, y es el mismo que ya se aceptó en ADR-0014.

**Riesgo declarado.** Se toca el punto de montaje del **re-catalogador**, el componente de alto riesgo del módulo. El evento cambia de dueño (`oer:anchor-slot`, emitido por nuestro JS, pasa a colgar de `o:sidebar-content-loaded`, emitido por el core). Cualquier fallo ahí deja al curador sin poder corregir el anclaje, o peor, se lo deja a medias.

**Deuda que este ADR NO resuelve.** El panel sigue necesitando **dos** peticiones: `drawer-details` autenticada y un `fetch` anónimo a `/api` del que el re-catalogador saca su `itemJson`. Por eso un REA privado sigue sin editor. Unificarlas es trabajo propio y anterior a que esto se dé por cerrado.

**Queda pendiente a raíz de esta decisión, y es exigible desde su aceptación.** La verificación en el admin real con sesión iniciada —que el spec §11 de TASK-032 ya declaraba «obligatoria y no negociable» y que **sigue sin hacerse**— pasa a ser bloqueante para dar TASK-034 por cerrada. Es un cambio de colocación sobre un mecanismo del anfitrión que ningún arnés del repo puede juzgar, y ya se acumulan dos tareas de layout cerradas sin ella.

## Fuentes

- Core Omeka-S 4.2 leído del contenedor (2026-08-20): `application/asset/js/admin.js` (delegación de `a.sidebar-content`), `application/asset/js/global.js` (`openSidebar`, `closeSidebar`, `populateSidebarContent`, `reserveSidebarSpace`), `application/asset/css/style.css` (`.sidebar` al 25%, `body.sidebar-open #content` al 56,25%, overlay al 100% por debajo de 640px), `application/view/omeka/admin/item/browse.phtml` (declaración de `#sidebar`), `application/view/omeka/admin/item/show-details.phtml` y `ItemController::showDetailsAction()` (partial terminal y `trigger('view.details')`).
- Decisión del propietario, 2026-08-20: volver al lateral por usabilidad y coherencia; anchura propia del módulo; portar los estados de área a PHP; ADR antes de tocar código.
- ADR-0005 §6 (panel lateral v1), ADR-0013 (catálogo de campos v2), ADR-0014 (lenguaje visual; §1 chrome heredado, §4 riel), TASK-032 y TASK-033 en `backlog.md`.
- NFR-005 (i18n es/en) y el hallazgo de que `make generate-pot` no extrae `Omeka.jsTranslate()`, registrado en `project-memory.md` al cerrar TASK-033.

---

*Registro append-only: los ADR no se borran ni se reescriben. Para cambiar una decisión, crear un ADR nuevo y marcar este como «Reemplazado por ADR-NNNN». Numeración correlativa de cuatro dígitos; no se reutiliza.*
