# Extracción enriquecida de contenido de medios — diseño

> Estado: aprobado en brainstorming (2026-06-29). Resuelve **TASK-017** (Fase 1) y prepara **TASK-012 / RF-012** (Fase 2). Componente de **ALTO RIESGO** (extracción del re-catalogador IA); aplica skill `recatalogador`.

## 1. Problema

Durante las pruebas funcionales de TASK-015 (item #3181, SCORM "Partes de la célula", paquete Netex smartbook) el trazado del intercambio LLM confirmó que el clasificador trabaja **solo con los metadatos de Omeka** (título + descripción), no con el contenido del medio:

- El ZIP **sí se lee** (aparece en `sources`), pero todo el contenido útil se descarta por whitelist:
  - `project.json → unsupported:json` — el modelo de contenido Netex vive en JSON.
  - `*.js` de contenido (`2304279631.js`, `1228349175.js`) — fuera de whitelist.
  - **todas** las imágenes `resources/...celula_graficos_NN.png/.jpg` — el infográfico real.
- Del HTML solo sale un shell casi vacío: `PARTES DE LA CÉLULA h1 p` (además con un artefacto `h1 p`, residuo de la extracción de HTML).

Con metadatos buenos la clasificación acertó igualmente; con metadatos pobres fallaría. El contenido del medio debe llegar al clasificador.

## 2. Decisiones tomadas (brainstorming 2026-06-29)

1. **Estrategia JSON: genérica, agnóstica de herramienta.** Extracción recursiva de valores string con heurísticas; sin parser por proveedor (vale para Netex, Articulate, Genially, etc.).
2. **Visión: filtro heurístico + tope N configurable**, con toggle global on/off (RF-012).
3. **Fasing:** Fase 1 (JSON/texto) ya; Fase 2 (visión) diferible como TASK-012/RF-012.
4. **Proveedor de visión:** reusar el `LlmClientInterface` configurado **solo si es vision-capable**; sin config de modelo de visión separado (YAGNI). Si el toggle está on con proveedor sin visión, se omite con aviso.

## 3. Arquitectura: cascada de extracción

Se **extiende** el dispatch existente de `ContentExtractor` (`match($ext)`), no se reescribe. La extracción de texto sigue siendo una clase **pura** (sin core de Omeka, sin LLM) testeable en host con TDD. La visión es un paso aparte porque necesita el LLM.

```
AiCataloguer::propose()
  ├─ ContentExtractor::extract(metadata, files)        → texto (metadatos + medios .txt/.html/.xml/.pdf/.json)
  │     └─ extractZip(): entradas .json → extractJson() (en memoria, mismos límites zip)
  └─ [Fase 2] MediaVisionExtractor::describe(imagePaths) → descripciones (si vision_enabled)
        └─ usa LlmClientInterface (vision-capable)
  → contenido fusionado (texto + descripciones) → clasificadores
```

`ContentExtractor` permanece sin dependencia del LLM; `MediaVisionExtractor` se inyecta en `AiCataloguer` como colaborador opcional.

## 4. Fase 1 — Extracción JSON genérica (TASK-017)

Añadir `json` a la whitelist del extractor y de `OmekaMediaSource`. Nuevo `extractJson(string $bytes, string $name): ?string`:

- `json_decode($bytes, true, $depth)` con `$depth` limitado (default 32) y captura de error → JSON inválido se salta con motivo `json_invalid`.
- Recorrido recursivo del árbol con **tope de nodos** (`max_json_nodes`, default p.ej. 5000) — anti-JSON patológico/profundo; al superarlo se corta y se devuelve lo acumulado.
- Recoge solo **valores string**. Heurística para quedarse con texto real y descartar ruido:
  - longitud mínima (`min_text_len`, default ~25) **o** que contenga varias palabras (espacios);
  - descartar lo que parezca id/código/hash/URL/ruta/clase CSS/clave técnica (sin espacios + patrón de slug/hex/camelCase con dígitos, empieza por `http`, contiene `/` o `.` de extensión, etc.).
- Si un string parece HTML (contiene `<tag>`), pasarlo por el `normalizeText(..., 'html')` existente (strip script/style + strip_tags + decode). **Esto también corrige el artefacto `h1 p`** del HTML suelto.
- Sujeto al presupuesto global `max_total_chars` (truncado compartido).

Dentro del ZIP, las entradas `.json` se enrutan al mismo `extractJson()` (ya se leen por índice en memoria con los topes de tamaño/ratio/conteo vigentes → no cambia la superficie de seguridad del ZIP).

**`.js` queda fuera** de la whitelist en Fase 1: extraer texto limpio de JS minificado es muy ruidoso; si `project.json` resultara insuficiente en pruebas, se reconsidera como follow-up.

## 5. Fase 2 — Cascada de visión (diferible, TASK-012 / RF-012)

Nuevo `MediaVisionExtractor`:

- Entrada: rutas de imagen candidatas del item (las que `OmekaMediaSource`/`ContentExtractor` reconozcan como imagen).
- **Filtro heurístico:** descartar por patrón de nombre (`placeholder|sprite|fondo|creditos?|icon|logo|bg|thumb`…) y por tamaño mínimo en bytes; de las restantes, ordenar por tamaño y tomar las **N mayores** (`vision_max_images`, default 3).
- Llama al `LlmClientInterface` con un prompt "describe el contenido educativo de esta imagen" (imagen como bloque de imagen del proveedor).
- Las descripciones se añaden al contenido como una fuente más (con su entrada en `sources`).
- **Toggle global** `vision_enabled` (default **off**, RF-012). Si on pero el proveedor no es vision-capable → se omite y se registra el motivo (sin romper la propuesta).

El filtro de imágenes es **puro y testeable**; la llamada de visión se prueba con un `LlmClientInterface` fake (como los clasificadores).

## 6. Configuración y límites

Settings nativos / defaults del extractor (conservadores):

| Clave | Fase | Default | Propósito |
| --- | --- | --- | --- |
| whitelist += `json` | 1 | — | habilita JSON |
| `max_json_nodes` | 1 | 5000 | anti-JSON patológico |
| `min_text_len` | 1 | 25 | umbral de "texto real" |
| `vision_enabled` | 2 | false | toggle global (RF-012) |
| `vision_max_images` | 2 | 3 | tope de imágenes a visión |

## 7. Testing

- **Fase 1 (TDD real en host):** fixtures JSON — Netex-like anidado con campos de contenido, JSON con ruido (ids/clases CSS/URLs/rutas) que debe filtrarse, JSON inválido (`json_invalid`), JSON con muchos nodos (corte por `max_json_nodes`), string HTML embebido (strip_tags aplicado), JSON dentro de ZIP. Sin tocar el core.
- **Fase 2:** test del filtro heurístico de imágenes (puro); test de la llamada de visión con `LlmClientInterface` fake; test de gating por toggle y por proveedor sin visión.
- Toda la batería bajo `make lint` (PSR-12) + `make test`.

## 8. Seguridad

- **JSON:** `json_decode` con `depth` limit + tope de nodos en el recorrido (nuevo vector acotado). Bytes ya capados por `max_entry_bytes`; lectura del ZIP en memoria (zip-slip/zip-bomb ya cubiertos, no se tocan).
- **Visión:** introduce un **nuevo egress de medios binarios a un tercero** (privacidad) — gobernado por `vision_enabled` off-by-default. Sin SSRF (imágenes del store local, nunca URLs remotas). La clave API nunca se loguea (garantía existente).
- Coherencia con ADR-0007 (la IA propone, el curador confirma) y ADR-0008 (conexión LLM configurable): no cambia.

## 9. Trazabilidad

- **TASK-017** (Fase 1) — RF-009, RF-010, ADR-0007.
- **TASK-012 / RF-012** (Fase 2, visión) — se activa con esta cascada.
- Mapeo RDF (ADR-0004) y grafo (ADR-0009): **no cambian**.
- No introduce dependencias nuevas (JSON nativo; imágenes vía el cliente LLM existente).
