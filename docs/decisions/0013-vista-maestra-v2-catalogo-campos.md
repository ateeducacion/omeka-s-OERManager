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

## Consecuencias

- **Abre RF-015** (datos de gestión y autoría del REA): es un hueco de gobierno real, nunca convertido en requisito, y bloquea la columna de autoría, su filtro, su sección del drawer y su acción de lote.
- **La configuración del módulo gana settings**: CustomVocab de licencias, CustomVocab de tipos de recurso y titular de derechos por defecto. El primero estaba decidido desde ADR-0004 y nunca llegó a `ConfigForm`.
- **La ACL necesita granularidad**: hoy `Module::onBootstrap` concede a `editor` el controlador entero sin lista de privilegios, de modo que una acción nueva de proyecto sería alcanzable por `editor` por herencia, contra NFR-003. Editar la ficha de gestión obliga a revisarlo.
- **Los filtros computados exigen un patrón nuevo** (resolver ids → evaluar predicado → paginar en memoria, con tope duro). Sin él se propaga el defecto actual del filtro «parcial», cuyo total es aproximado.
- **La agregación de los contadores es la misma que pide RF-007** para los gráficos de TASK-006: debe construirse compartida.
- **El historial no es un log**: la anotación vive en el valor, así que no muestra eliminaciones. La reversibilidad real sigue siendo TASK-007, con la que esta UI se acopla naturalmente.
- **Riesgo registrado — plantilla REA**: `IntegrityChecker` cambia de reglas si el item tiene plantilla. Asignar una que no marque licencia y alineamiento como obligatorios **silenciaría de golpe los avisos actuales de los 19 REA**. No se aplica plantilla sin definir antes sus campos obligatorios.
- **Riesgo registrado — higiene masiva**: promover literales a enlace, normalizar el `type` genérico de 44 valores o normalizar licencias reescribe RDF ya escrito sobre el catálogo entero. ADR-0002 no contempla cómo se anota una *corrección* frente a una re-catalogación: exige ADR propio antes de abordarse.
- **No se da superficie a `ai-evaluate`**: existe como endpoint sin UI, es síncrono y puede encadenar decenas de llamadas al LLM. Darle un botón invitaría a lanzarlo sin dimensionar su coste; exigiría antes convertirlo en Job, como se hizo con el propose en TASK-020.

## Decisiones que este ADR deja abiertas

- **PEND-011** — contenido del vocabulario de licencias (¿etiquetas con versión?, ¿URI canónica en `dcterms:license`?).
- **PEND-012** — ¿se crea y se aplica una plantilla REA, y con qué campos obligatorios?
- **PEND-013** — ¿se muestra la justificación pedagógica de la IA en el historial de lo ya confirmado? Es un caso distinto del panel de propuesta, donde la decisión vigente de TASK-023 (no mostrarla, para no anclar al curador) **no se toca**.

## Fuentes

- `docs/superpowers/specs/2026-07-27-campos-ui-design.md` (estudio de TASK-027).
- Medición del catálogo real (19 REA, `resource_class_id` 4758) e inventario funcional de la UI, 2026-07-27.
- ADR-0002 (auditoría), ADR-0004 (mapeo RDF), ADR-0005 (vista maestra v1), ADR-0009 (grafo curricular), ADR-0011 (capa de contexto).
