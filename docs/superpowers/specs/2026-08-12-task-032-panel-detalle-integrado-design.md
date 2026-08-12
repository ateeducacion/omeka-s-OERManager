# Panel de detalle integrado en la tabla — diseño (TASK-032)

> **Fecha:** 2026-08-12 · **Tarea:** TASK-032. Pedida por el propietario el 2026-08-12.
> **Gobierno previo:** el catálogo de campos lo fijó TASK-027 (aprobado por **ADR-0013**); la norma visual, **ADR-0014**; el registro de curación que aquí se lee, **ADR-0015**; el anclaje bottom-up, **ADR-0010**.
> **Regla de corte:** esta tarea **no escribe nada nuevo**. Reorganiza superficies de lectura y recoloca el panel de re-catalogación que ya existe.

---

## 1. Por qué esta tarea existe

El drawer de detalle es hoy un panel fijo de `28em` anclado al borde derecho (`asset/js/ui/drawer.js`, `.oer-drawer` en `oer-master-view.css`), declarado `role="dialog"`. Nació en ADR-0005 §6 como cajón de consulta y ha ido acumulando superficies: los nueve campos de la v1, y en la rebanada 3a de TASK-028 la integridad, el historial, el enlace al editor nativo y el recuento de medios.

Tres problemas, y ninguno se arregla añadiendo más contenido al cajón:

1. **El curador no puede verificar la calidad desde ahí.** El trabajo real —según el propietario, 2026-08-12— es **abrir los archivos del REA**: son lo que de verdad hay que juzgar. Hoy el panel dice cuántos medios hay y nada más.
2. **El anclaje se muestra como listas planas por dimensión.** Con dos cursos mezclados, el curador no puede saber qué saber pertenece a qué curso. Es el mismo defecto de lectura que la rebanada 2 encontró en la tabla y resolvió allí con pares materia-curso; en el drawer sigue vivo.
3. **El panel compite con la tabla en vez de integrarse.** `position: fixed` sobre el listado, con `max-width: 90vw` en pantallas estrechas: tapa justo el contexto desde el que se abrió.

## 2. Alcance

**Entra:**

1. **La fila de la tabla se expande en su sitio** y el detalle ocupa todo el ancho de la tabla, con áreas delimitadas.
2. **Miniatura del item junto al título.**
3. **Anclaje curricular agrupado** por curso · materia, con sus saberes y criterios colgando de cada grupo, los ejes temáticos aparte, y un bloque explícito para lo que no encaja.
4. **Lista de medios abribles**, con nombre, tipo y tamaño.
5. **Historial de curación bajo demanda**, plegado por defecto y **cargado solo al desplegarlo**.
6. **Integridad**, recolocada como banda de veredicto.
7. **Re-catalogación**: el panel que ya existe, recolocado en su propia área. No se reescribe.

**No entra:**

- **Todo lo editable.** Título, descripción, licencia y proyecto siguen siendo de solo lectura. Eso es la rebanada 3b de TASK-028, que necesita `clear_property_values` + `collectionAction=append`, validación por campo, CSRF, ACL de escritura y el vocabulario de licencias de **PEND-011**, sin decidir. **Decisión del propietario (2026-08-12):** esta tarea entrega la estructura; lo editable aterriza después dentro de las áreas ya construidas.
- Previsualización en línea de los medios (ver P-4).
- La cola de calidad con contadores, que es la rebanada 4 de TASK-028.

---

## 3. Decisiones

| ID | Decisión | Motivo |
| --- | --- | --- |
| **P-1** | El detalle vive en una **fila expandida dentro de la tabla** (`<tr><td colspan>`), no en un panel flotante | Decisión del propietario. Ocupa todo el ancho de la tabla —que es la parte central de la pantalla— **sin** perder el sitio en el listado. Ver §6 |
| **P-2** | **Una sola llamada autenticada** pinta el panel entero, y el panel deja de leer el JSON-LD anónimo. El historial es la única excepción: viaja en una segunda llamada, y solo si se despliega (P-6) | Obligado por tres hechos medidos, no por gusto. Ver §5 |
| **P-3** | El agrupamiento curricular se resuelve **en el servidor**, en una clase pura probada en host | El cliente no puede: el curso ancestro vive en el saber, no en el REA. Ver §4 |
| **P-4** | Los medios se **abren en pestaña nueva**; no hay previsualización en línea | Decisión del propietario. Trata todos los tipos igual, no añade componentes que haya que hacer accesibles, y **9 de los REA son ZIP de SCORM**, que ningún navegador previsualiza |
| **P-5** | Lo que no encaja en la jerarquía se muestra en un **bloque propio y marcado**, no se esconde ni se infiere | Decisión del propietario. Ver §4 |
| **P-6** | El historial se carga **solo al desplegarlo** | Lo pidió el propietario por ruido; además es la parte cara (una lectura por id referenciado) |
| **P-7** | **Una fila expandida a la vez** | La tabla sigue legible y el coste de lectura queda acotado |

---

## 4. El agrupamiento, y lo que no encaja (P-3, P-5)

**Por qué el servidor.** Para saber que el saber `B3.1` pertenece a *3º ESO · Biología y Geología*, no basta con el item: su JSON-LD solo trae el enlace al saber. El curso ancestro vive **en el saber**, alcanzable por `lrmi:educationalAlignment` / `lrmi:educationalLevel` — las mismas aristas que `RecatalogService::qualifiedTitle()` ya recorre desde la rebanada 1 para desambiguar títulos homónimos. Agrupar en el cliente exigiría pedirle el currículo entero al navegador.

**Qué es «no encajar».** Un valor queda fuera de grupo cuando la jerarquía que declara el REA no lo sostiene. Los dos casos medidos en el catálogo real:

- un **curso sin ninguna materia** que lo sostenga — afecta a **7 REA**, y es lo que desde el 2026-08-11 degrada su anclaje a *parcial* (ADR-0016);
- un **saber o criterio cuyo curso no está declarado** en el item.

**Por qué se muestran.** Esconderlos, o colgarlos del grupo más probable, haría que la pantalla dejara de reflejar lo que el REA dice de verdad, y taparía justo el defecto que el módulo ya sabe detectar y penalizar en la columna de anclaje. El panel debe ser donde se ve **por qué** un REA está parcial, no solo que lo está.

**Los ejes temáticos no se agrupan.** `dcterms:relation` es un vocabulario plano: todos sus términos cuelgan del mismo `DefinedTermSet`. Agruparlos produciría un único grupo con un rótulo constante que no distingue nada — el mismo motivo por el que `RecatalogService::UNQUALIFIED_TERM` los excluye de la cualificación de títulos. Van en su propia línea, al final del área.

---

## 5. Los tres hechos que obligan a la llamada única (P-2)

Medidos sobre el catálogo real el 2026-08-12:

| Hecho | Consecuencia |
| --- | --- |
| **`o:thumbnail_display_urls` no aparece** en el JSON del item | La miniatura no se puede obtener del JSON-LD |
| **`o:media` solo trae `[{@id, o:id}]`** | Ni nombre, ni tipo, ni tamaño: la lista de medios tampoco sale de ahí |
| El drawer carga con `fetch(apiUrl)` **sin autenticar** (`drawer.js`) | Los 19 REA son públicos hoy, así que no muerde; pero el módulo tiene un control de visibilidad, y **el primer REA que se ponga en privado dejará de poder abrir su propio panel**. Defecto latente |

Los tres se resuelven de una vez sirviendo el panel entero desde la acción autenticada que la rebanada 3a ya estrenó. Es además la misma lección que esa rebanada aprendió con los eventos privados (`is_public => false`) y con la URL del editor: **lo que el servidor sabe, lo entrega el servidor**.

---

## 6. Layout (P-1)

**Regla rectora**, de ADR-0014 §1: *una pantalla de curación se ordena alrededor de la decisión que habilita*. La decisión aquí es **¿este REA es lo que dice ser?**, y verificarlo es comparar lo que declara con lo que sus archivos contienen. Por eso **Anclaje y Medios van lado a lado**: son las dos mitades de esa comparación.

```
┌──────────────────────────────────────────────────────────────┐
│ [img] Título del REA                 Público   Abrir en Omeka▸│
├───────────────────────────────┬──────────────────────────────┤
│ ANCLAJE CURRICULAR            │ MEDIOS (3)                   │
│  3º ESO · Biología y Geología │   guia.pdf     PDF    Abrir ▸│
│    Saberes    B3.1 · B3.2     │   clase.mp4    vídeo  Abrir ▸│
│    Criterios  CE2.1           │   paquete.zip  SCORM  Abrir ▸│
│                               ├──────────────────────────────┤
│  1º Bach · Matemáticas I      │ INFORMACIÓN                  │
│    Saberes    SBII.2.3        │   Descripción  …             │
│    Criterios  —               │   Licencia     CC BY-SA      │
│                               │   Proyecto     …             │
│  ⚠ Sin encajar                │   Tipo         …             │
│    Curso  4º ESO              │                              │
│  Ejes  Patrimonio             │                              │
├───────────────────────────────┴──────────────────────────────┤
│ ⚠ Integridad · 2 incidencias                                 │
├──────────────────────────────────────────────────────────────┤
│ RE-CATALOGAR   [el panel que ya existe, recolocado]          │
├──────────────────────────────────────────────────────────────┤
│ ▸ Historial de curación                          (plegado)   │
└──────────────────────────────────────────────────────────────┘
```

**Por qué cada área está donde está**

- **Cabecera.** La miniatura ancla el REA visualmente y distingue su tipo de un vistazo. Sobre lo que **no** hay que engañarse, ver §7.1.
- **Anclaje (2fr) | Medios (1fr).** El anclaje crece con los grupos; los medios rara vez pasan de tres. El bloque «Sin encajar» cierra la columna, donde no compite con lo que sí está bien.
- **Información** bajo Medios: es contexto, no la decisión, y su descripción puede ser larga sin empujar nada.
- **Integridad**, banda a todo lo ancho: es un veredicto sobre el conjunto, no un dato de una columna.
- **Re-catalogar** al final del bloque de lectura: primero se entiende, luego se actúa.
- **Historial** plegado y perezoso (P-6).

**Responsive.** Por debajo de ~900 px las dos columnas se apilan en una, en el orden anclaje → medios → información. La tabla ya usa `tablesaw` en modo `stack`: la fila de detalle sigue esa lógica y no inventa otra.

**Accesibilidad: cambia el contrato, a propósito.** Al dejar de ser `role="dialog"`, el panel ya no atrapa el foco ni se cierra con Esc por convención de diálogo. A cambio da lo que un diálogo no puede: no perder el sitio en el listado. El disparador de la fila pasa a llevar `aria-expanded`, y la fila de detalle se anuncia como región con nombre.

---

## 7. Lenguaje visual

Todo lo que sigue se deriva de **ADR-0014**, que manda sobre cualquier criterio genérico de diseño.

- **El panel es un estante, no una tarjeta.** Fondo `--oer-surface`; la fila madre se marca con `--oer-select`. El detalle se lee como algo que la tabla abre **dentro de sí**.
- **El riel continúa.** La fila de detalle hereda el riel de color de su fila madre (§4 del ADR). Cortarlo dejaría el canto roto justo en la fila que se está mirando.
- **Las áreas se delimitan con aire**, no con cajas: rótulo corto en versalitas `--oer-muted` y un único filete `--oer-rule` entre columnas. Con seis áreas, seis marcos convertirían el panel en una hoja de cálculo. El presupuesto se gasta en jerarquía (§1).
- **El rojo sigue reservado** (§2): los enlaces «Abrir» van en tinta en reposo y toman el acento en `:hover`/`:focus`. El acento queda para lo que reclama acción.
- **Todo estado accionable tiene forma** (§3): «Sin encajar» lleva glifo **y** rótulo en texto; la banda de integridad conserva los encabezados textuales de severidad de la rebanada 3a.
- **Miniatura:** 64 px, `object-fit: cover`, filete `--oer-rule`. Si falta, un hueco que **se lee como ausencia**, no un icono de imagen rota: un REA sin miniatura es un dato, no un fallo de carga.

### 7.1 La miniatura miente en 15 de los 19 REA, y hay que decirlo

Medido sobre el catálogo real el 2026-08-12, contando `media.has_thumbnails`:

| Tipo | Con derivadas reales | Sin ellas |
| --- | --- | --- |
| Imágenes (`jpeg`, `png`) | 33 | 0 |
| Vídeo (`mp4`, `quicktime`) | 15 | 0 |
| `application/pdf` | 13 | 6 |
| `application/zip` (SCORM) | 0 | **9** |

**Solo 4 de los 19 REA tienen algún medio con miniatura real.** En los otros 15, `thumbnailDisplayUrls()` devuelve un **asset genérico de Omeka** (`/files/asset/…png`), es decir el icono del tipo de fichero, no una vista del recurso. Los 9 SCORM no pueden tener otra cosa.

**Consecuencia para el diseño:** la miniatura **no** es una verificación de contenido en este catálogo. Sirve para anclar visualmente la fila y para distinguir el tipo de un vistazo, y eso ya justifica ponerla — pero el panel **no debe apoyarse en ella** para juzgar calidad, y el texto de la interfaz no debe sugerir que lo hace. La verificación real es abrir los medios (§2.4).

**Lo que esto NO decide.** Que falten derivadas puede ser un defecto de ingesta reparable (`omeka-s` puede regenerarlas) o una limitación del tipo de fichero. Averiguarlo y, si procede, regenerarlas es **trabajo propio, fuera de esta tarea**: aquí solo se declara el hecho para que el diseño no se apoye en algo que no existe.
- **Movimiento:** la expansión **no anima la altura** —reflota la tabla y da tirones—; a lo sumo una transición corta de opacidad, anulada bajo `prefers-reduced-motion`.

**Sin tokens nuevos.** Todo sale de las variables ya declaradas en `oer-master-view.css`.

---

## 8. Arquitectura

La frontera de siempre: **la decisión, pura y probada en host; el glue del core, delgado.**

### 8.1 Piezas nuevas

| Fichero | Qué decide |
| --- | --- |
| `src/Service/Governance/CurricularGrouping.php` | De los valores del item al modelo agrupado: `[{curso, materia, saberes[], criterios[]}]`, ejes aparte y bloque `sinEncajar`. **Aquí vive la regla de qué es huérfano**, que es lo más fácil de equivocar |
| `asset/js/core/detailAreas.js` | Qué áreas se pintan, en qué orden, y en cuál de sus tres estados |

### 8.2 Glue

| Fichero | Cambio |
| --- | --- |
| `src/Service/RecatalogService.php` | Resolución de la jerarquía curso-materia y de los metadatos de medios y miniatura |
| `src/Controller/Admin/IndexController.php` | `drawer-details` devuelve el panel completo; el historial pasa a su propia acción perezosa |
| `asset/js/ui/drawerDetails.js` | Pinta las áreas dentro de la fila expandida |
| `asset/js/ui/drawer.js` | Abre y cierra la fila; deja de gestionar un diálogo |
| `view/oer-manager/admin/index/index.phtml` | La fila de detalle y su `colspan` |
| `asset/css/oer-master-view.css` | La rejilla y las áreas; se retira `.oer-drawer` fijo |

### 8.3 Reutilizar, no reescribir

`qualifiedTitle()` y su recorrido de ancestros · `IntegrityChecker` y el modelo de la 3a · `CurationHistory` y `historyModel.js` · `drawerModel.js` para los campos de la ficha · el enganche por evento `oer:drawer-rendered` del panel de re-catalogación, que **no se toca**.

---

## 9. Errores

Principio, heredado de lo que costó caro en la rebanada 3a: **«no pude leer» y «no hay nada» nunca comparten pantalla.** Cada área declara sus tres estados por separado — cargando, vacío con su motivo, y fallo con su motivo. La llamada única ayuda: un fallo se ve una vez, en el panel, y no repetido seis veces.

El REA privado deja de ser un problema: la llamada va autenticada (§5).

---

## 10. Riesgos

| Riesgo | Mitigación |
| --- | --- |
| El agrupamiento se equivoca en silencio y el curador lee un anclaje que no es | La regla vive en una clase pura con suite en host, y el caso real de los 7 REA es uno de sus tests |
| El coste crece: jerarquía + medios + integridad en cada apertura | Historial perezoso (P-6), una fila abierta a la vez (P-7). Se mide en el arnés |
| `drawerDetails.js` se convierte en el cajón de sastre que la rebanada 1 desmontó | Las áreas se deciden en `core/detailAreas.js`; la capa de UI solo pinta |
| Perder el atrapado de foco degrada la accesibilidad | Se cambia el contrato a propósito (§6) y se verifica en la sesión de navegador |
| La fila expandida rompe el modo `stack` de tablesaw en pantallas estrechas | Es lo primero que hay que mirar en el navegador, no al final |

---

## 11. Verificación

**Host (obligatorio, TDD real).** PHPUnit sobre `CurricularGrouping`: dos cursos mezclados, un saber cuyo curso no está declarado, un curso sin materia que lo sostenga, los ejes —que **no** se agrupan—, y el item sin anclaje. `node --test` sobre `detailAreas.js`, incluidos los tres estados de cada área.

**Contenedor.** Arnés que compruebe que el agrupamiento devuelto coincide con los valores reales del item, y que mida el coste de una apertura.

**Navegador con sesión real. Obligatorio y no negociable aquí:** es un cambio de layout, y ningún arnés puede juzgarlo. La sesión del 2026-08-10 encontró dos defectos que quince revisiones por tarea y una revisión final de rama no habían visto. Debe cubrir al menos: un REA con dos cursos, uno con el curso huérfano, uno sin medios, uno sin miniatura, y el modo estrecho.

---

## 12. Consecuencias de gobierno

- **Sin ADR nuevo.** Nada aquí fija norma que ADR-0013, ADR-0014 y ADR-0015 no cubran ya. P-1 y P-5 son aplicaciones de ADR-0014 §1 y §3; P-3 es una lectura de ADR-0010.
- **TASK-028 rebanada 3b hereda estructura**: sus campos editables aterrizan dentro de las áreas que esta tarea construye, en vez de definir las suyas.
- **ADR-0005 §6 queda desactualizado** en su descripción del drawer como panel lateral. No se reescribe (append-only, ADR-0001): la corrección se registra en `project-memory.md`.

---

## 13. Resumen en una frase

El detalle del REA deja de ser un cajón que tapa la tabla y pasa a abrirse dentro de ella, poniendo lado a lado lo que el REA **dice** que enseña y los archivos que **son** el REA — que es la comparación que el curador venía haciendo a ciegas.
