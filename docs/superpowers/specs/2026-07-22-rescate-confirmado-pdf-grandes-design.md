# Diseño — Rescate confirmado de PDF grandes (TASK-025)

- **Fecha:** 2026-07-22
- **Estado:** aprobado (brainstorming con el propietario)
- **Componente:** capa de contexto del LLM / extracción de medios
- **ADR afectados:** ADR-0011 (capa de contexto, rescate por visión),
  ADR-0007 (IA propone / curador confirma), ADR-0008 (conexión LLM).

## Contexto y motivo

Hallado por el propietario el 2026-07-22 probando «Proponer con IA» sobre un item
con un PDF grande (`IA_alcaravan.pdf`). El panel de debug mostraba
`leídos: (ninguno) | saltados: IA_alcaravan.pdf → pdf_too_large`, una ficha de
**212 chars** (solo el título del item: «Tema: Alcaravan (contenido del recurso).
Conceptos clave: No consta.»), Etapa `[1,2,3,4]` —el sesgo de inclusividad ante
la duda, ADR-0010 §Afinado TASK-019— y Materia y Ejes vacíos.

Diagnóstico confirmado en el código: hay **tres** motivos de salto para un PDF y
`AiCataloguer::rescuablePdfs()` solo rescata dos.

```php
if (!in_array($reason, ['pdf_unreadable', 'pdf_empty'], true)) {
    continue;
}
```

| Motivo | Cuándo | ¿Rescate por visión? | Resultado real |
| --- | --- | --- | --- |
| `pdf_unreadable` | el parser lanza | sí | #4674: ficha correcta, «1º ESO MATEMÁTICAS» |
| `pdf_empty` | el parser devuelve vacío | sí | #40437: ficha de 1.273 chars, alineamiento completo |
| **`pdf_too_large`** | tamaño > `max_pdf_bytes` (20 MB) | **no** | **sin texto y sin visión: sin contexto** |

La causa de fondo es conceptual: **`max_pdf_bytes` es una guarda anti PDF-bomb
del *parseo en memoria*** (endurecimiento de TASK-010), y se estaba aplicando de
hecho a un camino —la visión— que **no parsea nada**: manda el binario al
proveedor. Un límite de memoria local acabó gobernando una decisión de red.

Contexto agravante (TASK-024): en Alpine/musl `iconv //TRANSLIT` no existe, así
que casi todo PDF con texto acaba en `pdf_empty` y **la visión sostiene en
silencio toda la extracción de PDF**. El agujero de `pdf_too_large` es por tanto
más frecuente de lo que parece.

## Principio rector

El curador decide antes de gastar. Enviar un binario grande al proveedor tiene
coste y latencia visibles (NFR-008, TASK-020), así que **no se hace a espaldas
del curador**, coherente con ADR-0007 (la IA propone, el curador confirma).

## Decisiones (aprobadas en brainstorming)

1. **Rescatar `pdf_too_large` por visión, con un tope propio** para el envío al
   LLM, separado del tope de parseo.
2. **`max_pdf_bytes` (20 MB) se queda fijo** y sin exponer: es una guarda de
   seguridad, no un parámetro de rendimiento.
3. **Tope de visión configurable**, `vision_max_pdf_bytes`, default **32 MB**
   (límite documentado de PDF de entrada de Anthropic; valor razonable para
   gateways). Por encima **no se ofrece confirmación**: se informa. No se ofrece
   un botón que se sabe de antemano que va a fallar.
4. **Propose en dos fases**: la 1ª llamada corta **antes de la primera llamada al
   LLM** y devuelve `needs_confirmation`; la 2ª lleva la decisión.
5. **Al rechazar, el propose sigue sin ese PDF** (comportamiento actual, pero por
   fin explicado en la UI).

## Arquitectura y flujo

```
filesFor() [+size]
      │
      ▼
ContentExtractor.extract()      ← sin cambios (sigue marcando pdf_too_large a 20 MB)
      │  skipped: {nombre → motivo}
      ▼
AiCataloguer.classifyOversizePdfs()   ← PURO: cruza motivo + size + tope de visión
      │
      ├── confirmables (20–32 MB)  y decisión = 'ask'  ──► return needs_confirmation
      │                                                    ⚠️ CERO llamadas al LLM
      ├── decisión = 'include' ──► entran en rescuablePdfs() → MediaVisionExtractor
      └── decisión = 'skip'    ──► se ignoran; el propose continúa con el resto
```

El corte ocurre **después** de la extracción local (barata, sin red) y **antes**
de destilación, visión y cascada. En estado `ask` el coste en tokens es cero.

## Estado de la decisión: tri-estado, no booleano

`largePdfDecision`: `ask` (default) / `include` / `skip`.

Un booleano no sirve: `false` sería indistinguible de «aún no he preguntado» y
«el curador dijo que no», y el rechazo volvería a disparar la pregunta en bucle.

## Componentes (unidades con un solo propósito)

### 1. `OmekaMediaSource::filesFor(int $itemId): array`

Cada entrada gana `size` (bytes), como ya hace `imagesFor()`. Es el único punto
que toca el sistema de ficheros; mantiene el orquestador puro y testeable en host
con fuentes falsas.

### 2. `AiCataloguer::classifyOversizePdfs(array $files, array $skipped): array`

**Puro.** Devuelve `['confirmable' => [...], 'tooLarge' => [...]]`, cada entrada
`{name, path, size}`. Criterio: motivo `pdf_too_large` **y** `size <=
visionMaxPdfBytes` → confirmable; `size > visionMaxPdfBytes` → fuera de alcance.
Las entradas internas de un ZIP no tienen ruta propia y se omiten, como ya hace
`rescuablePdfs()`.

### 3. `AiCataloguer::rescuablePdfs()`

Admite `pdf_too_large` **solo** cuando la decisión es `include`. Los motivos
`pdf_unreadable`/`pdf_empty`/`pdf_iconv_unsupported` (este último de TASK-024b)
siguen rescatándose siempre y sin preguntar: son ficheros pequeños, ya dentro del
tope de parseo.

### 3b. `MediaVisionExtractor` — tope de envío unificado

**Descubierto al aterrizar el spec (no estaba previsto):** `MediaVisionExtractor`
ya tenía una constante privada `MAX_PDF_BYTES = 20 MB` que gobierna el envío real
del documento en `binaryBlock()`. Si se dejara intacta, un PDF de 25 MB
confirmado por el curador **se caería en silencio** dentro del extractor
(`$size > $maxBytes → null`) — la función nacería muerta. Por eso el tope de
visión debe ser **una sola fuente de verdad** inyectada en los dos sitios que lo
usan: `MediaVisionExtractor` (que reemplaza su const privada por un parámetro de
constructor `maxPdfBytes`) y `AiCataloguer::classifyOversizePdfs` (que decide
confirmable vs fuera de alcance). La factoría lee el setting una vez y lo pasa a
ambos, así son consistentes por construcción.

### 4. `AiCataloguer::propose(..., string $largePdfDecision = 'ask')`

Tras extraer, clasifica los PDF grandes. Si hay confirmables y la decisión es
`ask`, retorna sin tocar el LLM:

```php
['needs_confirmation' => ['confirmable' => [...], 'tooLarge' => [...]],
 'alignment' => [], 'justifications' => [], 'content' => [...], 'debug' => [...]]
```

En `include`/`skip` el retorno mantiene la forma actual, con `tooLarge` expuesto
en `content` para que el panel pueda explicar los que quedaron fuera.

**Caso sin confirmables:** si solo hay PDF `tooLarge` (ninguno entre 20 y 32 MB),
**no** se devuelve `needs_confirmation` y el propose sigue de largo: no hay nada
que confirmar, porque ninguno es rescatable. Esos ficheros se reportan en
`content` para que el panel los explique. Es decir, `needs_confirmation` aparece
si y solo si hay al menos un PDF **confirmable** y la decisión es `ask`.

### 5. `ConfigForm` / `Module`

Setting `oermanager_llm_vision_max_pdf_bytes`, default `33554432` (32 MB).
Parser puro `LlmSettings::parseVisionMaxPdfBytes()` (entero positivo; vacío o
inválido → default), simétrico a `parseMaxTokens`. Campo en el ConfigForm con
texto de ayuda que distinga explícitamente este tope (envío al proveedor, en MB)
del de parseo (20 MB, guarda de seguridad no expuesta). El valor se inyecta desde
la factoría a `MediaVisionExtractor` (send-gate) y a `AiCataloguer`
(clasificación) — misma fuente, ver §3b.

### 6. `IndexController::aiProposeAction`

Lee `large_pdf` del POST (`ask`/`include`/`skip`; valor desconocido → `ask`) y lo
propaga. CSRF y ACL sin cambios. Devuelve `needs_confirmation` tal cual en el
JSON.

### 7. JS (`asset/js/oer-master-view.js`)

Ante `needs_confirmation`, diálogo con nombre y tamaño legible (MB) y el aviso de
coste/latencia. Aceptar → re-POST con `large_pdf=include`; rechazar → re-POST con
`large_pdf=skip`. Los `tooLarge` se muestran como aviso informativo, sin botón.

## Seguridad

Sin relajar ninguna guarda existente:

- `max_pdf_bytes` (20 MB) **no se toca**: el parseo en memoria sigue acotado.
- Anti zip-bomb / zip-slip, límites de nodos JSON y truncado por presupuesto,
  intactos.
- El binario viaja al proveedor como **dato no-instrucción** (spec §6 de
  ADR-0011); el texto visible se describe, nunca se interpreta como orden.
- La clave nunca se loguea; si el proveedor rechaza el PDF pese al tope, el
  mensaje saneado va al log como en TASK-021.
- **Doble puerta de visión intacta**: con `vision_enabled` off o un proveedor sin
  soporte de PDF no hay rescate posible, luego **no se ofrece confirmación**
  (preguntar por algo que no se puede hacer sería un fallo de UX).

## Coste (NFR-008 / TASK-020)

Rescatar un PDF de 20–32 MB añade **una** llamada de visión con un binario
grande: es el paso más caro y lento del flujo. Por eso se confirma en vez de
hacerse solo, y por eso el corte en estado `ask` no gasta tokens. Agrava la
latencia del propose síncrono → refuerza el caso de TASK-020 (Job en segundo
plano).

## Plan de pruebas (TDD real en host, puertos/adaptadores)

Con LLM falso y fuente de medios falsa:

1. `pdf_too_large` de 25 MB + `ask` → `needs_confirmation` y **el fake LLM
   registra 0 llamadas** (el test que de verdad importa).
2. Mismo caso + `include` → el PDF llega a `MediaVisionExtractor`.
3. Mismo caso + `skip` → no llega, y el propose completa con el resto.
4. Bordes exactos: 20 MB (parsea), 20 MB + 1 byte (confirmable), 32 MB
   (confirmable), 32 MB + 1 byte (`tooLarge`, no confirmable).
5. `pdf_unreadable`/`pdf_empty` pequeños → rescate **sin** confirmación
   (no regresión del comportamiento actual).
6. `vision_enabled` off → sin `needs_confirmation`.
7. Decisión desconocida en el POST → se trata como `ask`.

Glue (controlador + JS) → verificación en contenedor con el arnés de
`test/container/` (`propose-harness.php`), extendido para pasar la decisión.

## Fuera de alcance (YAGNI)

- Trocear el PDF por páginas o mandar solo las N primeras.
- Recordar la decisión entre items o entre sesiones.
- Hacer configurable `max_pdf_bytes` (es una guarda de seguridad).
- Convertir el PDF a imágenes en el servidor (dependencia nueva; el proveedor ya
  acepta PDF nativo).
- El arreglo de `iconv //TRANSLIT` en Alpine → es TASK-024(b), de imagen Docker.

## Gobierno

- Nueva **TASK-025** en `docs/backlog.md`; actualizar `docs/traceability.md` y
  `docs/project-memory.md` al cerrar.
- Afina ADR-0011 (el rescate por visión gana un tramo y una confirmación); no
  cambia ADR-0004 (mapeo RDF) ni ADR-0009 (grafo).
