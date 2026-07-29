# ADR-0013: Vista maestra v2 — catálogo de campos y reequilibrio hacia la gobernanza

## Estado

Propuesto (2026-07-27) — pendiente de aprobación del propietario.

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

## Fuentes

- `docs/superpowers/specs/2026-07-27-campos-ui-design.md` (estudio de TASK-027).
- Medición del catálogo real (19 REA, `resource_class_id` 4758) e inventario funcional de la UI, 2026-07-27.
- ADR-0002 (auditoría), ADR-0004 (mapeo RDF), ADR-0005 (vista maestra v1), ADR-0009 (grafo curricular), ADR-0011 (capa de contexto).
