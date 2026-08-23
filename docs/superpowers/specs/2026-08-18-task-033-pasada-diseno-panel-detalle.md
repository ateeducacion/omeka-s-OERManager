# TASK-033 — Pasada de diseño del panel de detalle

## Contexto

TASK-032 integró el panel de detalle dentro de la tabla y **recolocó** el panel de re-catalogación sin reescribirlo (así lo declara `docs/backlog.md`). El resultado funciona, pero tiene tres problemas de composición que solo se ven con el panel montado:

1. **El currículo aparece dos veces y separado.** El área «Anclaje curricular» (lectura agrupada por curso·materia) va arriba; los cinco selectores Chosen que lo modifican van al final, con Integridad e Historial en medio. Y esa separación es accidental: `.oer-recatalog` es *hermano* de `.oer-detail-panel`, no hijo, porque `initRecatalog` va detrás de `initDrawerDetails` en `asset/js/main.js:14-16`. El spec §6 de TASK-032 lo situaba antes del Historial; el DOM real lo dejó detrás.
2. **Seis encabezados del mismo peso.** Anclaje, Medios, Información, Integridad, Historial y Re-catalogar se leen como seis iguales. La pantalla existe para encontrar REA mal catalogados (ADR-0014 regla 1: *«una pantalla de curación se ordena alrededor de la decisión que habilita»*), y ninguna de las seis manda.
3. **Integridad es la única área que no usa `.oer-area`**, sino `.oer-drawer-section` heredado de TASK-028 3a. No recibe la regla `.oer-area > h4` (versalitas, `--oer-muted`, `0.8em`), así que su encabezado se pinta como un h4 del admin. Es incoherencia, no decisión.

Resultado buscado: **una sola zona para el currículo**, tres niveles de jerarquía en vez de seis pares, y el veredicto de integridad donde se mira primero. Sin tipografías ni paleta propias — ADR-0014 regla 1 lo prohíbe y el módulo ya hereda Lato y `#a91919` del admin.

Decisiones del propietario para esta pasada (2026-08-18): **variante B** (etiquetas fijas + botón que las sustituye por los selectores), **veredicto de integridad en cabecera con banda solo si falla**, y **TASK nueva**, no addendum de TASK-032.

## Restricción que define el diseño

`CurricularGrouping::build()` (`src/Service/Governance/CurricularGrouping.php`) **deriva** los grupos curso·materia recorriendo el grafo del currículo. En RDF no existe ese grupo: el item tiene cinco listas planas (`lrmi:educationalLevel`, `schema:about`, `lrmi:teaches`, `lrmi:assesses`, `dcterms:relation`), y eso es lo que `collectAlignmentPairs()` envía como `alignment[term][]`.

Por eso la lectura es agrupada y la edición es plana, y por eso la variante B (sustitución) es la única que no exige rediseñar el camino de escritura del componente de alto riesgo del módulo.

## Composición

```
tr.oer-detail-row > td.oer-detail-cell
└ div.oer-detail-panel
  ├ div.oer-panel-header
  │   [thumb] Título del REA   Público · ✓ Sin incidencias   Abrir en el editor  [×]
  ├ section.oer-panel-alert            ← SOLO si hay incidencias
  │   ⚠ Integridad · 1 error, 2 avisos
  │     ⛔ Licencia: fuera del vocabulario
  │     ⚠ Curso: sin materia que lo sostenga
  ├ div.oer-panel-grid  (2fr / 1fr)
  │ ├ section.oer-area.oer-area-alignment[data-mode="read|edit"]
  │ │   h4 ANCLAJE CURRICULAR
  │ │   ├ div.oer-anchor-read      grupos · ejes · «Sin encajar»   [oculto en edit]
  │ │   ├ div.oer-anchor-slot      ← punto de montaje de .oer-recatalog
  │ │   └ div.oer-anchor-bar       [Re-catalogar]  ·  .oer-recatalog-undo
  │ └ div.oer-panel-side
  │   ├ section.oer-area-media
  │   └ section.oer-area-record
  └ section.oer-area-history
      details.oer-history-box  ▸ Historial de curación   (plegado, perezoso)
```

Tres niveles, no seis pares:

1. **Cabecera** — identidad y veredicto. Única tipografía grande (el `h3` que ya viene del admin).
2. **Anclaje curricular** — columna ancha, única zona con acciones. Es la tesis del panel.
3. **Evidencia** — Medios e Información en la columna lateral tras su filete; Historial como pie plegado.

Integridad sale de la fila de secciones: deja de ser un área más y pasa a ser un canal distinto (veredicto o alerta).

## El elemento memorable: el riel no se corta

ADR-0014 regla 4 define el riel del canto de la fila como **un mecanismo anclado a la señal que define la excepción**, no como una property. Hoy `.oer-detail-cell` pinta `background: var(--oer-surface)` encima del `box-shadow: inset 3px 0 0` de la fila, así que el riel se pierde justo en la fila que se está mirando.

Se convierte en intencional: **el riel entra en el panel y baja por el canto izquierdo de la zona de anclaje**, que es exactamente lo que la fila estaba señalando. Un REA con anclaje incompleto tiene un canto ámbar continuo desde la tabla hasta el control que lo arregla.

Es el único gasto de la pasada. Usa `--oer-rail-ok|warn|bad`, que ya existen — sin token nuevo, sin identidad nueva, sin animación. Todo lo demás se calla: se quitan encabezados y filetes redundantes.

## Cambios por fichero

### `asset/js/ui/drawerDetails.js` — el grueso

- **`renderHeader(identity, integrityArea)`**: añade `span.oer-panel-verdict` tras la visibilidad, **solo cuando no hay incidencias**. Tres salidas, no dos — la distinción que la rebanada 3a pagó cara: `integrityChecked()` falso → «Integridad no verificada» con glifo de aviso; verificado y limpio → «Sin incidencias» con ✓; con incidencias → sin marca (manda la banda).
- **`renderIntegrity()` → `renderAlert()`**: devuelve `null` si no hay grupos. Si los hay, `section.oer-panel-alert` con encabezado **contador** (`Integridad · 1 error, 2 avisos`), que es lo que el spec §6 pedía y nunca se pintó. Se conservan `.oer-integrity-issues-{error,warning}` y sus `list-style-type` (⛔ / ⚠): ADR-0014 regla 3 exige forma, no solo color.
- **`renderAlignment()`**: el cuerpo actual (grupos, ejes, huérfanos) pasa a un `div.oer-anchor-read`; se añaden `div.oer-anchor-slot` y `div.oer-anchor-bar` con el botón `.oer-anchor-edit`.
- **Nuevo evento `oer:anchor-slot` con `{ itemId, itemJson, slot, bar }`**, despachado tras pintar el panel, con guarda `panel.isConnected` — mismo motivo que la guarda de `drawer.js:132`: sus consumidores hacen peticiones reales al servidor (`recatalog-last-event`) y no hay que gastarlas en una fila que ya no existe.
- **Camino degradado (obligatorio):** en `.catch()` y en `!details.panel` hoy el curador se queda sin lectura pero **conserva el editor**. Con la fusión hay que despachar igualmente el evento con un `slot` a nivel de panel, o un `drawer-details` caído deja al curador sin poder re-catalogar.
- **Modo:** `data-mode` en `section.oer-area-alignment`, propiedad de este fichero. `[Re-catalogar]` → `edit`. **Construido distinto de lo planeado:** el camino de vuelta no re-despacha el evento desde aquí, sino que `[Cancelar]` llama a `reopenDrawer()` desde `recatalog.js`, que ya importaba esa función para el apply y el undo. Reconstruye la fila entera en vez de solo el editor, así que los chips sin confirmar se descartan de verdad, y deja el modo repartido en un solo sitio por dirección: entrar lo lleva este fichero, salir lo lleva quien pone el botón.
- **`renderHistory()`**: quitar el `<h4>` interno. «Historial de curación» sale hoy dos veces, en el `<summary>` y dentro.

### `asset/js/ui/recatalog.js` — cambios mínimos

- Escucha `oer:anchor-slot` en vez de `DRAWER_RENDERED`; monta en `slot`.
- `buildRecatalogPanel` envuelve los seis selectores en `div.oer-recatalog-dims` y **deja de pintar su propio `<h4>` «Re-catalogar»** (el área ya se llama «Anclaje curricular»; dos nombres para lo mismo es lo que esta pasada viene a quitar).
- `.oer-recatalog-undo` se monta en `bar`, no dentro del panel. `renderUndo()` lo busca hoy con `$panel.find(...)`: **pasarle el `bar`**, no moverlo por CSS — mover por CSS un elemento cuyo `:empty` colapsa márgenes es frágil.
- `itemJson === null` (REA privado, `/api` sin autenticar): no se monta el panel ni se pinta `[Re-catalogar]`; el aviso actual va dentro del área de anclaje, bajo la lectura. Mejor sitio que hoy.
- `!config.canRecatalog`: no se monta nada y **`.oer-anchor-bar` no debe quedar como caja vacía con aire**.

Todo lo demás sigue igual. Los manejadores de `termPicker.js`, `aiPropose.js` y `recatalog.js` están delegados en `document` y acotados con `.closest('.oer-recatalog')` / `.oer-recatalog-dim`, así que **reubicar el envoltorio es seguro** mientras conserve esas clases. `reopenDrawer()` tras aplicar o deshacer reconstruye todo en reposo con la lectura nueva — que es justo lo que se quiere; no hay que persistir «estaba editando».

### `asset/js/core/detailAreas.js`

Sin cambios de modelo. `AREA_ORDER` sigue siendo `['alignment','media','record','integrity']` — `test/js/detailAreas.test.js` lo asserta y solo se mueve el *pintado* de integridad, no su lugar en el modelo. Solo cambia el texto de `ALIGNMENT_EMPTY_TEXT` (ver Copy); el test comprueba que sea string no vacío, no su contenido.

### `asset/css/oer-master-view.css`

- `.oer-panel-verdict`, `.oer-panel-alert`, `.oer-anchor-read`, `.oer-anchor-slot`, `.oer-anchor-bar`, `.oer-anchor-edit`.
- Visibilidad por `data-mode` en `.oer-area-alignment` (`[data-mode="edit"] .oer-anchor-read { display:none }`, etc.).
- El riel: dejar pasar el `box-shadow` de la fila y llevarlo al canto de `.oer-area-alignment` según el `data-integrity` de `tr.oer-detail-row`, que `drawer.js:64` ya copia.
- Anular en el nuevo contexto `.oer-recatalog { margin-top; padding-top; border-top }` (l. 571-575): ese filete separaba del panel y ahora está dentro del área.
- Integridad pasa de `.oer-drawer-section` a las versalitas de `.oer-area > h4`.
- **Sin tokens nuevos.** `test/js/contrast.test.js` lee los tokens del CSS real y mide `--oer-ok|warn|bad|muted` sobre `#fff`, `--oer-surface` y `--oer-select`; no añadir colores mantiene esa medición válida y la deuda de ADR-0014 saldada.
- **Especificidad:** no crear `.oer-anchor-bar button` genérico — pisaría `.oer-recatalog-undo-btn`. Y recordar la trampa ya documentada en l. 1109-1111: `a:link,a:visited` del admin es (0,0,1,1) y gana a una clase sola.

## Copy

Una regla: **cada dimensión se llama igual en todo el panel**, y la fuente es `TERM_LABELS` (`asset/js/core/drawerModel.js`).

| Dónde | Hoy | Queda |
|---|---|---|
| Líneas del anclaje | `Saberes` / `Criterios` / `Ejes` | `Saberes básicos` / `Criterios de evaluación` / `Ejes temáticos` (de `TERM_LABELS`) |
| `ALIGNMENT_EMPTY_TEXT` | «Este REA no tiene anclaje curricular.» | «Sin anclaje curricular. Re-catalógalo para asignarle curso, asignatura y saberes.» — el botón está al lado; un vacío es una invitación a actuar |
| Etapa (`recatalog.js:22`) | `Etapa (ayuda, no se guarda)` | etiqueta `Etapa` + `p.oer-dim-hint` «Solo acota la búsqueda. No se guarda en el REA.» — una etiqueta etiqueta, nada hace doble trabajo |
| Cancelar de la IA | `Cancelar` | `Detener la propuesta` — **hoy chocaría** con el `Cancelar` que sale de la edición; dos controles con el mismo nombre y distinto efecto |
| Banda de integridad | `Integridad` | `Integridad · 1 error, 2 avisos` (singular/plural resuelto en el sitio; `jsTranslate` no hace plurales) |
| Cabecera | — | `Sin incidencias` / `Integridad no verificada`. **Construido distinto de lo planeado:** en vez de crear cadenas nuevas en la UI se acortaron `INTEGRITY_OK_TEXT` e `INTEGRITY_UNKNOWN_TEXT` en `core/integrityModel.js`, sus únicos consumidores. Cambian de registro porque cambian de sitio —ya no encabezan una sección del cuerpo, son una marca de cabecera— y el modelo sigue siendo la única fuente de cómo se llama cada estado |
| `<h4>` del historial cargado | duplica el `<summary>` | se quita |

Se mantiene **«Re-catalogar»** como verbo de la acción: es el término del dominio y vive en ADRs, specs y backlog. Lo que desaparece es «Re-catalogar» como *encabezado de sección*.

## Fuera de alcance (y por qué)

- **La extracción i18n.** `make generate-pot` solo escanea `*.php`/`*.phtml`, así que **ninguna** cadena de `Omeka.jsTranslate()` se extrae y todo el copy del panel es intraducible de facto (NFR-005). Arreglarlo exige tocar el `Makefile`, que `CLAUDE.md` declara intocable. Esta pasada no lo empeora — las cadenas nuevas nacen igual que las existentes — pero tampoco lo arregla. Merece TASK propia.
- **Unificar las dos peticiones del panel** (`drawer-details` autenticada + `fetch(apiUrl)` anónima). Sin ella, un REA privado sigue sin editor. Deuda ya declarada; rediseñar el flujo de datos del re-catalogador no es una pasada de diseño.
- **Selectores por grupo** (la variante A literal): exige rediseñar la escritura y merece su propio ADR.
- **El MIME crudo en `.oer-media-meta`** (`application/pdf · 412 KB`): un mapa MIME→nombre humano es una decisión de contenido, no de composición.
- **El desfase de breakpoints** (panel a 900px, tabla a 640px): se verifica en el navegador antes de tocarlo. Con la tabla ya apilada el `<td>` ocupa todo el ancho, así que apilar el panel también puede ser lo correcto.

## Gobierno

`docs/backlog.md`, `docs/traceability.md` y `docs/project-memory.md`: entrada **TASK-033**, RF-002/RF-004/RF-006, ADR-0014 como norma aplicada. TASK-032 se queda cerrada tal cual — historia append-only (ADR-0001). **No hace falta ADR nuevo**: ADR-0014 §«Lo que este ADR NO norma» deja fuera composición, anchuras y glifos, que es todo lo que esta pasada decide.

## Verificación

1. `make lint` — PSR-12. No se toca PHP, pero el stop hook lo exige igual.
2. `make test-js` — **`detailAreas.test.js` (13 tests) y `contrast.test.js` deben seguir verdes sin modificarlos.** Si uno se pone rojo, la pasada rompió el modelo o metió un token sin medir; es la señal, no un estorbo.
3. `make test` — PHPUnit, incluido `CurricularGroupingTest`. No se toca PHP.
4. `php test/container/detail-panel-check.php` — arnés de solo lectura; sigue verde (el servicio no cambia).
5. **Sesión de navegador con sesión iniciada.** El spec §11 de TASK-032 la declara no negociable y sigue sin hacerse (deuda de TASK-030); esta pasada es un cambio de layout que ningún arnés juzga. Cubrir:
   - REA con varios cursos: **#40437** (4 grupos, todos «Educación Física»).
   - REA con curso huérfano: uno de **4676, 5047, 37129, 37132, 40425, 40427**.
   - REA sin medios, REA sin miniatura (14 de 19 dan el icono genérico de Omeka, 1 no da ninguna).
   - REA privado → sin `[Re-catalogar]`, con motivo legible dentro del área.
   - Rol sin `canRecatalog` → área de solo lectura, sin caja vacía.
   - `drawer-details` caído (403/500) → el editor **sigue montándose** por el camino degradado.
   - Ciclo completo: `Re-catalogar` → `Previsualizar cambios` → `Confirmar` → el panel vuelve a reposo con la lectura nueva. Y `Cancelar` descarta los chips sin confirmar.
   - Los tres estados del veredicto, incluido «no verificada».
   - Modo estrecho por debajo de 900px y por debajo de 640px.
   - Teclado: `Esc` cierra la fila, el foco vuelve al disparador, y el foco es visible al entrar en edición.

## Addendum: lo que solo apareció al maquetar (2026-08-18)

Cuatro correcciones que no estaban en el plan porque no se ven leyendo código, solo mirando la página con el CSS real:

1. **Las etiquetas de campo ganaban a los rótulos de área.** `.oer-area-record dt` y `.oer-recatalog-dim > label` iban en versalitas `700`, más marcadas que el `.oer-area > h4` que las agrupa: dentro de cada columna, el nombre del área perdía contra sus propias filas. Bajan a texto de apoyo (`0.9em`, sin versalitas) y las versalitas quedan como lo que son, la marca de un área.
2. **El eje temático parecía colgar del último curso.** `.oer-align-axes` compartía la sangría de `.oer-align-line`, que es de grupo; el eje es del REA entero. Se le quita la sangría y se le da aire arriba.
3. **Dos verticales ámbar en la misma columna.** El canto de `.oer-align-orphans` rimaba con el riel del área a dos centímetros y se leía como el mismo aviso contado dos veces. Se retira: la forma de «Sin encajar» es su rótulo, que basta para la regla 3.
4. **La banda tenía tres niveles de encabezado para tres líneas.** Los `<h5>` por severidad pasan a `visually-hidden`: el contador ya dice «1 error, 2 avisos» y cada incidencia lleva su glifo ⛔/⚠, pero el nombre accesible tiene que seguir enunciando la severidad (ADR-0014 §3).

Y dos de composición al mirar los modos: los cinco botones de acción se envuelven contra el ancho de los campos (`max-width: 34em`) en dos renglones que leen como dos grupos —empujar `Cancelar` con `margin-left: auto` lo dejaba solo al otro lado de un hueco de 20em en cuanto la fila se partía—, y por debajo de 900px la columna lateral necesita la misma sangría de `1em` que el área de anclaje, o arranca descuadrada justo donde deja de haber dos columnas.
