# ADR-0013: Vista maestra v2 — catálogo de campos y reequilibrio hacia la gobernanza

## Estado

**Aceptado (2026-07-28)** por el propietario. Propuesto el 2026-07-27 con el estudio de TASK-027 y aceptado al día siguiente, junto con la sección «Sembrar, no poseer» y el troceado de TASK-028 en rebanadas, que se decidieron ese mismo 2026-07-28. Con este ADR se aprueba también el catálogo de campos de `docs/superpowers/specs/2026-07-27-campos-ui-design.md` (§Decisión.2). Sello formal registrado el 2026-07-30, al detectarse que el registro seguía en `Propuesto` mientras la rebanada 1 que lo implementa ya estaba fusionada en `main`.

Requerido por **ADR-0005 §25**: «Cambios futuros al alcance v1 (p. ej. panel configurable) requieren un ADR nuevo que reemplace o complemente este». Este ADR **complementa** ADR-0005, no lo deroga: el alcance v1 (columnas, filtros, drawer de lectura y curación de visibilidad) sigue siendo válido y es la base sobre la que se amplía.

## Contexto

El propietario pidió refactorizar la UI del módulo (TASK-028) y decidió estudiar antes qué campos debe llevar (TASK-027). Ese estudio, con el catálogo real de 19 REA medido el 2026-07-27, encontró un desequilibrio estructural:

| Bloque | Cobertura real | Superficie en la UI |
| --- | --- | --- |
| Alineamiento curricular (5 properties) | **100 %** — 384 enlaces | 2 columnas + indicador + 5 campos + el único panel editable |
| Licencia (`dcterms:rights`) | **5,3 %** — 1/19, literal sin normalizar | 1 columna y 1 campo, solo lectura |
| Autoría (12 properties candidatas) | **0 %** | Ninguna |
| Plantilla / URIs | **0 %** / **0** de 384 | Ninguna |
| Auditoría (`value_annotation`) | **340 anotaciones**, 15/19 REA | Se escribe y **jamás se lee** |
| Integridad (`IntegrityChecker`) | Se calcula en cada guardado | **Solo va al log** |

La UI dedica su superficie a la dimensión que el re-catalogador **ya resolvió al 100 %** —hasta el punto de que la columna «Alineamiento» devuelve `complete` para los 19 REA, es decir, tiene varianza cero— y deja sin superficie los tres objetivos declarados del módulo: calidad y homogeneidad de metadatos, control de autoría y control de licencia.

Además se detectaron defectos con impacto de gobierno: el filtro de tipo de recurso está muerto por construcción (operador de enlace sobre dato literal), los valores literales en properties de enlace son invisibles a la vez para el filtro y para el comprobador de integridad, y el preview de re-catalogación pide confirmar una escritura RDF mostrando solo un recuento.

## Decisión

1. **Regla rectora**: *un campo merece superficie de UI en proporción a la decisión curatorial que habilita, no a lo bien poblado que esté.* La UI se reequilibra hacia la gobernanza; el re-catalogador y su flujo (propone → previsualiza → confirma) **no se tocan**.

2. **Catálogo de campos**: el detalle vive en `docs/superpowers/specs/2026-07-27-campos-ui-design.md`, que se aprueba con este ADR. Fija columnas, filtros, secciones y campos del drawer, funcionalidades y prioridades.

3. **La UI del módulo edita la ficha de gestión completa**: título, descripción, tipo de recurso, licencia y autoría, además del alineamiento y la visibilidad ya existentes. Deja de delegar en el editor nativo de Omeka los campos donde están los huecos.

4. **Tres superficies**: inventario (vista maestra), cola de calidad y drawer de detalle. La cola se materializa como **presets de filtro y contadores sobre la misma tabla**, no como una tabla nueva: una segunda tabla duplicaría filtros, paginación, selección, drawer y ACL sin masa crítica que lo justifique.

5. **Vocabularios controlados**: `dcterms:rights` y `lrmi:learningResourceType` pasan a `CustomVocab` (mecanismo ya decidido en ADR-0004 §5 para la licencia y nunca implementado). Para el tipo de recurso **el vocabulario ya existe**: `custom_vocab` id 1, «Tipos de recursos», 29 términos en 7 familias, que ningún REA usa.

6. **La auditoría se lee**: las 340 anotaciones se exponen como historial en el drawer, agrupadas por evento de curación. La clave de agrupación existe ya en el dato: `RecatalogService::apply()` escribe el mismo `dcterms:modified` en todas las anotaciones de una confirmación, así que el par `(dcterms:contributor, dcterms:modified)` identifica un `apply` completo.

7. **La integridad se muestra**: `IntegrityChecker` gana una columna-semáforo y una sección de incidencias en el drawer, en vez de terminar solo en el log.

8. **Los filtros curriculares dejan de pedir IDs numéricos** y reutilizan el autocompletado `search-terms` ya existente, que respeta NFR-004 (búsqueda incremental, nunca el árbol completo).

9. **Sembrar, no poseer** — propiedad de los vocabularios y de la plantilla. Ver §«Sembrar, no poseer» más abajo.

## Sembrar, no poseer (resuelve PEND-012 y el mecanismo de PEND-011)

ADR-0006 fijó que el módulo **consume** vocabularios identificados por un ID en la configuración, «sin descubrimiento automático», y CLAUDE.md lo generaliza: *el currículo ya existe, el módulo lo consume, no lo posee ni lo modifica*. Ese principio resuelve el currículo y los ejes, pero **no cubre un caso nuevo**: el vocabulario de licencias y la plantilla REA **no existen y no son de nadie**. Consumir algo que no existe es no hacer nada.

**Regla:** el módulo **siembra lo que falta y consume lo que ya está**; en ambos casos, lo que use se identifica por **ID en un setting**, nunca por etiqueta (las etiquetas cambian con i18n y con la edición del admin).

| Artefacto | ¿Existe? | Decisión |
| --- | --- | --- |
| Currículo y ejes (`DefinedTerm`/`DefinedTermSet`) | Sí | Consumir por ID — ya decidido en ADR-0006 |
| CustomVocab de **tipos de recurso** | **Sí** — `custom_vocab` id 1, «Tipos de recursos», 29 términos en 7 familias | **Consumir por ID. El módulo NO lo crea**: duplicarlo competiría con el que alguien ya diseñó. El trabajo es **migrar** los 9 literales libres a esos términos |
| CustomVocab de **licencias** | **No** | **Sembrar bajo demanda**, con contenido inicial sensato; a partir de ahí es del admin |
| **Plantilla REA** (`resource_template`) | **No** | **Sembrar bajo demanda**, y solo después de decidir sus campos obligatorios |

**Cómo se siembra:**

1. **Nunca en `install()`.** Crear datos en silencio al instalar es intrusivo en un sitio con convenciones propias, y en el caso de la plantilla sería crearla *antes* de decidir sus campos obligatorios, que es justo la trampa de §8.1 del estudio. Se crea **bajo demanda desde el formulario de configuración**, con acción idempotente que no duplica si ya existe.
2. **`upgrade()` jamás re-siembra ni corrige** el contenido. Una vez creado, el vocabulario o la plantilla son dato del admin, editables desde la UI nativa de Omeka. El módulo no los sobrescribe en ninguna versión futura.
3. **Degradación explícita**: si el setting está vacío o el artefacto ya no existe, el campo cae a texto libre y **la UI lo dice**, en vez de romper. Es el patrón que ya usa `CurriculumSearch`, que devuelve `[]` cuando su setting está sin configurar.

**El setting de plantilla desactiva la trampa.** Hoy `IntegrityChecker` solo distingue «tiene plantilla / no tiene», de modo que *cualquier* plantilla cambia las reglas de validación. Conociendo cuál es **la** plantilla REA, pasa a distinguir tres casos:

| Situación del item | Comportamiento |
| --- | --- |
| Tiene **la** plantilla REA | Valida sus campos obligatorios |
| Tiene **otra** plantilla | Aplica la regla mínima **y avisa**: «plantilla inesperada» |
| Sin plantilla | Aplica la regla mínima |

**Crear la plantilla ≠ asignarla.** Asignarla a los REA existentes es una escritura masiva sobre el catálogo: va como acción de lote con previsualización, confirmación y auditoría, nunca de forma automática. **Criterio de aceptación de esa asignación:** tras aplicarla, los 18 avisos de «sin licencia» **deben seguir apareciendo**. Si desaparecen, la plantilla no marca como obligatorio lo que debe y se ha silenciado el problema en vez de resolverlo.

### Settings nuevos

| Setting | Contenido | Nota |
| --- | --- | --- |
| `oermanager_licence_vocab_id` | ID del CustomVocab de licencias | Decidido desde ADR-0004 §5, nunca implementado |
| `oermanager_resource_type_vocab_id` | ID del CustomVocab de tipos de recurso | Apunta al **id 1 ya existente** |
| `oermanager_rea_template_id` | ID de la plantilla REA | Habilita los tres casos de integridad de arriba |
| `oermanager_default_rights_holder` | Titular de derechos por defecto | Deriva del modelo de autoría de RF-015 |

### Dependencia de CustomVocab

CustomVocab es un módulo opcional de Omeka. Se opta por **dependencia blanda**: `module.ini` **no** la declara; el módulo detecta si está activa y, si no lo está, los campos de licencia y tipo degradan a texto libre y la acción de sembrar no se ofrece. El resto del módulo (alineamiento, IA, integridad) funciona igual. Declararla como dependencia dura impediría instalar OERManager sin ella, lo que es desproporcionado para una función entre varias.

## Consecuencias

- **Abre RF-015** (datos de gestión y autoría del REA): es un hueco de gobierno real, nunca convertido en requisito, y bloquea la columna de autoría, su filtro, su sección del drawer y su acción de lote.
- **La configuración del módulo gana cuatro settings** (ver §«Sembrar, no poseer»): los CustomVocab de licencias y de tipos de recurso, la plantilla REA y el titular de derechos por defecto. El de licencias estaba decidido desde ADR-0004 y nunca llegó a `ConfigForm`.
- **`Module::install()` sigue vacío** y `upgrade()` sin migraciones: la siembra es bajo demanda desde la configuración, no en el ciclo de instalación.
- **La ACL necesita granularidad**: hoy `Module::onBootstrap` concede a `editor` el controlador entero sin lista de privilegios, de modo que una acción nueva de proyecto sería alcanzable por `editor` por herencia, contra NFR-003. Editar la ficha de gestión obliga a revisarlo.
- **Los filtros computados exigen un patrón nuevo** (resolver ids → evaluar predicado → paginar en memoria, con tope duro). Sin él se propaga el defecto actual del filtro «parcial», cuyo total es aproximado.
- **La agregación de los contadores es la misma que pide RF-007** para los gráficos de TASK-006: debe construirse compartida.
- **El historial no es un log**: la anotación vive en el valor, así que no muestra eliminaciones. La reversibilidad real sigue siendo TASK-007, con la que esta UI se acopla naturalmente.
- **Riesgo registrado y mitigado — plantilla REA**: `IntegrityChecker` cambia de reglas si el item tiene plantilla, así que asignar una que no marque licencia y alineamiento como obligatorios **silenciaría de golpe los avisos actuales de los 19 REA**. Se mitiga con el setting `oermanager_rea_template_id` (tres casos de validación) y con el criterio de aceptación de la asignación en lote. Sigue en pie la regla: no se aplica plantilla sin definir antes sus campos obligatorios.
- **Riesgo registrado — higiene masiva**: promover literales a enlace, normalizar el `type` genérico de 44 valores o normalizar licencias reescribe RDF ya escrito sobre el catálogo entero. ADR-0002 no contempla cómo se anota una *corrección* frente a una re-catalogación: exige ADR propio antes de abordarse.
- **No se da superficie a `ai-evaluate`**: existe como endpoint sin UI, es síncrono y puede encadenar decenas de llamadas al LLM. Darle un botón invitaría a lanzarlo sin dimensionar su coste; exigiría antes convertirlo en Job, como se hizo con el propose en TASK-020.

## Decisiones que este ADR deja abiertas

- **PEND-011** — **solo el contenido** del vocabulario de licencias: qué valores exactos, si llevan versión (`CC BY-SA 4.0` frente al actual `ccbysa`) y si se acompañan de la URI canónica en `dcterms:license`. El **mecanismo** (CustomVocab identificado por setting, sembrado bajo demanda) queda resuelto por este ADR.
- **PEND-012** — **resuelto por este ADR** (2026-07-28) en cuanto a *quién crea qué y cuándo*: el módulo siembra la plantilla bajo demanda, la identifica por setting y nunca la asigna automáticamente. Queda abierto **solo qué campos marca como obligatorios**, que es una decisión editorial del propietario y condición previa a crearla.
- **PEND-013** — ¿se muestra la justificación pedagógica de la IA en el historial de lo ya confirmado? Es un caso distinto del panel de propuesta, donde la decisión vigente de TASK-023 (no mostrarla, para no anclar al curador) **no se toca**.

## Afinado (decisión del propietario, 2026-07-29): la columna de alineamiento no se retira, se comprime

**Estado: Aceptado.** Afina la fila `~~Alineamiento~~ … RETIRA` de la tabla §4 del spec de TASK-027, que queda **sustituida por lo aquí decidido**. No toca el resto del ADR.

### Qué obligó a revisarlo

El propietario planteó que retirar la columna pierde **capacidad supervisora**: en producción habrá **miles de REA** y hace falta un control rápido de los que no se ajustan al currículo.

Revisado el spec, el plan **no eliminaba** esa capacidad: la columna nueva de **Integridad** la absorbe explícitamente (`missing_alignment` es uno de sus avisos, spec §4) y **el filtro de alineamiento se mantenía** en todos los casos. Pero el planteamiento expone dos cosas que el plan sí daba por buenas y no lo son:

1. **La premisa «varianza cero» está medida sobre 19 REA.** Es el argumento que sostiene la retirada, y **no sobrevive a la escala de producción**: con miles de REA entrando por vías distintas, el alineamiento dejará de ser uniforme, que es justo cuando la columna empieza a valer. El argumento era correcto para el catálogo de julio de 2026 y solo para él.
2. **La columna ocupaba ancho desproporcionado a lo que comunica.** El problema real medido no era que la columna sobrase, sino que gastaba ~10 em en rendir un rótulo de texto. Eso se arregla comprimiendo, no retirando.

### Qué se decide

1. **La columna de alineamiento se mantiene**, comprimida a **solo glifo**: `✓` cuando está completo, `⚠` cuando no. El rótulo íntegro (`Completo` / `Parcial` / `Sin alinear`) viaja en el **nombre accesible** y en el `title`, no en la celda.
2. **En la tabla la distinción es binaria** (ajustado / no ajustado al currículo), que es la que dispara la acción a velocidad de barrido. **La severidad no se pierde**: `parcial` y `sin alinear` se siguen distinguiendo por color, por el riel de la fila y por el nombre accesible, y el **filtro de tres estados no cambia**.
3. **El triaje por severidad vive en la cola de calidad**, coherente con §4 de este ADR, que ya la materializa como presets de filtro y contadores sobre esta misma tabla y no como tabla nueva. Ahí la severidad **sí** dirige la decisión, así que ahí exige formas distintas por estado (ADR-0014 §3).
4. **Cuando la columna de Integridad se estrene**, se reevalúa si absorbe a esta o conviven. Criterio para esa decisión, fijado ahora: **la integridad es la señal más fiable de las dos** —`AlignmentStatus` da `Completo` a un REA con la materia literal rota (D3), un falso positivo que `IntegrityChecker` no comete—, así que si conviven, la rectora es integridad y el riel de fila se ancla a ella (ADR-0014 §4). Lo que **no** se acepta es quedarse sin ninguna de las dos.

### Consecuencias

Se recupera ~4 em de ancho de tabla sin perder la señal. El coste es que la tabla deja de nombrar el estado en texto: quien no distinga `✓` de `⚠` depende del `title` y del lector de pantalla, que es exactamente el caso que ADR-0014 §3 obliga a cubrir y por el que la distinción accionable va por forma y no por color.

**Queda pendiente:** medir el comportamiento del riel y del glifo sobre un volumen realista. Todo lo verificado hasta hoy lo ha sido sobre 19 filas, y esta decisión se toma para un escenario de miles que aún no existe.

## Afinado (TASK-028 rebanada 2, 2026-08-05)

**Estado: Aceptado.** Cierra la rebanada 2 (spec `docs/superpowers/specs/2026-08-03-task-028-rebanada-2-design.md`), que estrena la columna de Integridad prevista en §Decisión.7 y en el afinado del 2026-07-29 de este mismo ADR. Commits `fabe205`…`9bf5765`.

**RF-006 cambia de semántica.** Las reglas mínimas —anclaje presente y licencia presente— dejan de ser la rama «else» de la comprobación de integridad y pasan a aplicarse **siempre**; los campos obligatorios de la plantilla REA se **suman**, no sustituyen. Antes de este afinado eran ramas excluyentes: asignar una plantilla que no declarase obligatorios el anclaje y la licencia habría silenciado ambos avisos sin que el catálogo mejorase un ápice, la trampa que TASK-027 §8.1 anticipó y que PEND-012 iba a abrir en cuanto se sembrase la plantilla. Se añade también el aviso `literal_in_link_property` (severidad aviso, D-3 del spec): un literal en una property de enlace contaba como valor presente y la integridad decía «ok»; medido sobre el catálogo real, 4 valores lo disparan (`schema:about` ×3, `educationalLevel` ×1), coincidiendo con el defecto D2 que TASK-027 había contado. Nota gemela en `docs/requirements.md`, fila RF-006.

**Dos revisiones conscientes de §4 de este ADR, ambas deliberadas y no accidentales:**

1. **La columna de Licencia no marca sus tres estados en la celda.** El estudio de TASK-027 §3 pedía para Licencia tres estados marcados (valor del vocabulario / fuera del vocabulario / sin licencia). Con **18 de 19 REA sin licencia**, marcar los tres estados en la fila habría marcado la tabla entera, y una marca presente en el 95 % de las filas deja de señalar la excepción (regla 3 de ADR-0014 al pie de la letra). La columna queda en texto y tinta apagada, sin glifo; los tres estados **siguen existiendo** en el filtro y quedan para el drawer de la rebanada 3.
2. **La columna de Anclaje no se retira.** Decisión ya tomada por el propietario en el afinado del 2026-07-29 de este mismo ADR (más arriba): esta rebanada la hereda tal cual. Anclaje e Integridad **conviven y se solapan en parte, a propósito**: Anclaje gradúa el ajuste curricular en tres estados con filtro propio; Integridad agrega «ficha sana» en un contador. Responden preguntas distintas —«¿está ajustado al currículo?» frente a «¿está completa la ficha?»—. El riel de 3px de la fila (ADR-0014 regla 4) se re-ancla de Anclaje a Integridad, que es el movimiento que la pasada visual de la rebanada 1 dejó previsto por escrito.

**Verificado sobre el catálogo real (19 REA), arnés `test/container/columns-check.php`, salida `6 OK, 0 FAIL, 1 SKIP`, exit 0:** `ok=1`, `warning=18`, `error=0`; `dead_link=0` confirma D-5 (el tercer estado del semáforo sigue sin productor real, ver §4.1 del spec); `literal_in_link_property=4` coincide con lo medido en TASK-027; `missing_license=18` de 19. La comprobación de D-6 (suma plantilla+mínimo) quedó **SKIP**: ningún REA tiene plantilla asignada todavía (PEND-012 sigue abierto), así que esa rama del comprobador no se ejerció sobre datos reales — queda para cuando la plantilla se siembre y se asigne.

**285 tests PHP (669 aserciones) y 48 tests JS**, subiendo desde 252/37 al empezar la rebanada. **No verificado y declarado como tal:** la UI en navegador con sesión real (columna Integridad, columna Curricular, riel re-anclado), que entra en la deuda de TASK-030 junto con el resto del JS del módulo.

## Fuentes

- `docs/superpowers/specs/2026-07-27-campos-ui-design.md` (estudio de TASK-027).
- `docs/superpowers/specs/2026-08-03-task-028-rebanada-2-design.md` (diseño de la rebanada 2, decisiones D-1…D-8).
- Medición del catálogo real (19 REA, `resource_class_id` 4758) e inventario funcional de la UI, 2026-07-27.
- ADR-0002 (auditoría), ADR-0004 (mapeo RDF), ADR-0005 (vista maestra v1), ADR-0009 (grafo curricular), ADR-0011 (capa de contexto), ADR-0014 (norma visual).
