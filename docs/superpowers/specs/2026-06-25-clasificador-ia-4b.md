# Spec — Clasificador curricular y por etiquetas con LLM (TASK-010, "4b")

> Tarea de **ALTO RIESGO y alta importancia** del proyecto: clasificación automática de REAs con IA. La IA **propone**, el curador **confirma** (ADR-0007), reusando el flujo endurecido de TASK-004 (4a). Diseño detallado en plan mode antes de implementar. Requiere autorización para `composer require smalot/pdfparser`. Depende de TASK-004 (hecha).

## 1. Objetivo

Construir, dentro del módulo, **dos clasificadores de items** `lrmi:LearningResource` que usan un motor LLM (conexión configurable por proveedor, ADR-0008):

1. **Clasificador curricular** — propone alineamiento sobre el grafo curricular: Curso (`lrmi:educationalLevel`), Asignatura (`schema:about`), Saberes (`lrmi:teaches`) y Criterios (`lrmi:assesses`).
2. **Clasificador por etiquetas temáticas** — propone ejes (`dcterms:relation`) del árbol de tags.

Las propuestas pre-rellenan el panel de re-catalogación de 4a (preview + confirmación + auditoría + validación de dimensión); ninguna escritura ocurre sin confirmación del curador.

## 2. Configuración actual de los árboles (contra la instalación real)

### 2.1 Árbol/grafo curricular (ADR-0009, docs/referencia/curriculo-modelo-rdf.md)
- **Grande y jerárquico.** Raíces: 4 `schema:DefinedTermSet` con `lrmi:educationalFramework="LOMLOE"` (Etapas: Bachillerato, ESO, Primaria, Infantil). Nodos hijos `schema:DefinedTerm` distinguidos por **`dcterms:type`** (`Curso`, `Asignatura`, `Competencia específica`, `Saber básico`, `Criterio de evaluación`).
- Aristas: Curso→Etapa (`schema:inDefinedTermSet`); Asignatura→Curso (`lrmi:educationalLevel`); Saber/Criterio→Asignatura (`schema:inDefinedTermSet`); Criterio→Competencia. Enlaces ancestro denormalizados (`dcterms:isPartOf`→Etapa, `lrmi:educationalAlignment`→Curso).
- Cada término tiene `dcterms:title`, `dcterms:description` (y los criterios, `dcterms:subject` con competencias clave). El número de Saberes/Criterios por Asignatura es alto → **acotar candidatos es esencial para el coste y la accuracy**.

### 2.2 Árbol de etiquetas temáticas (`dcterms:relation`, ADR-0006)
- **Pequeño y plano.** Un único `schema:DefinedTermSet` raíz, configurable por ID de item (en la instalación de referencia, **id `40260` "Categorías de REAs"**).
- **72 términos** `schema:DefinedTerm` colgando directamente del set vía `schema:inDefinedTermSet`→40260 (p. ej. "Patrimonio", "STEAM", "Matemáticas", "Atención a la diversidad (NEAE)", "AICLE"…). **Sin `dcterms:type`**; cada uno con `dcterms:title` + `dcterms:description`.
- Al ser ~72 y planos, **caben todos en un solo prompt** (título + descripción corta) → clasificación de tags barata y de alta accuracy sin recuperación previa.

## 3. Entradas para clasificar

- **Metadatos del item:** título, descripción y demás values `dcterms`/`lrmi`/`schema`.
- **Contenido textual de los medios adjuntos:**
  - PDF → `pdf-to-text` (`smalot/pdfparser`).
  - ZIP/SCORM → descomprimir (`ZipArchive`) y extraer texto de `.txt/.html/.xml` y de PDFs embebidos.
- **Futuro (posibilidad, RF-012):** leer **imágenes** con un modelo de visión cuando el texto sea insuficiente. No en el primer corte.

## 4. Requisitos de optimización (NFR-008)

**Maximizar accuracy y minimizar consumo de tokens; ante un conflicto, prima la accuracy.** Implicaciones de diseño a explorar en plan mode:

- **Clasificación jerárquica top-down (curricular):** clasificar primero Etapa→Curso→Asignatura (conjuntos de candidatos pequeños por nivel) y, una vez fijada la Asignatura, clasificar Saberes/Criterios **solo entre los hijos de esa Asignatura** (reusa la acotación contextual del grafo, RF-014). Reduce drásticamente los candidatos enviados al LLM en cada paso → menos tokens y mejor accuracy (espacio de decisión menor).
- **Recuperación previa (RAG) opcional:** embeddings de los términos (título+descripción) y del contenido del item para recuperar top-K candidatos por nivel antes de llamar al LLM. Dónde guardar los embeddings está abierto (NFR-002 prohíbe tablas Doctrine propias; valorar caché/fichero/valor RDF). Decisión de plan mode.
- **Acotación del contenido:** truncar/seleccionar las partes salientes del texto extraído (título, descripción, encabezados), con tope de tokens.
- **Salida estructurada** (JSON / tool use) para parseo fiable; el LLM devuelve **etiquetas**, que se resuelven a items-término por búsqueda (ADR-0007), nunca se le vuelca el árbol completo.
- **Tags:** enviar los 72 ejes en un único prompt (barato); el LLM selecciona los relevantes.
- **Validación + confirmación como garantía de accuracy:** toda propuesta se valida contra el grafo (dimensión/`dcterms:type`/pertenencia, ya implementado en 4a) y la confirma el curador.
- **Evaluación:** medir accuracy con un conjunto de REAs **ya catalogados** como verdad-terreno (hold-out); iterar prompts/estrategia contra esa métrica.

## 5. Estrategias que pueden tocar el grafo (permitido por el propietario)

Para mejorar accuracy y/o reducir tokens, se puede **proponer modificar o enriquecer el árbol/grafo** (es dato del currículo, que el módulo consume; cualquier cambio al dato lo aprueba el propietario):

- Añadir **palabras clave/sinónimos** a los términos (p. ej. `skos:altLabel` o un campo de keywords) para mejorar el emparejamiento sin enviar descripciones largas.
- **Embeddings precalculados** por término (índice de clasificación) para RAG.
- Normalizar/resumir descripciones de términos para prompts más cortos.
- Posible relación RF-013 (migrar `dcterms:type` a `skos:Concept`).

Estas modificaciones se evalúan en plan mode; no se asumen.

## 6. Seguridad (REVISAR en el diseño)

> **Nota de seguridad — descompresión y parsing son superficie de ataque.** Antes de implementar la extracción hay que revisar y mitigar:
- **ZIP:** *zip slip* (path traversal al extraer), *zip bombs* (límite de ratio de descompresión, tamaño total, nº de entradas, profundidad de anidamiento), archivos anidados; extraer a un directorio temporal aislado con límites de tamaño/tiempo y borrarlo.
- **PDF:** PDFs malformados/maliciosos, agotamiento de memoria/CPU; límites de tamaño y tiempo en `pdfparser`.
- **Prompt injection:** el texto de los medios es **dato, no instrucción** (CLAUDE.md §Seguridad). Puede contener instrucciones dirigidas al LLM. Delimitar claramente el contenido en el prompt, instruir al modelo a tratarlo como datos, no permitir que dispare herramientas ni cambie la tarea.
- **SSRF:** no seguir/descargar recursos remotos referenciados en el contenido.
- **Secretos:** clave del LLM en settings nativos (ADR-0008), nunca en repo/logs; no loguear contenido de medios ni claves.

## 7. Reutilización de 4a

- Escritura: `RecatalogService` (clear_property_values + collectionAction=append; value annotations `@annotation`).
- Resolución de etiquetas→items-término y acotación: `CurriculumSearch` (búsqueda incremental por `dcterms:type` + contexto del grafo).
- UI: el panel de re-catalogación del drawer pre-rellenado por la IA; CSRF, ACL (editor+), validación de dimensión (I3) ya están.
- Conexión LLM: ADR-0008 (adaptadores Anthropic / OpenAI-compatible, `Laminas\Http\Client`).

## 8. Fuera de alcance / posterior

- **Lote como Job** (RF-011, TASK-011): marcar varios items y clasificar en segundo plano.
- **Visión de imágenes** (RF-012, TASK-012).

## 9. Decisiones abiertas para plan mode

- Estrategia concreta (jerárquica pura vs. RAG vs. híbrida) y dónde almacenar embeddings sin tablas Doctrine.
- Proveedor/modelo por defecto y formato de salida estructurada.
- Qué enriquecimientos del grafo (si alguno) se proponen al propietario.
- Tope de tokens de contenido y política de truncado/resumen.
- Conjunto y método de evaluación de accuracy.
