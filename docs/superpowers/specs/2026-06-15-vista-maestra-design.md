# Vista maestra (TASK-003) — Diseño v1

- **Fecha:** 2026-06-15
- **Estado:** propuesto (pendiente de revisión del propietario)
- **Relacionado:** RF-001, RF-002, RF-003; NFR-001, NFR-002, NFR-003, NFR-005, NFR-006; TASK-003; ADR-0001, ADR-0002, ADR-0004.
- **Resuelve** PEND-007 punto 1 (columnas, filtros y panel de detalle de la vista maestra) **para el alcance v1**.

## 1. Objetivo y alcance

La vista maestra es la tabla de gestión del catálogo de REA en el panel admin, desde la que los gestores supervisan y curan el catálogo. Esta v1 cubre **lectura + curación de visibilidad**.

**En alcance (v1):**
- Tabla de items `lrmi:LearningResource` con columnas propias, filtros y panel de detalle (RF-001, RF-002).
- Curación de visibilidad público/privado, individual y por lotes (RF-003).

**Fuera de alcance (sprints siguientes):**
- Re-catalogador curricular y por tags (TASK-004).
- Asignación de proyecto `schema:isPartOf` desde la vista (es editable por gestores, pero entra en un sprint posterior).
- Panel de detalle configurable por el admin (v1 = set fijo bien diseñado).
- Comprobación de integridad completa (TASK-005); aquí solo el indicador ligero de Alineamiento.
- Matriz rol×acción propia (PEND-007); v1 reusa el permiso nativo de edición.

## 2. Arquitectura

**Enfoque A** (decidido): página **server-rendered** en `admin/oer-manager` que reutiliza el **sistema de browse de Omeka 4.x** (column types, paginación, orden, búsqueda y selección por lotes nativas) + una **capa jQuery** fina para el panel de detalle (drawer) y las acciones, sobre la **REST API**.

Invariantes:
- **Extender, no parchear** (NFR-001): integración solo vía `router`, `column_types`, helpers de browse nativos y REST API.
- **Sin tablas propias** (NFR-002): todo se lee/escribe vía API y valores RDF.
- El drawer es lo único asíncrono; el resto es server-rendered (ni SPA ni tabla desde cero).

## 3. Componentes

| Componente | Fichero | Responsabilidad |
|---|---|---|
| Controlador | `src/Controller/Admin/IndexController.php` (`indexAction`) | Construir la query (filtrada a `lrmi:LearningResource`), aplicar filtros/orden/paginación, render de la tabla |
| Acción de visibilidad | mismo controlador (`setVisibilityAction`, POST) | Cambiar `is_public` individual y en lote vía API (la API aplica el ACL nativo de edición) |
| Column type custom | `src/ColumnType/AlignmentStatus.php` | Computa *Completo/Parcial/Sin alinear*; registrado en `column_types` |
| Constructor de query | `src/Service/MasterViewQuery.php` | Traduce los 9 filtros a parámetros de búsqueda de la API; encapsula la lógica del filtro *Alineamiento* |
| Vista | `view/oer-manager/admin/index/index.phtml` | Barra de filtros + tabla (helpers de browse del core) + contenedor del drawer |
| JS | `asset/js/oer-master-view.js` | Drawer (fetch `/api/items/{id}` → render), filtros rápidos, selección por lotes, confirmación |
| CSS | `asset/css/oer-master-view.css` | Estilo del drawer lateral |

- La property `lrmi:LearningResource` se resuelve **por término** en runtime (no por id de instalación); el filtro de clase es fijo (RF-001).
- `Module.php` apenas cambia: la columna va por config y la página por ruta; sin listeners nuevos en v1.

## 4. Columnas

Orden fijo de la tabla v1:

| # | Columna | Origen | Tipo |
|---|---|---|---|
| 1 | Título | `dcterms:title` | column type `title` (core) |
| 2 | Visibilidad | `is_public` | column type de visibilidad (core) |
| 3 | Etapa | `lrmi:educationalLevel` | column type `value` (core, configurado) |
| 4 | Materia | `schema:about` | column type `value` (core, configurado) |
| 5 | Alineamiento | computado | **`AlignmentStatus` (custom)** |
| 6 | Licencia | `dcterms:rights` | column type `value` (core, configurado) |
| 7 | Modificado | `o:modified` | column type de fecha (core) |

**Regla del indicador Alineamiento (3 estados):**
- **Completo** = etapa (`lrmi:educationalLevel`) + materia (`schema:about`) + ≥1 criterio (`lrmi:assesses`) + ≥1 saber (`lrmi:teaches`).
- **Parcial** = tiene algún alineamiento pero le falta alguno de los anteriores.
- **Sin alinear** = sin criterios (`lrmi:assesses`) ni saberes (`lrmi:teaches`).

Es una versión ligera; la integridad completa es TASK-005.

## 5. Filtros

Barra de filtros (vista de gestor, máxima flexibilidad de búsqueda):

| Filtro | Origen |
|---|---|
| Búsqueda por título (texto libre) | `dcterms:title` |
| Etapa | `lrmi:educationalLevel` |
| Materia | `schema:about` |
| Visibilidad (público/privado) | `is_public` |
| Alineamiento (completo/parcial/sin alinear) | computado |
| Licencia | `dcterms:rights` (CustomVocab) |
| Proyecto | `schema:isPartOf` |
| Eje temático | `dcterms:relation` |
| Tipo de recurso | `lrmi:learningResourceType` |

- La mayoría mapea a filtros de property de la búsqueda nativa.
- **Alineamiento**: "sin alinear" se expresa directo en query (items sin `lrmi:assesses` ni `lrmi:teaches`); "completo/parcial" se compone en `MasterViewQuery` (combinación de condiciones has/has-no value), evitando post-filtrado masivo en memoria siempre que se pueda.

## 6. Panel de detalle (drawer)

**Forma:** panel lateral (drawer) que se abre a la derecha al seleccionar una fila, sin perder la tabla. **Fijo** en v1 (configurable = sprint posterior).

**Contenido (de arriba abajo):**
- **Cabecera**: miniatura · título · badge de visibilidad (con acción de cambiarla) · enlace al item nativo de Omeka.
- **Descripción** (`dcterms:description`).
- **Alineamiento curricular**: Etapa · Materia · Criterios de evaluación (lista) · Saberes básicos (lista) · estado (completo/parcial/sin alinear).
- **Clasificación**: Eje temático (`dcterms:relation`) · Proyecto (`schema:isPartOf`) · Tipo de recurso (`lrmi:learningResourceType`).
- **Legal**: Licencia (`dcterms:rights`).
- **Metadatos**: Propietario · Creado / Modificado.

Render **cliente** a partir del JSON-LD de `GET /api/items/{id}`.

## 7. Curación de visibilidad

- **Individual**: toggle público/privado en la cabecera del drawer y acción rápida por fila → POST `setVisibilityAction` → API update `o:is_public`.
- **Lote**: casillas de selección + barra de acción ("hacer público/privado") con **confirmación que muestra cuántos REA afecta** → API `batchUpdate`.
- **ACL**: se reusa el **permiso nativo de edición** — la API solo deja cambiar `is_public` a quien ya puede editar el item (NFR-003 en v1; matriz propia → PEND-007).
- **Auditoría**: la visibilidad queda **fuera** de la auditoría RDF (ADR-0002); esta acción **no** escribe value annotations.

## 8. Flujo de datos

1. **Carga**: GET `admin/oer-manager` → resuelve clase `lrmi:LearningResource` → `MasterViewQuery` monta query base + filtros (GET) → API `search items` paginado/ordenado → tabla con los column types.
2. **Filtros**: la barra envía GET → re-query (server-rendered).
3. **Drawer**: clic en fila → JS `GET /api/items/{id}` → render del layout fijo; no recarga la tabla.
4. **Visibilidad individual**: toggle → POST → API update → refresca el badge de esa fila.
5. **Visibilidad en lote**: casillas → barra de acción → confirmación (nº afectados) → POST → API `batchUpdate` → refresco.

## 9. Manejo de errores

- Fetch del drawer falla → error dentro del drawer; la tabla sigue viva.
- Update sin permiso → la API responde 403 (ACL nativo) → el JS avisa y revierte el toggle.
- Lote con fallos parciales → reporta cuántos cambiaron y cuántos no.
- Filtro inválido / sin resultados → se ignora o estado vacío claro; nunca rompe la query base (que siempre filtra a `lrmi:LearningResource`).

## 10. NFR

- **i18n** (NFR-005): cadenas traducibles (`// @translate`), idiomas es/en.
- **a11y** (NFR-006): drawer navegable por teclado (foco al abrir, `Esc` cierra, foco devuelto al cerrar), atributos ARIA; se mantiene el nivel del admin de Omeka 4.x.
- **Rendimiento**: paginación nativa del browse (no carga todo el catálogo); el filtro Alineamiento se resuelve en query siempre que se pueda.
- **NFR-001/002**: sin parchear el core, sin tablas propias.

## 11. Testing

Arnés PHPUnit existente (`test/`):
- **Unit `AlignmentStatus`**: tabla de casos completo/parcial/sin alinear con items sintéticos.
- **Unit `MasterViewQuery`**: cada filtro genera los parámetros de búsqueda correctos (foco en el filtro Alineamiento).
- **Contrato**: la ruta `admin/oer-manager` y el `column_types` registrado (estilo de los tests de `ModuleConfig` actuales).
- **JS**: drawer y acciones de visibilidad → verificación manual documentada en el contenedor real (no hay arnés JS).

## 12. Trazabilidad y pendientes

- **Resuelve** PEND-007 punto 1 (columnas exactas y orden, filtros, panel de detalle) **para v1**.
- **Sigue abierto** el resto de PEND-007: reglas del re-catalogador (cardinalidad/obligatoriedad), reglas de integridad y plantilla REA, catálogo de estadísticas, matriz rol×acción completa, objetivos numéricos de rendimiento, lista definitiva de idiomas, nivel de accesibilidad objetivo.
- Al cerrar TASK-003 se actualizan `backlog.md`, `traceability.md` y `project-memory.md`.
