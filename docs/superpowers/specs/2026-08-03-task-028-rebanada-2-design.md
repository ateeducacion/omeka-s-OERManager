# Columnas y filtros de gobernanza — diseño (TASK-028, rebanada 2)

> **Fecha:** 2026-08-03 · **Tarea:** TASK-028, segunda rebanada.
> **Gobierno previo:** el QUÉ lo fijó TASK-027 (`2026-07-27-campos-ui-design.md`, aprobado por **ADR-0013**); el CÓMO estructural lo fijó la rebanada 1 (`2026-07-28-refactor-ui-design.md`); la norma visual la fijó **ADR-0014**.
> **Regla de corte:** esta rebanada añade campos a la tabla y reglas al comprobador de integridad. No toca el drawer, ni el historial, ni las acciones de lote.

---

## 1. Por qué esta rebanada existe

La rebanada 1 dejó la UI bien estructurada y con **los mismos campos de siempre**: columnas registradas como `column_types` nativos, filtros computados con total exacto, autocompletado en lugar de ids en crudo. Lo que no hizo —a propósito— fue cambiar **qué** se muestra.

El desequilibrio que TASK-027 midió sigue intacto: el catálogo tiene el anclaje curricular al 100 % y la ficha de gobernanza vacía (**0 % autoría, 5 % licencia, 0 % plantillas**), y la tabla dedica su superficie exactamente a lo primero. Además hay dos activos construidos y jamás mostrados: `IntegrityChecker`, que se calcula en cada guardado desde TASK-005 y **solo va al log**, y las 340 anotaciones de auditoría (esas son de la rebanada 3).

Esta rebanada estrena el primero de esos dos activos y reequilibra la tabla hacia la gobernanza.

---

## 2. Decisiones

| ID | Decisión | Motivo |
| --- | --- | --- |
| **D-1** | **Sin autoría.** Las columnas y el filtro de autoría se van a una rebanada 2b | RF-015 y PEND-011 siguen abiertos en su contenido. Todo lo demás se puede diseñar sin ellos |
| **D-2** | **Anclaje e Integridad conviven.** El riel de fila se ancla a **Integridad** | ADR-0013 §Afinado ya dejó dicho que si conviven, la rectora es integridad, y que retirar la señal de anclaje pierde capacidad supervisora a escala de miles de REA. **Se solapan en parte, a propósito**: Anclaje gradúa el ajuste curricular en tres estados y tiene filtro propio; Integridad agrega «ficha sana» en un contador. Responden preguntas distintas — «¿está ajustado al currículo?» frente a «¿está completa la ficha?» |
| **D-3** | El **literal en property de enlace** (defecto D2 del estudio) pasa a ser un issue del comprobador, severidad **aviso** | Una sola regla alimenta columna, filtro y drawer. Sin esto el riel se anclaría a una señal que miente igual que la que sustituye: hoy `checkCompleteness` cuenta un literal como valor presente y la integridad dice «ok» |
| **D-4** | **Glifo solo donde dispara una acción.** Integridad y literal-en-enlace llevan glifo; licencia ausente y tipo fuera de vocabulario van en tinta apagada con texto explícito | ADR-0014 regla 3 al pie de la letra. Con 18/19 sin licencia y 16/19 con el tipo fuera del vocabulario, marcarlos sería marcar la tabla entera, y una marca presente en el 90 % de las filas deja de señalar la excepción |
| **D-5** | **Semáforo de dos estados** (ok / aviso). El `error` queda definido pero sin pintar | Ver §4.1: `dead_link` es casi inalcanzable por diseño del core, así que hoy **ningún dato puede producir un error**. No se inventa un estado sin productor |
| **D-6** | **Las reglas mínimas se aplican siempre**, haya plantilla o no; los campos obligatorios de la plantilla se **suman**, no sustituyen | Cierra la trampa de PEND-012 (§4.2) antes de que se abra. Cambia la semántica de RF-006 → nota de gobierno obligatoria |
| **D-7** | La comprobación de **enlace vivo** pasa a ser opcional: encendida en el listener, apagada en columna y filtro | Es la única parte que despierta un proxy Doctrine por valor, y persigue un caso que la FK en cascada hace casi imposible. Coste plano en el barrido del filtro |
| **D-8** | La columna «Alineamiento» se **renombra a «Anclaje»** en la UI | Decisión del propietario (2026-08-03). Ver §8 para el alcance exacto del renombrado |

---

## 3. Columnas

### 3.1 Juego por defecto

Ocho, que es el tope que fijó TASK-027 §3 (selección + 8):

| # | Columna | Procedencia | Estado |
| --- | --- | --- | --- |
| 1 | Título | `o:title`, con `o:id` como línea secundaria | MANTIENE, gana «(sin título)» marcado |
| 2 | Anclaje | calculado (`AlignmentStatus`) | MANTIENE, renombrada (D-8) |
| 3 | **Integridad** | calculado (`IntegrityChecker`) | **NUEVA** |
| 4 | **Curricular** | `lrmi:educationalLevel` + `schema:about` | **NUEVA** (fusiona dos columnas en una) |
| 5 | **Tipo de recurso** | `lrmi:learningResourceType` | **NUEVA** |
| 6 | Licencia | `dcterms:rights` | MANTIENE, gana estado vacío explícito |
| 7 | Visibilidad | `o:is_public` | MANTIENE |
| 8 | Modificado | `o:modified` | MANTIENE |

Salen del juego por defecto los dos `oerValue` de `lrmi:educationalLevel` y `schema:about` (los sustituye Curricular) y el `oerValue` de `dcterms:rights` (lo sustituye la columna con estado vacío). **Siguen registrados**: un curador puede reactivarlos desde la configuración nativa de columnas, igual que la rebanada 1 conservó `'items'` en `getResourceTypes()` para no retirar una posibilidad existente.

> **Nota de despliegue.** `column_defaults` solo aplica a quien **no** haya guardado su propia selección de columnas. Quien la guardó tras la rebanada 1 conservará las columnas viejas hasta que la reajuste. No es un defecto: es cómo funciona el mecanismo nativo, y el motivo por el que la selección es una preferencia del usuario y no una decisión irreversible del módulo.

### 3.2 Columna Curricular

Sustituye dos columnas por una y arregla dos defectos del estudio:

- **Dedup por título con linaje.** El currículo repite «Matemáticas» en cuatro cursos y «Conocimiento del Medio…» en tres; la tabla mostraba cuatro chips idénticos. Se muestra `Materia (+N cursos)`, con el detalle en el `title`.
- **Marca del literal (D2).** El valor literal en una property de enlace se pinta hoy como enlace legítimo. Pasa a llevar ⚠ — es uno de los dos únicos glifos que D-4 autoriza, porque pide una acción concreta y distinta: promover el valor a enlace.

**No hay lógica nueva de linaje.** El curso ancestro ya está resuelto en el repo dos veces: `RecatalogService.php:477` (cascada `lrmi:educationalLevel` → `lrmi:educationalAlignment` → `schema:inDefinedTermSet`, decidida por el propietario el 2026-07-29) y `CurriculumSearch::mapResults()`, que devuelve `courseId`/`courseTitle`. La columna consume, no reimplementa.

### 3.3 Columnas Tipo y Licencia

Ambas son el mismo problema: mostrar un valor de property con **estado vacío explícito**. Hoy la celda vacía no dice nada, y con 18/19 sin licencia eso es precisamente la información que falta.

Un único tipo de columna reutilizable, parametrizado por `property_term` y por la etiqueta de ausencia, registrado dos veces en `column_defaults`. No dos clases casi idénticas.

**Revisión consciente de TASK-027 §3.** El estudio pedía para Licencia tres estados marcados en la celda (*valor del vocabulario* / *fuera del vocabulario* / *sin licencia*). D-4 lo reduce a **texto y tinta, sin glifo**: la distinción «fuera del vocabulario» gradúa severidad pero no dispara una acción distinta de la que ya ofrece el filtro de gobernanza. Los tres estados **siguen existiendo** en el filtro y en el drawer (rebanada 3); lo que se retira es la marca en la fila.

---

## 4. Integridad

### 4.1 Qué comprueba hoy, y por qué eso obliga a cambiarlo

`IntegrityChecker::check()` aplica tres reglas, **y la segunda y la tercera son excluyentes entre sí**:

1. **Enlace vivo** (`dead_link`, error): valor cuyo `type` empieza por `resource` y no tiene destino.
2. **Con plantilla**: los campos que la plantilla marque obligatorios deben tener valor (`missing_required`, aviso). **Y nada más.**
3. **Sin plantilla**: las cuatro properties de anclaje presentes (`missing_alignment`) + `dcterms:rights` presente (`missing_license`), aviso.

Dos hallazgos de esta lectura, ambos verificados contra el código:

**El `error` no tiene productor.** En `application/src/Entity/Value.php` del core, la asociación al recurso destino es `@ManyToOne(targetEntity="Resource")` con `@JoinColumn(onDelete="CASCADE")`. Al borrarse el item-término, **la base de datos borra el valor entero**: no queda un enlace apuntando al vacío. `dead_link` solo podría darse con un `type` de recurso y destino nulo, que es un estado que la escritura normal no produce. De ahí **D-5**: el semáforo pinta dos estados, no tres.

> Pendiente de medición: el recuento exacto de `dead_link` en el catálogo real quedó sin ejecutar (bloqueo de permisos al consultar la BD). Se mide al implementar, con el arnés de §9. Si aparecieran casos, se enciende el tercer estado sin rehacer nada.

**La rama de plantilla puede silenciar la señal.** Es la trampa que TASK-027 §8.1 anticipó. Hoy 0/19 REA tienen plantilla, así que los 19 van por la rama 3 y el único aviso que se emite es `missing_license` (18 de 19). En cuanto PEND-012 aterrice la plantilla REA y se asigne, el comprobador **salta a la rama 2** y deja de mirar anclaje y licencia salvo que la plantilla los declare obligatorios: la integridad podría pasar de 18 avisos a cero **sin que el catálogo haya mejorado en nada**.

### 4.2 Reglas nuevas (D-6)

Las reglas mínimas dejan de ser la rama «else» y pasan a aplicarse **siempre**:

| Regla | Código | Severidad | Cuándo |
| --- | --- | --- | --- |
| Anclaje presente (×4 properties) | `missing_alignment` | aviso | Siempre |
| Licencia presente | `missing_license` | aviso | Siempre |
| Literal en property de enlace | `literal_in_link_property` | aviso | Siempre — **nueva** (D-3) |
| Campo obligatorio de plantilla ausente | `missing_required` | aviso | Solo si el REA tiene plantilla, **sumándose** a lo anterior |
| Enlace muerto | `dead_link` | error | Solo con la comprobación encendida (D-7) |

**Efecto medible sobre el catálogo actual:** los 18 REA sin licencia siguen con su aviso; los 4 valores literales (`schema:about` ×3, `educationalLevel` ×1) añaden un segundo aviso a sus REA y dejan de pasar por «ok».

**Lo honesto sobre la varianza:** con el anclaje al 100 %, la columna seguirá diciendo mayoritariamente «falta licencia», que es lo que la celda vecina ya dice con palabras. El valor de D-6 no es la varianza de hoy — es que la señal **ya no puede ser silenciada** por asignar una plantilla, y que la columna crece sola según se pueble la ficha de gobernanza.

### 4.3 Coste (D-7)

`ValueRepresentation::valueResource()` no es un getter barato: obtiene la entidad destino, llama a `getResourceName()` —lo que **inicializa el proxy Doctrine**, una consulta— y construye la representación. Con ~18 valores de anclaje por REA, evaluar el predicado de integridad sobre el tope de 2 000 items del `ComputedFilter` serían miles de consultas en una sola carga de página.

`check()` gana un interruptor para esa comprobación:

- **Listener de TASK-005** (`api.create.post` / `api.update.post`): encendida. Un item por guardado, coste irrelevante, y es el sitio correcto para cazar el caso patológico.
- **Columna y filtro**: apagada. No se paga por buscar algo que la FK en cascada hace casi imposible.
- **Drawer** (rebanada 3): encendida. Un item a la vez, y ahí el detalle importa.

Todo lo demás se calcula **al vuelo**, sin caché ni estado nuevo: NFR-002 intacto y sin problema de invalidación.

---

## 5. La frontera pura / glue

**El problema a resolver.** `IntegrityChecker` no tiene hoy ni un test de host: en `test/Service/` no existe `IntegrityCheckerTest`, y lo único que `ModuleConfigTest` afirma es que el servicio está registrado. Es la limitación conocida del arnés (TASK-008): la clase tipa `ItemRepresentation`, así que no se puede instanciar fuera del contenedor. Meter las reglas de D-3 y D-6 ahí dentro sin más las haría nacer **sin poder probarse**.

La respuesta es la que ya funcionó en TASK-010 (puertos/adaptadores) y en TASK-029 (`ConfigPayload` llevándose el mapeo de `Module.php` a un sitio probable): **cada columna es un adaptador delgado sobre una pieza pura**.

| Pieza pura (host, PHPUnit) | Qué decide | Entrada |
| --- | --- | --- |
| `IntegrityPolicy` | Las reglas de §4.2 | Datos planos: término → lista de `{type, tieneDestino}`, más los campos obligatorios de la plantilla si la hay |
| `CurricularSummary` | Dedup por título, `Materia (+N cursos)`, marca del literal | Lista de `{título, cursoAncestro, esLiteral}` |
| `ValuePresence` | Valor presente / ausente, para Licencia y Tipo | El valor y su etiqueta de ausencia |

`IntegrityChecker` se queda como **proyector**: traduce `ItemRepresentation` a esa forma plana y delega en `IntegrityPolicy`. `IntegrityResult` no cambia — ya calcula el status desde la severidad de los issues y filtra por severidad.

Consecuencia directa: D-3 y D-6 nacen con tests escritos primero, y una clase que llevaba desde TASK-005 sin cobertura automatizada pasa a tenerla.

---

## 6. Filtros

### 6.1 Los baratos, a `MasterViewQuery`

Cuatro filtros de gobernanza expresables como query de la API (`property[type=nex]`), sin coste computado:

| Filtro | Property | Afectados hoy |
| --- | --- | --- |
| Sin licencia | `dcterms:rights` | 18/19 |
| Sin descripción | `dcterms:description` | 4/19 |
| Sin título | `dcterms:title` | 1/19 |
| Sin tipo de recurso | `lrmi:learningResourceType` | 3/19 |

Van a la búsqueda avanzada que la rebanada 1 construyó (`searchAction` + `search.phtml`), que es donde viven los filtros que no caben en la barra rápida.

**Se deja fuera «sin autoría»** pese a figurar como imprescindible en TASK-027 §9: con 19/19 sin autoría devuelve el catálogo entero, y sin su columna no comunica nada. Entra en la rebanada 2b junto a RF-015.

### 6.2 El computado, y la mejora que obliga

El filtro de **estado de integridad** (ok / aviso) no es expresable como query: se evalúa por item. Va en la barra rápida, junto a Anclaje, porque es la señal rectora.

`indexAction` tiene hoy una rama `if/else` escrita a medida del filtro «parcial». Añadir un segundo predicado computado duplicaría ese bloque entero —la búsqueda con `per_page = HARD_CAP`, el `ComputedFilter`, el `paginator`, el cálculo de `isTruncated`—. Se extrae un resolutor **`query → predicado|null`** para que quede **una sola** rama computada en el controlador, sirviendo a los dos filtros.

`ComputedFilter` **no cambia**: el patrón obligatorio de ADR-0013 (ids del conjunto completo con tope duro → predicado → paginar) ya está construido y probado. Esta rebanada es su segundo consumidor, que es exactamente para lo que se hizo.

---

## 7. Presentación

### 7.1 Riel y glifos

El riel de 3px en el canto de la fila —introducido en la pasada visual de la rebanada 1 y elevado a norma por ADR-0014 regla 4— **se re-ancla a Integridad**. Es el movimiento que aquella pasada dejó previsto por escrito: «el mecanismo es la escala visual, no la property».

Anclaje conserva su ✓/⚠ propio. **Dos glifos por fila como máximo**, y cada uno pide una acción distinta:

| Glifo | Dónde | Acción que pide |
| --- | --- | --- |
| ⚠ | Integridad | Abrir el drawer y ver las incidencias |
| ⚠ | Curricular | Promover el valor literal a enlace (D2) |
| ✓ / ⚠ | Anclaje | Revisar el ajuste curricular |

Licencia ausente y tipo fuera de vocabulario: **tinta apagada y texto explícito**, sin glifo (D-4).

### 7.2 Contraste WCAG AA — automatizado, no a ojo

ADR-0014 dejó como obligación de la rebanada que estrenase la columna de integridad **medir el contraste de la tríada de estado contra WCAG AA**: hoy no está medido y los valores se eligieron por criterio.

Se cumple de forma **ejecutable**: los colores de estado se declaran como tokens y un test `node --test` calcula el ratio contra el fondo real del admin y falla por debajo de **4.5:1**. Así la obligación deja de ser una nota en un documento y pasa a ser una prueba que se rompe si alguien mueve un hexadecimal.

Si la medición obliga a mover los valores de la tríada, se mueven: ADR-0014 norma el criterio, no los hexadecimales.

---

## 8. Renombrado: Anclaje

Decisión del propietario (2026-08-03). Alcance exacto:

- **Cambia:** el rótulo de la columna (`AlignmentStatus::getLabel()`), el rótulo del filtro y las cadenas de UI asociadas.
- **No cambia:** el nombre de la clase `AlignmentStatus`, sus constantes (`COMPLETE`/`PARTIAL`/`NONE`) ni el parámetro de query `alignment`. Los internos espejan el vocabulario RDF (`lrmi:educationalAlignment`), que no se renombra, y el parámetro de query sostiene los enlaces y marcadores ya existentes.

Las cadenas nuevas no estarán en `language/*.mo` y degradarán al literal español, que es el texto correcto — mismo comportamiento y mismo caveat que la rebanada 1.

> **Revocable.** Si el propietario prefiere que el renombrado alcance también a la clase y al parámetro, es un cambio mecánico; se hace en esta misma rebanada y se anota en ADR-0013.

---

## 9. Pruebas y verificación

**Host (obligatorio, TDD real).**

- PHPUnit sobre las tres piezas puras: reglas de §4.2 (incluidos D-3 y la suma plantilla+mínimo de D-6), semáforo de dos estados, dedup curricular con los 7 casos «Matemáticas ×4» y el estado vacío de Licencia/Tipo.
- `node --test` para el contraste de la tríada (§7.2).
- Ampliación de `ModuleConfigTest` para las columnas nuevas y el `column_defaults` de §3.1.

**Contenedor (arnés nuevo, estilo `acl-check.php` / `config-page-check.php`).** Renderiza la fila de los 19 REA y comprueba que Integridad y Curricular dicen lo que el dato manda — en particular los **4 valores literales**, que hoy pasan por «ok» y deben pasar a aviso, y el recuento de `dead_link` que quedó sin medir (§4.1). Se mide también el coste del filtro de integridad sobre el conjunto completo, para confirmar que D-7 aplana lo que debía aplanar.

**Navegador con sesión real:** entra en la deuda de **TASK-030**, como todo el JS del módulo. Se declara, no se finge.

---

## 10. Fuera de alcance

| Qué | Dónde va |
| --- | --- |
| Columna y filtro de autoría | Rebanada 2b (RF-015, PEND-011) |
| Historial de las 340 anotaciones, gobernanza editable en el drawer | Rebanada 3 (PEND-013) |
| Cola de calidad con contadores y presets | Rebanada 4 — su agregación es la misma que RF-007 pide para TASK-006, y debe construirse **una vez** y consumirse dos |
| Acciones de lote nuevas (asignar licencia, normalizar tipo) | Rebanada 4 |
| Higiene masiva (literal→enlace, `type` genérico, normalizar licencias) | Exige **ADR propio**: reescribe RDF ya escrito. Esta rebanada **detecta**, no reescribe |
| Sembrar el CustomVocab de licencias y la plantilla REA | ADR-0013 ya fijó el mecanismo; el contenido es PEND-011/012 |

---

## 11. Consecuencias de gobierno

- **RF-006 cambia de semántica** (D-6): las reglas mínimas dejan de ser excluyentes con la plantilla. Es una ampliación de lo que PEND-007 punto 4 aprobó y **requiere nota en ADR-0013** al cerrar la rebanada.
- **ADR-0013 §4 se revisa en dos puntos**, ambos conscientes y argumentados aquí: la columna de Licencia no marca sus tres estados en la celda (§3.3), y la de Anclaje no se retira (ya lo había decidido el propietario en ADR-0013 §Afinado; este spec lo hereda).
- **ADR-0014 se cumple entero**: regla 3 en D-4, regla 4 en §7.1, y la obligación de contraste en §7.2.
- **Sin ADR nuevo.** Nada aquí fija norma que ADR-0013/0014 no cubran ya; lo que cambia son reglas de negocio de RF-006, que se anotan donde viven. Si el propietario quiere elevar a norma el criterio de «un glifo por acción, no por severidad», eso sí sería ADR — y es llamada suya, no del agente.

---

## 12. Riesgos

| Riesgo | Mitigación |
| --- | --- |
| La columna Integridad duplica hoy a la de Licencia (18/19 avisos son `missing_license`) | Aceptado y declarado (§4.2). D-6 y D-3 le dan tres motivos distintos de aviso y la blindan contra el silenciado por plantilla; la varianza llegará con la ficha de gobernanza |
| **D-6 aumenta el solape entre Anclaje e Integridad**: al comprobarse el anclaje siempre, `missing_alignment` cubre terreno de la columna de Anclaje | Aceptado y declarado (D-2). Lo que Integridad **no** hace es graduar: no distingue `parcial` de `sin anclar`, que es la severidad que el curador usa para priorizar, ni alimenta el filtro de tres estados. Si tras medir a escala en TASK-030 el solape resultara total, la salida es retirar Anclaje del juego **por defecto** —dejándola activable—, no borrarla |
| El barrido del filtro de integridad a escala de miles de REA | D-7 aplana el coste; el tope duro de `ComputedFilter` (2 000) ya protege y la UI ya sabe declarar el resultado acotado. Se mide en el arnés de §9 |
| El curador que guardó columnas en la rebanada 1 no ve las nuevas | Declarado en §3.1. Es el precio de que la selección sea una preferencia del usuario |
| `literal_in_link_property` empieza a volcarse al log de Omeka en cada guardado de los REA afectados | Consecuencia buscada: detectar es el objetivo. Son 4 valores en 19 REA, no un torrente |

---

## 13. Resumen en una frase

La tabla deja de dedicar su superficie a lo que ya está bien, estrena el comprobador de integridad que llevaba tres tareas calculándose en silencio —arreglando de paso que contase un literal roto como valor válido y que una plantilla pudiera silenciarlo—, y lo hace con **dos glifos por fila como máximo**, cada uno pidiendo una acción distinta.
