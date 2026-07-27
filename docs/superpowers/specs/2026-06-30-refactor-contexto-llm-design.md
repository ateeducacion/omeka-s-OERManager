# Refactor de la capa de contexto del item que se pasa al LLM — diseño

> Estado: aprobado en brainstorming (2026-06-30). Resuelve **TASK-019** y absorbe la
> visión antes diferida a **TASK-012 / RF-012**. Componente de **ALTO RIESGO**
> (extracción del re-catalogador IA); aplica la skill `recatalogador`.

## 1. Problema

La catalogación IA-assistida ensambla hoy el contexto del LLM así:
`IndexController::itemMetadataText()` da un texto de metadatos,
`OmekaMediaSource::filesFor()` da rutas de medios, y `ContentExtractor::extract()` los
**concatena en un único texto plano** truncado por presupuesto. Ese mismo blob se pasa
**idéntico** a ambos clasificadores y a **cada paso** del LLM (etapa, materia, bloques,
saberes, criterios, ejes).

- **P1** Concatenación plana: no distingue metadatos de contenido de medios ni la
  procedencia → entierra la señal.
- **P2** Truncado ciego: como los metadatos van primero, el tope puede descartar el
  contenido del medio.
- **P3** Mismo contexto completo a todos los pasos, aunque cada paso necesite señal
  distinta → caro en tokens y ruidoso.
- **P4** `pdf_unreadable` (PDF escaneado sin capa de texto) e imágenes con la señal real
  (infografías) se descartan: nunca llegan al LLM.

## 2. Objetivos y decisiones tomadas (brainstorming 2026-06-30)

Objetivo: mejor contexto para catalogar (curricular + tags) y **económico en tokens**,
con **prioridad de no perder información** sobre el ahorro.

1. **Dos roles de LLM** (extiende ADR-0008): un **modelo de extracción** barato y
   vision-capable (default sugerido Haiku) + el **modelo clasificador** actual
   (Sonnet/Opus). Mismo proveedor/endpoint/clave por defecto, distinto `model`. Si el
   proveedor no es vision/PDF-capable → la visión se omite con motivo y la propuesta sigue
   con texto.
2. **Destilador fiel** (LLM barato): lee el crudo (metadatos + texto de medios +
   descripciones de visión) y produce una **ficha estructurada** (tema, conceptos clave,
   vocabulario, qué enseña el recurso). **No infiere currículo** — no propone
   etapa/materia/curso salvo que esté literal en el recurso. Conservador: prima la
   fidelidad.
3. **Visión: siempre top-N imágenes + rescate de PDF escaneado** (no condicional). Filtro
   heurístico (descartar `placeholder|sprite|icon|logo|bg|thumb…` + tamaño mínimo; tomar
   las N mayores). **Binario directo al LLM** (sin tooling local): imágenes como bloque
   `image`, PDF escaneado como bloque `document`(pdf) nativo del proveedor. Master toggle
   `vision_enabled` por privacidad (egress de binarios a un tercero).
4. **Contexto por paso:** pasos gruesos (etapa, materia, bloque) → solo la ficha; pasos
   finos (saberes, criterios) → ficha + contenido crudo de medios (completo, acotado por
   presupuesto; "relevante" = el contenido del medio, sin recortes arriesgados).
5. **Objeto de contexto estructurado** (`ItemContext`) sustituye el blob plano: secciones
   con procedencia (metadatos, texto de medios por fuente, descripciones de visión, ficha);
   el truncado protege el contenido de medios.

Se respeta lo ya fijado: mapeo RDF (ADR-0004), grafo (ADR-0009), secuencia bottom-up
(ADR-0010), arquitectura puertos/adaptadores con núcleo puro testeable en host (TASK-010),
y las garantías de seguridad existentes (contenido como dato-no-instrucción, anti
zip-bomb/zip-slip, límites de nodos JSON, sin SSRF, clave write-only nunca logueada).

## 3. Arquitectura

```
IndexController::itemMetadataText(item)  ─┐
OmekaMediaSource::filesFor(item)         ─┤
                                          ▼
            ContentExtractor (texto de medios + JSON, sin LLM)
            MediaVisionExtractor (top-N imágenes + rescate PDF → LLM extracción) [si vision_enabled]
                                          ▼
            ContextDistiller (LLM extracción) → ficha fiel
                                          ▼
                 ItemContext (metadata | mediaText | visionDescriptions | ficha)
                                          ▼
            AiCataloguer enruta contexto POR PASO:
              · pasos gruesos (etapa/materia/bloque) → ficha
              · pasos finos (saberes/criterios)      → ficha + crudo de medios
                                          ▼
            CurricularClassifier / TagClassifier (LLM clasificador) → propuesta
```

- `ContentExtractor` y el filtro de imágenes son **puros** (sin core, sin LLM): TDD en host.
- `MediaVisionExtractor` y `ContextDistiller` usan el **LLM de extracción** (inyectado tras
  `LlmClientInterface`): se prueban con un cliente fake (como los clasificadores).

## 4. Componentes y ficheros

**Cliente LLM multimodal (`src/Service/Llm/`)**
- `LlmClientInterface::chat()` ya recibe `content` libre; `AnthropicClient` lo **reenvía
  tal cual**, así que enviar `content` como **array de bloques** (`text`/`image`/`document`)
  funciona sin cambiar el transporte. Se añade un helper para construir bloques `image`
  (base64 + media_type) y `document` (pdf base64).
- `OpenAiCompatibleClient`: aceptar `content` como array y mapear imágenes a `image_url`;
  PDF **gated** por capacidad (omitir con motivo si el proveedor no lo soporta).
- `LlmSettings`: nuevas constantes `EXTRACTION_MODEL`, `VISION_ENABLED` (default off),
  `VISION_MAX_IMAGES` (default 3).

**Extracción de medios (`src/Service/Content/`)**
- `ContentExtractor`: sin cambios de seguridad.
- `MediaVisionExtractor` (nuevo): filtro heurístico de imágenes (puro) + selección top-N +
  rescate de PDF ilegible → descripciones de texto vía LLM de extracción.
- `OmekaMediaSource`: reconocer imágenes (jpg/png/…) como candidatas de visión, sin
  meterlas en la cascada de texto.
- `ItemContext` (nuevo VO): secciones por procedencia + helpers para el texto de cada tipo
  de paso (grueso vs fino), acotado por presupuesto protegiendo el contenido de medios.

**Clasificación (`src/Service/Ai/`)**
- `ContextDistiller` (nuevo): `LlmClientInterface` (extracción) + `PromptBuilder`; prompt
  fiel-no-clasificador (contenido como dato-no-instrucción); produce la ficha.
- `AiCataloguer`: una extracción + visión opcional + una destilación → `ItemContext`;
  enruta el contexto por paso. Mantiene `debug`/`TraceableInterface` y expone
  `content.sources`/`skipped`/visión en el panel.
- `CurricularClassifier`/`TagClassifier`/`ClassifierInterface`: aceptan `ItemContext` (o un
  selector de contexto) en vez de `string`; pasos gruesos usan la ficha, finos ficha+crudo.

**Glue del core**
- `config/module.config.php`: factorías de `MediaVisionExtractor`, `ContextDistiller`, el
  segundo cliente LLM (extracción, con `EXTRACTION_MODEL`) y el cableado de `AiCataloguer`.
  Reusa el patrón de selección de proveedor existente.
- `Form/ConfigForm.php`: campos de modelo de extracción, `vision_enabled`, `vision_max_images`.

## 5. Fases de implementación (SDD + TDD real en host, lint PSR-12)

1. **ADR-0011** + gobierno a "en curso".
2. **Adaptador multimodal** + `LlmSettings` (tests de payload con transporte fake).
3. **`ItemContext` + enrutado por paso** (tests P1/P2/P3; protección del medio en truncado).
4. **`ContextDistiller`** + segundo modelo (tests con LLM fake: fidelidad, no-inferencia).
5. **`MediaVisionExtractor`** + gating por toggle/proveedor (tests del filtro + llamada fake).
6. **Glue + ConfigForm** + cierre de gobierno; verificación en contenedor diferida.

## 6. Seguridad

- Contenido del recurso como **dato no-instrucción** también en el destilador (marcas
  neutralizadas, igual que `PromptBuilder`).
- Visión = nuevo **egress de medios binarios a un tercero** (privacidad): gobernado por
  `vision_enabled` (off por defecto) + tope `vision_max_images`. Sin SSRF (solo store
  local). Clave nunca logueada.
- Se mantienen anti zip-bomb/zip-slip, límites de nodos JSON y truncado por presupuesto.

## 7. Testing

- Host (gate): `make lint` (PSR-12) + `make test` en verde; cobertura de adaptador
  multimodal, `ItemContext`/enrutado, `ContextDistiller` y filtro de visión (fakes).
- Contenedor (diferido, como TASK-010/015): con el trazado del intercambio LLM, verificar
  en item con PDF escaneado y con imágenes (#3181) que la ficha llega a pasos gruesos, el
  crudo+ficha a saberes/criterios, la visión describe imágenes/PDF, y baja el coste de
  tokens del clasificador.

## 8. Trazabilidad

- **TASK-019** (esta tarea); **ADR-0011** (a redactar). Absorbe **TASK-012 / RF-012**.
- Extiende ADR-0008 (dos modelos), ADR-0010 (destilador/contexto por paso); no cambia
  ADR-0004 (mapeo RDF) ni ADR-0009 (grafo).

## 9. Fuera de alcance

- Prompt caching del proveedor (palanca complementaria; trabajo aparte sobre los adaptadores).
- RAG/embeddings (TASK-016, bloqueada por PEND-010).
- Lote como Job (TASK-011).
