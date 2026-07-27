# Campos y funcionalidades de la UI del módulo — diseño (TASK-027)

> **Estado:** propuesta para revisión del propietario.
> **Sucede a** `2026-06-15-vista-maestra-design.md` (v1, aprobado por ADR-0005), al que **no deroga**: lo amplía.
> **Entregable de TASK-027.** Fija el **QUÉ** (campos, filtros, funcionalidades y prioridades). El **CÓMO** (maquetación, arquitectura JS, tecnología, build, tests) es **TASK-028** y queda expresamente fuera.
> **Base empírica:** catálogo real de 19 REA (`resource_class_id` 4758) e inventario funcional de la UI, medidos el 2026-07-27.

---

## 1. Principio rector

### 1.1 El desequilibrio

| Bloque | Cobertura real | Superficie en la UI hoy |
| --- | --- | --- |
| Alineamiento curricular (`lrmi:educationalLevel`, `schema:about`, `lrmi:teaches`, `lrmi:assesses`, `dcterms:relation`) | **100 %** — 384 enlaces a `DefinedTerm` | 2 columnas + 1 indicador + 5 campos del drawer + **el único panel editable** |
| Licencia (`dcterms:rights`) | **5,3 %** — 1/19, literal `ccbysa` sin versión ni URI | 1 columna + 1 campo, **solo lectura** |
| Autoría (`dcterms:creator`, `contributor`, `publisher`, `source`, `rightsHolder`, `license`; `schema:author`, `creator`, `copyrightHolder`) | **0 %** — cero usos en las 12 properties candidatas | **Ninguna** |
| Plantilla (`resource_template`) | **0 %** — 0/19 | **Ninguna** |
| URIs en cualquier valor | **0** de 384+ | **Ninguna** |
| Auditoría (`value_annotation`) | **340 anotaciones** sobre 15/19 REA; quién, cuándo, qué y —en 94 casos— el porqué pedagógico | **Se escribe y jamás se lee** |
| Integridad (`IntegrityChecker`) | Se calcula en cada `api.create.post`/`api.update.post` | **Solo va al log de Omeka** |

El módulo dedica su superficie de UI a la dimensión que su propio re-catalogador **ya resolvió al 100 %**, y deja sin superficie los tres objetivos declarados —calidad y homogeneidad de metadatos, control de autoría, control de licencia— más dos activos ya construidos y pagados (auditoría e integridad) que hoy son invisibles.

### 1.2 Regla de diseño

> **Un campo merece superficie de UI en proporción a la decisión curatorial que habilita, no a lo bien poblado que esté.**

Corolarios directos, con su dato:

1. **La columna «Alineamiento» tiene varianza cero.** Con las 4 properties al 100 %, `AlignmentStatus::statusFor()` devuelve `complete` para **los 19 REA**: la columna ocupa ancho para mostrar siempre lo mismo. Etapa y Materia, otras 2 de las 7 columnas, están igual.
2. **Licencia y autoría, al 5,3 % y 0 %, son donde está todo el trabajo pendiente.** Ahí hace falta columna, filtro, edición y acción de lote.
3. **Lo que ya se calcula y no se muestra es coste hundido**: integridad, auditoría, ficha destilada de la IA y traza de visión existen en memoria y se descartan.
4. **El hueco es el dato, y hoy el hueco se borra.** El drawer omite la fila entera cuando un campo está vacío. Consecuencia literal: el item 4359 (sin título) abre un drawer casi en blanco, y los 4 REA sin descripción son indistinguibles de un fallo de render. **Todo campo de gobernanza necesita estado vacío explícito y accionable.**

### 1.3 Defectos hallados durante el estudio

Entran en el catálogo como correcciones, no como funcionalidad nueva.

| # | Hallazgo | Anclaje |
| --- | --- | --- |
| **D1** | El filtro «Tipo de recurso» está **muerto por construcción**: usa operador `res` (enlace) sobre un dato que es literal libre | `MasterViewQuery.php:66-68` vs. catálogo real |
| **D2** | Los 4 valores **literales** en properties de enlace son **doblemente invisibles**: `checkLiveLinks` solo inspecciona valores cuyo `type` empieza por `resource`, y `checkCompleteness` los cuenta como presentes → integridad dice «ok»; y el filtro `res` nunca los devuelve | `IntegrityChecker.php:45-65`, `MasterViewQuery.php:70-78` |
| **D3** | `AlignmentStatus` da **«Completo»** a un REA cuya materia es un literal roto | `AlignmentStatus.php:75-91` |
| **D4** | El filtro «parcial» criba **en memoria sobre la página ya paginada** → total aproximado y nº de filas variable | `MasterViewQuery.php:103-109` |
| **D5** | Ninguna cabecera es ordenable, aunque `MasterViewQuery` ya acepta `sort_by`/`sort_order` | `MasterViewQuery.php:40-45` vs. `index.phtml:88-90` |
| **D6** | Las columnas están **hardcodeadas en el `.phtml`**, esquivando la configuración nativa de columnas del browse de Omeka 4.x | `index.phtml:20-27` |
| **D7** | El preview de re-catalogación muestra **solo recuentos** (`+N/−M`) aunque el backend devuelve `current`/`next`/`added`/`removed` con títulos | `RecatalogService::preview()` vs. `oer-master-view.js` |

### 1.4 Lo que este diseño NO cambia

El re-catalogador curricular funciona y está auditado. Este diseño **no toca** su panel ni su flujo (propone → previsualiza → confirma), ni el mapeo RDF de ADR-0004, ni la acotación por el grafo de ADR-0009. Reequilibra lo que hay **alrededor**.

---

## 2. Vistas

Tres superficies con propósitos distintos. El criterio para separarlas es la **pregunta que responde cada una**.

| Vista | Pregunta que responde | Estado |
| --- | --- | --- |
| **Inventario** (vista maestra) | «¿Qué hay en el catálogo y cuál es este REA concreto?» | Existe; se reequilibra |
| **Cola de calidad** | «¿Qué me falta por arreglar y por dónde empiezo?» | **Nueva** |
| **Drawer de detalle** | «¿Qué sé de este REA y qué le corrijo?» | Existe; se amplía |

### 2.1 Por qué hace falta una cola de calidad

Una tabla de inventario ordena por *lo que hay*; el trabajo curatorial se organiza por *lo que falta*. Con los datos actuales, la cola tendría estos grupos, cada uno con recuento y acción de lote:

| Grupo | REA afectados | Detección |
| --- | --- | --- |
| Sin licencia | **18** | `dcterms:rights` ausente |
| Sin autoría | **19** | `dcterms:creator` ausente |
| Tipo fuera del vocabulario | **16** | literal que no casa con el CustomVocab |
| Sin descripción | **4** — 4359, 4362, 40435, 40437 | `dcterms:description` ausente |
| Sin trazabilidad | **4** — 40427, 37129, 37132, 40442 | sin ninguna `@annotation` |
| Sin tipo de recurso | **3** — 4359, 4362, 3181 | `lrmi:learningResourceType` ausente |
| Sin título | **1** — 4359 | `dcterms:title` ausente |
| Sin media | **1** — 40442 | `o:media` vacío |
| Valores literales que deberían ser enlace | 4 valores | `schema:about` ×3, `educationalLevel` ×1 |
| Valores con `type` genérico | 44 valores | `type='resource'` en vez de `resource:item` |
| Enlaces muertos | según `IntegrityChecker` | `dead_link` |

Sin esta vista, el curador reconstruye cada grupo a base de filtros, uno por uno, cada vez.

### 2.2 Forma recomendada: presets sobre la misma tabla, no una tabla nueva

El propietario decidió que haya cola de calidad. **Cómo materializarla** admite dos formas, y la diferencia de coste es grande:

| Forma | A favor | En contra |
| --- | --- | --- |
| Tabla propia en ruta aparte | Columnas específicas por tipo de defecto | Duplica tabla, filtros, paginación, selección, drawer y ACL. Con 19 REA es infraestructura sin masa crítica |
| **Franja de contadores + presets sobre la tabla existente** (recomendada) | Cada grupo es un enlace a un preset de filtros; el «cuánto falta» son contadores. Una superficie, un drawer, un juego de acciones de lote | Los contadores exigen varias consultas agregadas, y dos de ellos no son expresables como query |

Franja propuesta, cada elemento enlazando a su preset:

`Sin licencia (18) · Sin autoría (19) · Sin descripción (4) · Sin título (1) · Sin plantilla (19) · Fuera de colección (11) · Sin medios (1) · Sin auditoría (4) · Enlaces rotos (—)`

**Sostén técnico y sus límites:**

| Contador | ¿Expresable como query de la API? | Coste |
| --- | --- | --- |
| Sin licencia / descripción / título / tipo / autoría | Sí (`property[type=nex]`) | 1 consulta por contador |
| Sin plantilla / fuera de colección / propietario | Sí (`resource_template_id`, `item_set_id`) — **verificar en contenedor** que admiten «ninguno» | 1 consulta |
| Sin medios | **Verificar** si `has_media` existe en el `ItemAdapter` de 4.2 | 1 consulta o barrido |
| Integridad · con/sin auditoría · alineamiento parcial · literal en property de enlace | **No**: se computan por item o por valor | Barrido con tope duro |

Regla: los contadores computados se calculan con **tope duro** (p. ej. 2 000 items) y se marcan como aproximados si se alcanza.

**Reutilización obligada:** esta agregación es **la misma** que RF-007 pide para los gráficos de TASK-006. Debe construirse como un servicio compartido (`CatalogueStats` o similar) y consumirse dos veces, no duplicarse.

---

## 3. Vista maestra — columnas

`NUEVA` · `MANTIENE` · `DEGRADA` (pasa a opcional, no visible por defecto).

| Columna | Procedencia | Ordenable | Defecto | Estado | Justificación curatorial |
| --- | --- | --- | --- | --- | --- |
| Título | `o:title` / `dcterms:title` + `o:id` | Sí | Sí | MANTIENE | Debe renderizar **«(sin título)»** marcado: hoy el item 4359 sale como `null` y es invisible en cualquier listado. El `o:id` como línea secundaria es la moneda de cambio con el contenedor |
| Integridad | calculado (`IntegrityChecker`) | Por severidad | Sí | **NUEVA** | Semáforo ok/aviso/error + nº de incidencias, detalle en el drawer. Hoy se calcula en cada guardado y **solo va al log**; RF-002 lo pide. **Absorbe la columna Alineamiento**: `missing_alignment` ya es uno de sus avisos |
| Licencia | `dcterms:rights` | Sí | Sí | MANTIENE | Tres estados explícitos: *valor del vocabulario* / *valor fuera del vocabulario* (⚠ `ccbysa`) / **«Sin licencia»**. Hoy la celda vacía no dice nada; 18/19 sin valor |
| Autoría | `dcterms:creator` (+ `dcterms:publisher`) | Sí | Sí | **NUEVA** | 0 % en las 12 properties candidatas. `o:owner` no sirve: los 19 son del mismo usuario técnico |
| Curricular | `lrmi:educationalLevel` + `schema:about` | No | Sí | **NUEVA (fusión)** | **Sustituye dos columnas por una** y arregla dos defectos: deduplica por título (los 7 casos «Matemáticas ×4») mostrando `Materia (+N cursos)`, y marca ⚠ el valor **literal** que hoy se pinta como enlace legítimo (D2) |
| Tipo de recurso | `lrmi:learningResourceType` | Sí | Sí | **NUEVA** | 9 literales libres para 16 REA con solapes evidentes; verlo en tabla es lo que hace visible la heterogeneidad |
| Visibilidad | `o:is_public` | Sí | Sí | MANTIENE | Estado de la única acción de escritura implementada |
| Modificado | `o:modified` | Sí | Sí | MANTIENE | Orden natural de una cola de trabajo |
| ~~Alineamiento~~ | calculado (`AlignmentStatus`) | — | — | **RETIRA** | **Varianza cero**: `complete` en los 19. Además da «Completo» a un REA con la materia literal rota (D3). **El filtro se mantiene**; lo que se retira es la columna |
| ~~Etapa~~ / ~~Materia~~ | — | — | — | **FUSIONA** | → columna «Curricular» |
| Trazabilidad | calculado (¿tiene `@annotation`?) | No | No | **NUEVA** | Distingue lo verificado de lo no verificado: 4 REA y 44 valores sin traza. **Coste N+1 por fila** → nunca por defecto |
| Proyecto | `schema:isPartOf` | Sí | No | **NUEVA** | Pedida por el objetivo completo de RF-002; 3/19. Debe marcar ⚠ si hay un `dcterms:isPartOf` literal compitiendo |
| Plantilla | `o:resource_template` | Verificar | No | **NUEVA** | 0/19; «sin plantilla» es lo que hace que `IntegrityChecker` caiga a la regla mínima |
| Medias | `o:media` (nº y tipo) | Sí | No | **NUEVA** | Detecta el REA sin media y el tipo de material |
| Colección | `o:item_set` | No | No | **NUEVA** | 11/19 fuera de cualquier colección |
| Propietario / Creado | `o:owner`, `o:created` | Sí | No | **NUEVA** | Hoy inservible (un solo usuario); ganará valor con más cargadores |

**Tope recomendado: selección + 8 columnas visibles por defecto** (las ocho primeras de la tabla). El resto, activables. Justificación: la tabla ya convive con el panel de filtros y la acción de lote; pasar de ahí obliga a scroll horizontal en el admin de Omeka y degrada el triaje.

**Ordenación:** hoy **no existe** (D5) — las cabeceras no emiten enlaces y `AlignmentStatus::getSortBy()` devuelve `null`, aunque `MasterViewQuery` ya acepta `sort_by`/`sort_order`. Las columnas marcadas ordenables deben emitirlos.

**Recomendación transversal sobre la selección de columnas.** En vez de fijar para siempre qué ocho columnas caben: **registrar todas como `column_types` y dejar la selección al mecanismo nativo de configuración de columnas del browse de Omeka 4.x**, enviando el conjunto de arriba como valor por defecto. Hoy el `.phtml` las hardcodea (D6), y por eso «¿qué columnas?» es una decisión irreversible en vez de una preferencia del usuario. *Verificar en el contenedor cómo persiste Omeka 4.2 esa configuración antes de comprometerse.* Prioridad: recomendable — es un habilitador de TASK-028.

---

## 4. Vista maestra — filtros y búsqueda

### 4.1 Arreglar lo que ya hay

| Filtro | Property | Operador | Control hoy | Control propuesto |
| --- | --- | --- | --- | --- |
| Título | `dcterms:title` | `in` | texto | igual |
| Visibilidad | `is_public` | — | select | igual |
| Alineamiento | calculado | ver §4.3 | select | igual |
| Etapa | `lrmi:educationalLevel` | `res` | **ID numérico en crudo** | **autocompletado** |
| Materia | `schema:about` | `res` | **ID numérico en crudo** | **autocompletado** |
| Proyecto | `schema:isPartOf` | `res` | **ID numérico en crudo** | **autocompletado** |
| Eje temático | `dcterms:relation` | `res` | **ID numérico en crudo** | **autocompletado** |
| Tipo de recurso | `lrmi:learningResourceType` | **`res` → `eq`** | **ID numérico en crudo** | **desplegable del CustomVocab** |
| Licencia | `dcterms:rights` | `eq` | texto libre exacto | **desplegable del CustomVocab** |

Dos correcciones de fondo:

1. **Los IDs numéricos en crudo son inservibles para un curador** (hay que conocer de memoria el id del item-término). El autocompletado **ya existe, funciona y está acotado por el grafo**: `search-terms` + `CurriculumSearch`, hoy conectado únicamente al drawer. Reutilizarlo satisface NFR-004 (nunca cargar el árbol completo) sin código nuevo de búsqueda.
2. **Corrección del defecto D1:** el filtro de tipo de recurso usa operador `res` sobre datos que en realidad son **literales**. **Hoy no puede casar nunca.** Con el CustomVocab pasa a `eq`.

Dos cambios pequeños en el contrato de `search-terms`, ambos necesarios para que el filtro sea usable:

- **Usar `description` y `block`**, que el endpoint ya devuelve y el JS descarta, como segunda línea del resultado: desambigua términos homónimos.
- **Añadir el linaje al resultado** (curso/padre). `mapResults()` no lo devuelve, pero `searchLeaves()` ya sabe calcularlo (`courseId`/`courseTitle`). Sin esto, los 7 casos de «Matemáticas ×4» son indistinguibles en el desplegable, exactamente igual que en la tabla. **Imprescindible.**

### 4.2 Filtros de gobernanza a añadir

| Filtro | Procedencia | Operador | Prioridad |
| --- | --- | --- | --- |
| Sin licencia | `dcterms:rights` | `nex` | **Imprescindible** |
| Sin autoría | `dcterms:creator` | `nex` | **Imprescindible** |
| Estado de integridad | calculado | ok/warning/error | **Imprescindible** |
| Sin descripción | `dcterms:description` | `nex` | Recomendable |
| Sin título | `dcterms:title` | `nex` | Recomendable |
| Sin tipo de recurso | `lrmi:learningResourceType` | `nex` | Recomendable |
| Con / sin auditoría | `@annotation` | calculado | Recomendable |
| Propietario | `o:owner` | — | Opcional |
| Colección (`item_set`) | `o:item_set` | — | Opcional — 11/19 fuera de toda colección |
| Rango de fechas | `o:created` / `o:modified` | — | Opcional |
| Tiene media / tipo de media | `o:media` | — | Opcional |

### 4.3 Los filtros computados y el defecto D4

Hoy «parcial» se criba en memoria **sobre la página ya paginada** (el adaptador de Omeka no soporta agrupar AND/OR con paréntesis): el total es aproximado y el nº de filas varía. Los filtros nuevos de integridad, auditoría y literal-en-property-de-enlace tienen **exactamente la misma naturaleza** y heredarían el defecto multiplicado.

**Patrón obligatorio si se añade cualquier filtro computado:**

1. Resolver el filtro base por query de la API.
2. Obtener los **ids** del conjunto completo con **tope duro** (p. ej. 2 000).
3. Evaluar el predicado computado sobre esa lista.
4. Paginar en memoria sobre la lista ya filtrada.

Da total exacto y filas estables. Con 19 REA es gratis; el tope protege el crecimiento y se comunica al usuario («resultado acotado a los primeros N»). **Añadir filtros computados sin este patrón es propagar D4.**

---

## 5. Drawer — inventario de campos

Leyenda: `L` solo lectura · `E` editable · **negrita** = nuevo respecto a los 9 campos actuales.

### 5.1 Identidad

| Campo | Procedencia | L/E | Control | Rol | Prioridad |
| --- | --- | --- | --- | --- | --- |
| **Miniatura** | `o:thumbnail_display_urls` | L | imagen | — | Recomendable |
| Título | `o:title` | L (aquí) | texto | — | Imprescindible |
| **Identificador** | `o:id` | L | texto | — | Recomendable |
| **Enlace al item nativo** | `@id` | L | enlace | — | **Imprescindible** — hoy no hay salida al editor de Omeka desde el drawer |
| Visibilidad | `o:is_public` | **E** | toggle | editor+ | **Imprescindible** — hoy se muestra y **no se puede cambiar desde el drawer** |
| **Plantilla** | `o:resource_template` | L | texto | — | Opcional — hoy 0/19 |
| **Colección** | `o:item_set` | L | lista | — | Opcional |

### 5.2 Ficha descriptiva — **sección editable nueva**

| Campo | Property | Tipo | L/E | Control | Rol | Prioridad |
| --- | --- | --- | --- | --- | --- | --- |
| Título | `dcterms:title` | literal | **E** | texto | editor+ | **Imprescindible** |
| Descripción | `dcterms:description` | literal | **E** | textarea | editor+ | **Imprescindible** |
| Tipo de recurso | `lrmi:learningResourceType` | literal | **E** | **desplegable CustomVocab** | editor+ | **Imprescindible** |

### 5.3 Alineamiento curricular

Se mantiene el panel actual sin cambios de campo (5 dimensiones editables + Etapa como ayuda no persistente). Se añade:

| Campo | Procedencia | L/E | Prioridad |
| --- | --- | --- | --- |
| **Estado de alineamiento** | calculado | L | Recomendable — al retirarse la columna (§3), este pasa a ser el único sitio donde se ve |
| **Diff detallado del preview** | `RecatalogService::preview()` | L | **Imprescindible (corrige D7)** — el backend ya devuelve `added`/`removed`/`invalid` con títulos y el JS solo pinta `+N/−M`. **Confirmar una escritura RDF viendo solo un número es el punto débil del flujo preview→confirmar** |
| **Marca de valor literal** | computado | L | Recomendable — ⚠ en el chip cuyo valor es literal en vez de enlace (D2) |

### 5.4 Clasificación

| Campo | Property | L/E | Rol | Prioridad |
| --- | --- | --- | --- | --- |
| Eje temático | `dcterms:relation` | E (panel actual) | editor+ | Imprescindible |
| **Proyecto** | `schema:isPartOf` | **E** | **`global_admin` / `site_admin`** (NFR-003) | Recomendable — decidido en ADR-0004 como acción de gestor, nunca implementado |

### 5.5 Gobernanza legal y autoría — **sección nueva**

Modelo aprobado por el propietario el 2026-07-27 (ligero controlado).

| Campo | Property | Tipo | Cardinalidad | Control | Prioridad |
| --- | --- | --- | --- | --- | --- |
| Licencia | `dcterms:rights` | literal | 1 | **desplegable CustomVocab** | **Imprescindible** |
| Autoría | `dcterms:creator` | literal | **múltiple** | texto multivalor | **Imprescindible** |
| Organismo editor | `dcterms:publisher` | literal | 1 | **desplegable CustomVocab** (centro / CEP / Consejería) | **Imprescindible** |
| Titular de derechos | `dcterms:rightsHolder` | literal | 1 | texto con **valor por defecto configurable** | Recomendable |
| Fuente original | `dcterms:source` | **URI** | 0-1 | URL | Recomendable — para adaptaciones; hoy hay **0 URIs** en todo el corpus |

### 5.6 Medios

| Campo | Procedencia | L/E | Prioridad |
| --- | --- | --- | --- |
| **Lista de medias** | `o:media` | L | Recomendable — nombre, `media_type`, tamaño |
| **Aviso de REA sin media** | calculado | L | Recomendable — hoy afecta a 40442; un REA sin medio no es un recurso |
| **Aptitud para la IA** | `content.sources` / `content.skipped` | L | Recomendable — por media: leída o saltada, con el motivo (`pdf_iconv_unsupported`, `pdf_too_large`…). Hoy solo vive en un `<details>` de debug, y **es lo que explica por qué un propose sale pobre** |

### 5.7 Trazabilidad — **sección nueva**

Ver §7.

### 5.8 Diagnóstico — **sección nueva**

| Campo | Procedencia | Prioridad |
| --- | --- | --- |
| **Issues de integridad** | `IntegrityChecker` (`dead_link`, `missing_required`, `missing_alignment`, `missing_license`) | **Imprescindible** — se calcula hoy y solo va al log |
| **Ficha destilada de la IA** | `debug.ficha` (ADR-0011) | Recomendable — viaja en el payload y **no se muestra en ningún sitio** |
| **Traza de visión** | `debug.vision` | Recomendable — contiene el motivo por el que la visión no aportó (`disabled`, `provider_no_vision`, `no_candidates`, `empty_response`) |

---

## 6. Funcionalidades

### 6.1 Acciones de lote (vista maestra y cola de calidad)

| Acción | Estado | Prioridad |
| --- | --- | --- |
| Cambiar visibilidad | Existe | — |
| **Asignar licencia** | Nueva | **Imprescindible** — 18 REA la necesitan |
| **Asignar autoría / organismo editor** | Nueva | **Imprescindible** — 19 REA |
| **Normalizar tipo de recurso al CustomVocab** | Nueva | **Imprescindible** — 16 REA |
| **Asignar proyecto** | Decidida en ADR-0004, nunca implementada | Recomendable |
| **Catalogación IA en lote** | RF-011 / TASK-011, pendiente | Recomendable — la infraestructura de Job ya existe (TASK-020) |

### 6.2 Acciones individuales

| Acción | Estado | Prioridad |
| --- | --- | --- |
| Re-catalogar (preview + confirmar) | Existe | — |
| Proponer con IA | Existe | — |
| **Editar ficha de gestión** | Nueva | **Imprescindible** |
| **Promover literal → enlace** | Nueva | Recomendable — 4 valores lo necesitan |

### 6.3 Higiene de datos que los datos piden

Detectables y corregibles, hoy invisibles:

| Problema | Alcance | Nota |
| --- | --- | --- |
| Valores con `type='resource'` genérico | **44 valores** | Son **exactamente** los que carecen de anotación de auditoría: dos generaciones de escritura conviviendo |
| Literales donde debería haber enlace | 3 en `schema:about`, 1 en `educationalLevel` | Uno discrepa además en mayúsculas del término oficial → invisible para cualquier filtro por materia |
| `dcterms:isPartOf` literal | 1 valor, «ES parte de FEDRER» | Compite con `schema:isPartOf`, que sí enlaza a items de clase `Project` |
| Títulos repetidos en `schema:about` | 7 REA | `DefinedTerm` distintos con idéntico texto: es correcto ontológicamente, pero la UI debe agrupar |

### 6.4 Fuera de alcance

`ai-evaluate` existe como endpoint sin ninguna UI (solo accesible tecleando la URL), es **síncrono** y puede encadenar decenas de llamadas al LLM. **No se le da superficie en este diseño**: darle un botón invitaría a lanzarlo sin dimensionar su coste. Si se quiere exponer, exige antes convertirlo en Job, como se hizo con el propose en TASK-020.

---

## 7. Cómo mostrar la auditoría

El módulo escribe **340 anotaciones** sobre el 88,5 % de los valores de alineamiento y **nunca las lee de vuelta**. Es el activo más infrautilizado del sistema.

### 7.1 Contenido disponible por valor

| Dato | Property de la anotación | Cobertura |
| --- | --- | --- |
| Quién | `dcterms:contributor` | 340 — `fmatdia` (322), `verificacion-task023` (18) |
| Cuándo | `dcterms:modified` | 340 — ISO 8601 |
| Qué | `dcterms:provenance` | 340 — `OERManager re-catalogación de <property>` |
| Por qué | `dcterms:description` | **94** — justificación pedagógica en lenguaje natural |

### 7.2 La clave de agrupación ya existe en el dato

`RecatalogService::apply()` calcula el timestamp **una sola vez por confirmación** y lo escribe idéntico en el `dcterms:modified` de todas las anotaciones de esa operación. Por tanto:

> **El par `(dcterms:contributor, dcterms:modified)` identifica un evento de curación completo.**

Las 340 anotaciones colapsan así en un puñado de eventos legibles por REA, y cada evento reconstruye exactamente un `apply`. Sin esta observación, el historial sería una lista plana de 340 líneas sin estructura.

### 7.3 Estructura propuesta

```
Historial de curación                              [4 eventos · 23 valores]
──────────────────────────────────────────────────────────────────────────
▸ 22 jul 2026, 10:31 · fmatdia · lrmi:teaches, lrmi:assesses    12 valores
▾ 18 jun 2026, 09:04 · verificacion-task023 · schema:about       3 valores
     Materia: Matemáticas (1º ESO)              [ver porqué]
     Materia: Matemáticas (2º ESO)
     Materia: Matemáticas (3º ESO)
──────────────────────────────────────────────────────────────────────────
⚠ 8 valores de este REA no tienen traza de auditoría (previos al módulo)
```

| Elemento | Origen | Prioridad |
| --- | --- | --- |
| Cabecera de evento: fecha · contribuyente · properties tocadas | `dcterms:modified` + `dcterms:contributor` + `dcterms:provenance` | Imprescindible |
| Valores del evento, con el título del término enlazado | valor anotado | Imprescindible |
| Contador «N valores sin traza» | computado | Recomendable |
| Colapsado por defecto, último evento abierto | UI | Recomendable |

Debe **marcar explícitamente lo no verificado**: los 4 REA sin ninguna anotación y los 44 valores sin traza dentro de REA que sí la tienen.

### 7.4 Límites que la UI debe declarar

| Límite | Consecuencia |
| --- | --- |
| La anotación vive **en el valor**: si el valor se borra, su traza desaparece | El historial es «estado actual anotado», **no un log**. **No muestra eliminaciones.** La reversibilidad real es TASK-007 |
| La visibilidad está fuera de la auditoría (ADR-0002) | El historial no debe insinuar que cubre todos los cambios |
| `verificacion-task023` (18 anotaciones) es un contribuyente de pruebas | Se muestra tal cual, por honestidad; opcionalmente con distintivo |

**Dependencia técnica nº 1 de esta sección:** *verificar en el contenedor si `GET /api/items/{id}` incluye `@annotation` en el JSON-LD de cada valor.* Si no lo incluye, hace falta una acción del controlador que lea las anotaciones en servidor.

### 7.5 Decisión abierta — requiere confirmación del propietario

Existe decisión vigente de **no** mostrar la justificación de la IA en el **panel de propuesta** (`2026-07-07-justificacion-saberes-criterios-design.md`: «viaja oculta con la propuesta y se materializa al confirmar»; mostrarla figura en «Fuera de alcance (YAGNI)»).

Mostrarla en el **historial de lo ya confirmado** es un caso distinto:

| | Panel de propuesta (decisión vigente) | Historial de lo confirmado (a decidir) |
| --- | --- | --- |
| Momento | Antes de escribir | Después de escribir |
| Naturaleza del dato | Sugerencia de la IA, sin validar | Literal RDF ya persistido, visible en el JSON-LD y en cualquier export |
| Riesgo de mostrarla | **Anclaje**: la explicación de la IA sesga la revisión del curador | Ninguno de anclaje; a lo sumo ruido visual |
| Valor de mostrarla | Discutible | Alto: 94 de 340 anotaciones la tienen. Sin ella el historial dice *qué* se cambió, nunca *por qué* |

Opciones, **decide el propietario**: **(a)** mostrarla siempre · **(b)** tras un «ver porqué» colapsado — *recomendada, mantiene el principio de no anclar y no tira el dato* · **(c)** no mostrarla en ninguna superficie, coherencia total con TASK-023.

**Este documento lo plantea, no lo resuelve.**

---

## 8. Consecuencias de gobierno

Qué hay que decidir o registrar **antes** de que TASK-028 pueda implementar.

| Elemento | Tipo | Motivo |
| --- | --- | --- |
| **ADR-0013** — alcance UI v2 y catálogo de campos | **Obligatorio** | ADR-0005 §25: «Cambios futuros al alcance v1 requieren un ADR nuevo que reemplace o complemente este» |
| **RF-015** — datos de gestión y autoría del REA | **Obligatorio** | Hueco real: el contexto original listaba autoría/copyright como campo de gestión y **nunca se convirtió en RF**. No hay property, tipo ni cardinalidad fijados |
| Setting: CustomVocab de licencias | Config | Decidido en ADR-0004 («la config del módulo gana dos parámetros»), **nunca implementado**: no está en `ConfigForm` |
| Setting: CustomVocab de tipos de recurso | Config | Debe apuntar al **`custom_vocab` id 1 ya existente** |
| Setting: titular de derechos por defecto | Config | Deriva del modelo de autoría aprobado |
| **PEND nuevo** — valores exactos del vocabulario de licencias | Decisión | ¿Etiquetas legibles con versión (`CC BY-SA 4.0`)? ¿URI canónica en `dcterms:license`? El único valor actual, `ccbysa`, no permite distinguir versión ni jurisdicción |
| **PEND nuevo** — ¿plantilla REA (`resource_template`)? | Decisión | Ver el aviso de §8.1: es la decisión con más riesgo oculto de todo el documento |
| **PEND nuevo** — justificación IA en el historial | Decisión | §7.3 |

### 8.1 ⚠ Trampa de la plantilla REA

`IntegrityChecker::checkCompleteness()` **cambia de reglas según haya plantilla o no**: con plantilla valida los campos que ella marque obligatorios; **sin plantilla** aplica el mínimo (alineamiento vivo + licencia presente). Las dos ramas son excluyentes.

> **Consecuencia:** asignar a los 19 REA una plantilla que no marque `dcterms:rights` y el alineamiento como obligatorios **silenciaría de golpe todos los avisos de integridad actuales**, incluidos los 18 «sin licencia». El catálogo pasaría a verse «ok» sin haber arreglado nada.

**Regla:** no aplicar plantilla a ningún REA hasta haber definido y aprobado sus campos obligatorios. Hoy 0/19 la usan y ninguna de las 2 plantillas existentes cubre `lrmi:LearningResource` (ambas son puramente `dcterms`); el módulo `AdvancedResourceTemplate` está activo y permitiría crearla.

### 8.2 Riesgo de la higiene masiva

Las acciones de §6.3 (promover literal→enlace, normalizar `type` genérico, normalizar licencia) **reescriben RDF ya escrito** sobre el catálogo entero. Es el perfil de riesgo del re-catalogador, que la skill `recatalogador` marca como componente de ALTO RIESGO, y además **ADR-0002 no contempla cómo se anota una *corrección*** frente a una re-catalogación.

Invariantes obligatorias para cualquiera de estas acciones: previsualización con **diff detallado** (no recuentos), confirmación con el nº exacto de REA y valores afectados, anotación de auditoría propia, tope de tamaño de lote y CSRF. **Ninguna se aborda sin ADR previo.**

### 8.3 Roces a vigilar

| Requisito | Roce |
| --- | --- |
| **NFR-004** | Los filtros curriculares nuevos deben ir por autocompletado incremental; nunca precargar el árbol |
| **NFR-003** | Proyecto (`schema:isPartOf`) solo `global_admin`/`site_admin`. Pero `Module::onBootstrap` concede a `editor` **el controlador entero sin lista de privilegios**: una acción nueva de proyecto sería alcanzable por `editor` **por herencia**, aunque la UI no pinte el botón. Exige privilegio propio (p. ej. `assign-project`) o `userIsAllowed()` explícito por acción, además del gating visual |
| **NFR-006** | Accesibilidad = nivel del admin nativo. Los campos editables nuevos heredan la exigencia |
| **ADR-0002** | La visibilidad queda **fuera** de la auditoría RDF: editarla no genera anotación |

### 8.4 Dependencias con otras tareas

| Tarea | Relación |
| --- | --- |
| **TASK-028** | Consume este documento; bloqueada por él |
| **TASK-007** (auditoría y reversibilidad) | §7 **es su cara de lectura**. Conviene hacerlas juntas: TASK-007 aporta los ids added/removed persistidos y esta UI los muestra. **Sin TASK-007 el historial no puede mostrar eliminaciones** |
| **TASK-006** (estadísticas) | Los contadores de la cola (§2.2) y los agregados del dashboard son **la misma agregación**: construir un servicio compartido y consumirlo dos veces, o se duplica |
| **TASK-011** (IA en lote) | Aporta la infraestructura de Job que reutilizarían los lotes de saneamiento |
| **TASK-013** (`dcterms:type` como `skos:Concept`) | Cambiaría los filtros de `eq` a `res`. Los filtros de la tabla deben consumir `search-terms` **por contrato**, sin replicar la construcción de la query |
| **TASK-024(b)** (PDF/iconv en Alpine) | Mientras siga abierto, el «motivo de omisión de visión» del drawer es la única forma de que el curador entienda por qué la IA no aporta nada en un PDF |

---

## 9. Prioridades

Para que TASK-028 pueda recortar por abajo sin renegociar el diseño.

### Imprescindible — sin esto el módulo no cumple sus objetivos declarados

- Columnas: integridad, licencia con estado vacío explícito, autoría, tipo de recurso, título con marca de ausente y `o:id`.
- Filtros: sin licencia, sin autoría, sin descripción, estado de integridad; **autocompletado con linaje** en lugar de IDs crudos; **corrección de D1** (operador del filtro de tipo).
- Correcciones: **cabeceras ordenables** (D5) y **diff detallado en el preview** (D7).
- Drawer: sección de gobernanza legal y autoría (editable), ficha descriptiva (editable), issues de integridad, enlace al item nativo, visibilidad editable, historial de curación.
- Lote: asignar licencia, asignar autoría, normalizar tipo de recurso.
- Cola de calidad con sus grupos y recuentos.
- Setting del CustomVocab de licencias (hoy decidido y no implementado).

### Recomendable

- Columnas de trazabilidad y proyecto; ordenación por columnas.
- Filtros: sin descripción, sin título, sin tipo, con/sin auditoría.
- Drawer: historial de auditoría, medios, estado de alineamiento, ficha destilada y traza de visión, miniatura, proyecto editable.
- Lote: asignar proyecto, catalogación IA en lote.
- Promover literal → enlace.

### Opcional

- Columnas de propietario y medias; filtros de colección, fechas, propietario y tipo de media.
- Plantilla y colección en el drawer.
- Corrección de los 44 valores con `type` genérico y del `dcterms:isPartOf` literal.

---

## 10. Resumen en una frase

El re-catalogador dejó el alineamiento curricular impecable y la ficha de gobernanza vacía; **la UI debe dedicar su superficie a lo segundo**, exponer los dos activos que ya se calculan y nadie ve (integridad y auditoría), y sustituir los IDs numéricos en crudo por el autocompletado que el módulo ya tiene construido.
