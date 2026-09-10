# ADR-0019: La licencia del REA es `dcterms:license`, guardada como URI

## Estado

Aceptado (2026-09-10). Reemplaza **ADR-0004 §5** (licencia = literal de `CustomVocab` en `dcterms:rights`). No cambia el **mecanismo** de ADR-0013 (vocabulario identificado por id en un setting, «sembrar, no poseer»), solo la property y el tipo de valor al que se aplica.

## Contexto

Desde ADR-0004 (2026-06-15) todo el módulo supervisa la licencia en `dcterms:rights`: la columna «Licencia» de la vista maestra, el filtro por licencia y el filtro «Sin licencia», la regla `missing_license` de integridad (RF-006), la dimensión «licencia» de las estadísticas (RF-007), el área «Información» del panel de detalle y el drawer. El propietario corrige la premisa (2026-09-10): **la licencia vive en `dcterms:license` y se guarda como URI** (URI canónica + etiqueta, p. ej. «Creative Commons Attribution 4.0 International»).

Semánticamente es lo correcto: en DCMI `dcterms:license` es «a legal document giving official permission to do something with the resource» y su rango natural es un recurso (URI); `dcterms:rights` es una declaración de derechos genérica. PEND-011 ya había dejado escrito que la **versión** de la licencia (que la etiqueta «CC BY-SA» no distingue) se expresaría «en la URI canónica de `dcterms:license`», como trabajo declarado y sin diseñar.

**Medido en el catálogo real (2026-09-10, solo lectura):**

- 19 REA (`lrmi:LearningResource`). **0 valores `dcterms:license`** en toda la instalación.
- 4 REA con `dcterms:rights`: 2 con el literal `ccbysa`, 2 con términos del CustomVocab id 2 «licencias» (`CC BY-ND`, `CC BY-SA`). Otros 76 valores `dcterms:rights` (`copyright`, `p`) viven en recursos que **no** son REA.
- `oermanager_licence_vocab_id = 2` apunta a un CustomVocab **de términos literales**. La plantilla REA (id 3) declara `dcterms:rights` con `customvocab:2`.
- El módulo CustomVocab instalado (2.0.2) admite vocabularios **de tipo URI**: cada entrada es «URI etiqueta»; al hidratar, el valor se guarda con `uri` = la URI elegida y `value` = la etiqueta del vocabulario (`CustomVocab\DataType\CustomVocab::hydrate()`).

**Verificado en el core 4.2 (afecta al diseño):** `ValueRepresentation::__toString()` delega en `DataType::toString()`, que para `uri` y `customvocab` devuelve `value()` — **la etiqueta**. Un valor URI **sin etiqueta** se convierte en cadena vacía. Hoy la columna, el panel y las estadísticas leen la licencia como texto (`(string) $value` / `$value->value()`), así que, sin cambio, un REA con licencia URI sin etiqueta aparecería como «Sin licencia». Los operadores de búsqueda `eq`/`in` del core sí casan contra `value` **o** `uri`, así que el filtro por licencia admite etiqueta o URI exactos sin cambios.

## Alternativas consideradas

1. **Control del valor**
   - **A) CustomVocab de tipo URI**, identificado por el mismo setting `oermanager_licence_vocab_id` — el editor nativo muestra un desplegable; la etiqueta la fija el vocabulario, así que es homogénea. Reusa el mecanismo de ADR-0013. **Elegida.**
   - B) Tipo `uri` nativo libre — cualquier URI y etiqueta opcional escrita a mano; el módulo solo supervisa. Contra: heterogeneidad de etiquetas y URIs, justo lo que la gobernanza quiere evitar; el setting de vocabulario pierde sentido.
2. **Los 4 REA con licencia en `dcterms:rights`**
   - **A) No migrar.** Pasan a «Sin licencia» hasta que un curador rellene `dcterms:license`. `ccbysa` no dice versión, así que no hay URI deducible, y ADR-0013 exige ADR propio para cualquier normalización masiva. **Elegida.**
   - B) Migración con tabla etiqueta→URI fijada por el propietario, con ADR de corrección y auditoría (ADR-0002).
3. **`dcterms:rights` después del cambio**
   - **A) El módulo lo olvida**: sale de columna, drawer, panel, estadísticas e integridad. El valor sigue en el item y la ficha nativa de Omeka lo muestra. **Elegida.**
   - B) Mostrarlo como campo informativo «Derechos» en el drawer.
4. **Reglas de integridad de la licencia**
   - A) Solo presencia (como hoy, sobre `dcterms:license`).
   - **B) Presencia + debe ser URI**: aviso nuevo `license_not_uri` si un valor de `dcterms:license` no lleva URI (un literal «CC BY» metido por la REST API, una importación o un CustomVocab de términos mal apuntado). **Elegida.**
   - C) Presencia + URI + pertenencia al vocabulario configurado (los «tres estados» de ADR-0013, pendientes de la rebanada 3b). Fuera de alcance.

## Decisión

1. La licencia del REA es **`dcterms:license`**. El término vive en **una sola constante** (`IntegrityPolicy::LICENSE_TERM`) y todo consumidor la referencia; los sitios donde hoy va escrito a mano (`MasterViewQuery`, `DimensionFacts`, `ItemPanelData`, config de columnas, vistas, JS) se alinean con ella.
2. El valor se **guarda como URI**, controlado por un **CustomVocab de tipo URI** identificado por `oermanager_licence_vocab_id` (mismo setting, nuevo contenido). El módulo **no** crea ni modifica ese vocabulario ni la plantilla REA en este cambio: son dato de instancia del propietario (ver «Pasos manuales» en TASK-040).
3. **Texto mostrable de una licencia = etiqueta si la hay; si no, la URI.** Una pieza pura (`Governance\ValueText::of(?label, ?uri)`) lo resuelve para PHP; `valueText()` hace lo mismo en JS. Así ningún consumidor vuelve a confundir «URI sin etiqueta» con «sin licencia».
4. **Integridad:** `missing_license` (aviso) si `dcterms:license` está vacío; **`license_not_uri`** (aviso, uno por valor) si un valor no lleva URI. La pertenencia al vocabulario sigue sin validarse.
5. **`dcterms:rights` deja de ser una property que el módulo lea.** No se borra, no se migra y no se muestra en las vistas propias del módulo.
6. **Estadísticas:** la dimensión «licencia» agrupa por el texto mostrable (etiqueta o, en su defecto, URI). Con un CustomVocab URI la etiqueta es la del vocabulario y es estable; si algún día dos URIs compartiesen etiqueta se fundirían — riesgo aceptado, no se resuelve hoy.

## Consecuencias

- **Visible al desplegar:** los 4 REA que hoy muestran licencia pasarán a «Sin licencia» y a aviso `missing_license`. Es el efecto buscado (su licencia no está donde debe), no una regresión; conviene avisar a los curadores.
- **Pasos manuales del propietario, fuera del código:** crear el CustomVocab de URIs de licencias, apuntar a él `oermanager_licence_vocab_id` y cambiar en la plantilla REA (id 3) `dcterms:rights`/`customvocab:2` por `dcterms:license` con el nuevo vocabulario. Hasta entonces el editor nativo seguirá ofreciendo el campo antiguo.
- **Contenido del vocabulario de URIs** (qué licencias, qué versión, qué URI canónica exacta): decisión editorial del propietario → **PEND-011 se reabre** con ese alcance. El código no depende de ese contenido.
- La skill `recatalogador` (tabla de mapeo RDF) y ADR-0004 §5 quedan desactualizadas: la skill se corrige con confirmación del propietario (regla de CLAUDE.md para Skills preexistentes); ADR-0004 recibe la nota «§5 reemplazado por ADR-0019».
- La regla `license_not_uri` detecta sola un vocabulario mal apuntado (uno de términos literales): cada licencia elegida desde él dispararía el aviso.
- Queda abierto, como ya estaba: validar contra el vocabulario (rebanada 3b de TASK-028) y la higiene masiva de licencias heredadas (exige ADR propio, ADR-0013).

**Addendum (2026-09-10, revisión final de rama):** una selección de columnas **guardada por un usuario** (`user_setting` id `columns_admin_oer_items`) sustituye a `column_defaults` (core `Browse::getColumnsData()`); si esa selección aún apunta a `dcterms:rights`, ese usuario sigue viendo una columna «Licencia» que contradice integridad, panel y estadísticas. Medido en la revisión final: usuarios #3 y #10. El módulo no reescribe preferencias ajenas (decisión del propietario); paso manual: esos usuarios deben restablecer o reañadir la columna «Licencia» en sus preferencias de la vista maestra. Alternativa a decidir por el propietario: un `upgrade()` que reescriba `property_term` en las selecciones guardadas (escritura sobre datos de usuario).

## Fuentes

- Instrucción del propietario en el chat (2026-09-10) y respuestas a las cuatro preguntas de diseño de esa misma sesión.
- Medición de solo lectura sobre la BD del contenedor (2026-09-10): tablas `value`, `custom_vocab`, `setting`, `resource_template_property`.
- Core Omeka 4.2: `Api/Representation/ValueRepresentation::__toString()`, `DataType/AbstractDataType::toString()`, `DataType/Uri::render()`, `Api/Adapter/AbstractResourceEntityAdapter::buildPropertyQuery()` (`eq`/`in` casan `value` o `uri`).
- Módulo CustomVocab 2.0.2: `src/DataType/CustomVocab.php` (`getUriForm()`, `hydrate()`, `toString()`).
- ADR-0004 §5, ADR-0013 («sembrar, no poseer»), PEND-011.
