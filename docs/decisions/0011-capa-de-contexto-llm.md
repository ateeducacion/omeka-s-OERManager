# ADR-0011: Capa de contexto del LLM (dos modelos, destilado fiel, visión, contexto por paso)

## Estado

Aceptado (2026-06-30). Extiende ADR-0008 (conexión LLM) y ADR-0010 (anclaje
bottom-up); revisa el "off-by-default" de la visión de la spec de TASK-012 §5. No
cambia el mapeo RDF (ADR-0004) ni el grafo (ADR-0009).

## Contexto

La catalogación IA-assistida ensambla el contexto del LLM como un **único texto
plano** (`itemMetadataText` + medios concatenados por `ContentExtractor`) truncado
por presupuesto, y lo pasa **idéntico** a ambos clasificadores y a **cada paso** del
LLM. Defectos detectados en pruebas funcionales (TASK-015/017/018):

- Concatenación plana sin procedencia → entierra la señal (P1).
- Truncado ciego que descarta el contenido del medio cuando los metadatos van
  primero (P2).
- Mismo contexto completo a todos los pasos, caro y ruidoso (P3).
- `pdf_unreadable` (PDF escaneado) e imágenes con la señal real nunca llegan al LLM
  (P4).

## Decisión

1. **Dos roles de LLM** (extiende ADR-0008). Un **modelo de extracción** barato y
   vision-capable (default sugerido Haiku) además del **modelo clasificador** actual.
   Mismo proveedor/endpoint/clave por defecto, distinto `model` (`EXTRACTION_MODEL`).
   Si el proveedor no es vision/PDF-capable, la visión se omite con motivo y la
   propuesta sigue con texto.
2. **Destilador fiel.** El LLM de extracción lee el crudo (metadatos + texto de
   medios + descripciones de visión) y produce una **ficha estructurada** (tema,
   conceptos clave, vocabulario, qué enseña el recurso). **No infiere currículo**: no
   propone etapa/materia/curso salvo que esté literal en el recurso. La inferencia
   curricular es del clasificador (con el grafo, ADR-0009/0010). Conservador: **prima
   no perder información** sobre el ahorro de tokens.
3. **Visión: siempre top-N imágenes + rescate de PDF escaneado.** No condicional.
   Filtro heurístico (descartar `placeholder|sprite|icon|logo|bg|thumb…` + tamaño
   mínimo; tomar las N mayores, `vision_max_images` default 3). **Binario directo al
   LLM** (sin tooling local): imágenes como bloque `image`, PDF escaneado como bloque
   `document`(pdf) nativo del proveedor (la Messages API de Anthropic lo acepta).
   Master toggle `vision_enabled` (default **off**) por privacidad: introduce egress
   de medios binarios a un tercero. Revisa el off-by-default por-item de TASK-012 §5:
   cuando el toggle está on, la visión es always-top-N, no condicional.
4. **Contexto por paso.** Pasos gruesos (etapa, materia, bloque) → solo la ficha
   (barato). Pasos finos (saberes, criterios) → ficha + contenido crudo de medios
   (completo, acotado por presupuesto; "relevante" = el contenido del medio, sin
   recortes arriesgados) — donde no se puede perder detalle.
5. **Objeto de contexto estructurado (`ItemContext`).** Sustituye el blob plano:
   secciones con procedencia (metadatos, texto de medios por fuente, descripciones de
   visión, ficha). El truncado por presupuesto **protege el contenido de medios** (ya
   no lo entierran los metadatos).

## Consecuencias

- `LlmClientInterface` gana soporte multimodal: `AnthropicClient` reenvía `content`
  como array de bloques (cambio mínimo, ya lo reenvía); `OpenAiCompatibleClient` mapea
  imágenes a `image_url` y omite PDF si el proveedor no lo soporta.
- Nuevos componentes: `MediaVisionExtractor` (filtro puro + llamada de visión),
  `ContextDistiller` (LLM extracción → ficha), `ItemContext` (VO estructurado). El
  núcleo sigue puro y testeable en host con clientes fake (arquitectura TASK-010).
- `AiCataloguer` orquesta extracción + visión opcional + destilación y enruta el
  contexto por paso; los clasificadores aceptan `ItemContext`.
- Coste de tokens: el modelo caro (clasificador) recibe la ficha compacta en los pasos
  gruesos en vez del blob completo; la extracción/visión va al modelo barato. Se gasta
  en visión para no perder señal; se ahorra en el lado del clasificador.
- Seguridad: contenido como dato-no-instrucción también en el destilador; visión
  gobernada por toggle + tope; se mantienen anti zip-bomb/zip-slip, límites de nodos
  JSON, sin SSRF, clave nunca logueada.

## Limitaciones conocidas

- La calidad de la ficha depende del modelo de extracción; un destilado pobre con
  metadatos pobres puede limitar los pasos gruesos (mitigado: los pasos finos
  conservan el crudo de medios).
- La visión (PDF/imagen al proveedor) solo funciona con proveedores vision/PDF-capable;
  con otros se omite con motivo registrado (no rompe la propuesta).
- Verificación funcional contra el core **diferida al contenedor** (como TASK-010/015).

## Progreso de implementación (TASK-019)

Faseado con TDD real en host (puertos/adaptadores, núcleo puro). Estado (2026-06-30):

- **Fases 1-3 (hechas, commit `4b01d8a`):** ADR-0011 + gobierno «en curso»; adaptador
  multimodal (`LlmClientInterface::supportsImages()/supportsPdf()`;
  `AnthropicClient`/`OpenAiCompatibleClient` traducen bloques `image`/`document`);
  `ItemContext` con `coarseText()`/`fineText()`/`rawForDistillation()` y enrutado por
  paso en `CurricularClassifier`/`TagClassifier`.
- **Fase 4 (hecha):** `ContextDistiller` — `PromptBuilder::buildDistillationPrompt()`
  (ficha fiel: tema/conceptos/vocabulario/qué enseña; **no infiere currículo**;
  contenido como dato-no-instrucción con marcas neutralizadas); destilador puro,
  `TraceableInterface`, sin-LLM-si-vacío, que lee `rawForDistillation()` y devuelve la
  ficha; `AiCataloguer` orquesta extracción → destilación → `withFicha()` →
  clasificación y expone `ficha`/`distillation` en el debug; factoría del 2º cliente
  LLM (`EXTRACTION_MODEL` cae a `MODEL`) + factoría de `ContextDistiller` en
  `module.config.php`. 105 tests verdes, lint PSR-12.
- **Fase 5 (hecha):** `MediaVisionExtractor` — filtro heurístico PURO de imágenes
  (`selectImages()`: descarta ruido por nombre `logo|icon|sprite|bg|thumb…` con
  lookaround + tamaño mínimo/máximo; ordena por tamaño y toma top-N) y `describe()`
  que envía top-N imágenes + PDF escaneado como bloques `image`/`document` al modelo
  de extracción y devuelve la descripción. Doble puerta: master toggle `vision_enabled`
  (off por defecto) + capacidad del proveedor (`supportsImages()/supportsPdf()`);
  `TraceableInterface` (registra `images`/`pdfs` o el motivo de omisión:
  `disabled`/`provider_no_vision`/`no_candidates`). `PromptBuilder::buildVisionPrompt()`
  (fiel, no-clasificador, texto visible como dato-no-instrucción). `AiCataloguer`
  enruta las imágenes y rescata los PDF `pdf_unreadable`/`pdf_empty` a la visión, y la
  ficha viaja a `ItemContext::visionDescriptions`.
- **Fase 6 (hecha):** `OmekaMediaSource::imagesFor()` localiza las imágenes del item
  (jpg/png/gif/webp) con su tamaño, aparte de la cascada de texto; `ConfigForm` añade
  los campos `EXTRACTION_MODEL`, `vision_enabled` (off) y `vision_max_images` (default
  3), persistidos en `Module::getConfigForm()/handleConfigForm()`; `module.config.php`
  registra la factoría de `MediaVisionExtractor` (gating por settings) y la inyecta en
  `AiCataloguer`; `IndexController` pasa `imagesFor()` a `propose()`. **120 tests
  verdes, lint PSR-12.**
- **Pendiente:** solo la **verificación funcional en contenedor** (diferida, como
  TASK-010/015): item con PDF escaneado y con imágenes (#3181) — que la ficha y la
  visión lleguen a los pasos, y que baje el coste de tokens del clasificador.

## Afinado (TASK-022, 2026-07-07)

Diagnóstico con **6 REAs reales** del catálogo (`resource_class_id=4758`) pasados por
el `ContentExtractor` real en el host: la ficha (señal primaria de los pasos gruesos)
salía mala no solo por el prompt sino porque **el crudo que la alimenta estaba roto**.
Dos frentes, sin relajar ninguna defensa de seguridad de la Fase 5:

- **Endurecimiento del `ContentExtractor`:** (a) **denylist de rutas vendor** en ZIP
  (`noise_path_segments`: ckeditor, plugins, fonts, lib…), saltadas ANTES de consumir
  `max_zip_entries` — sin esto, el caso #37129 (SCORM 41 MB) llenaba los 24k chars con
  samples de CKEditor y texto demo «Apollo 11», y el contenido real (al final del ZIP)
  ni se leía; (b) **reparto equitativo del presupuesto** entre piezas (water-filling)
  en vez de truncado head-first: una fuente ruidosa ya no expulsa la señal de las demás,
  y una fuente única conserva el presupuesto completo; (c) **filtro reforzado** en
  `meaningfulText()`: descarta identificadores de interfaz de herramientas de autor
  (`imagelink_<hash>`, `interface_view_581-001`, `navigationSectionInteracted`,
  `ntx-text-…`) y reglas CSS embebidas (`!important`, selectores) que antes se colaban
  por el filtro JSON.
- **Prompt del destilador guiado por casos reales:** instrucción explícita de **ignorar
  el ruido técnico**; nueva sección estable **«Nivel citado textualmente»** (copia el
  nivel/curso/materia literal o «No consta») que da señal directa a los pasos gruesos y
  al sesgo de inclusividad de etapa (ADR-0010 §Afinado TASK-019); y **few-shot** con 2
  ejemplos reales compactos (#4674 con nivel literal; #3181 Netex ruidoso) en
  `PromptBuilder::DISTILLATION_EXAMPLES`.

Corpus de evaluación en `test/fixtures/distiller-corpus/` (extracto real + ficha de
referencia por item) con el protocolo de medición funcional en contenedor (tasa de
acuerdo ficha↔referencia, criterio ADR-0012). Golden en host: `ContentExtractorTest`
(extracción limpia sobre ZIPs con estructura vendor) y `PromptBuilderTest` (guía + few-shot
presentes; invariantes fiel/no-clasificador intactos). **151 tests verdes, lint PSR-12.**

Guías al propietario derivadas del diagnóstico: activar `vision_enabled` (#40442 es un
PDF escaneado sin capa de texto; #3181 es infográfico → solo señal visual); completar
metadatos mínimos (#4359 no tiene ni título); subir `max_tokens` si la ficha se corta
tras el few-shot. La verificación funcional en contenedor sigue diferida.

## Fuentes

- Brainstorming con el propietario, 2026-06-30.
- Diagnóstico TASK-022 con REAs reales (`localhost:8080`, `resource_class_id=4758`), 2026-07-07.
- Diseño: docs/superpowers/specs/2026-06-30-refactor-contexto-llm-design.md.
- ADR-0008 (conexión LLM), ADR-0010 (anclaje bottom-up), ADR-0007 (IA propone/curador
  confirma), spec de extracción de medios (2026-06-29) §5 (visión).
