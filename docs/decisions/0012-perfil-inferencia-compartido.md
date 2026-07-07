# ADR-0012: Perfil de inferencia compartido entre proveedores LLM

## Estado

Aceptado (2026-07-02)

## Contexto

Probando TASK-019 con **el mismo modelo** por dos caminos — API de Anthropic directa y OpenRouter (gateway OpenAI-compatible) — los resultados divergen, especialmente la **ficha destilada** (y con ella las selecciones posteriores). La causa no está en los pesos del modelo sino en la petición: el módulo solo enviaba `model`, `messages`, `system` y `max_tokens=1024` fijo, dejando `temperature`, `top_p` y el razonamiento al **default de cada proveedor**. Con `temperature≈1` (default de ambos) la salida fluctúa incluso dentro del mismo proveedor (ya observado en TASK-018: item #3181 clasificado Primaria en una pasada y ESO en otra); además OpenRouter puede traer el razonamiento **activado por defecto** según el modelo (`default_enabled`), y sus tokens de thinking consumen `max_tokens` → JSON truncado → selección vacía silenciosa (el parser no reintenta).

## Decisión

1. **Perfil de inferencia único y explícito**, configurado por el admin y enviado **idéntico** en todos los pasos (destilación, visión, cascada curricular, ejes) y por **ambos adaptadores**: `oermanager_llm_temperature` y `oermanager_llm_max_tokens` (settings nativos, `LlmSettings`).
2. **Temperatura vacía = no enviar** (queda el default del proveedor): necesario porque los modelos Claude actuales de gama alta (**Sonnet 5, Opus 4.6+, Fable 5**) tienen los sampling params eliminados y rechazan `temperature` con 400 — verificado en las pruebas del 2026-07-02 con `claude-sonnet-5`; Haiku 4.5 y Sonnet 4.6 sí la aceptan. Validación en `LlmSettings::parseTemperature()` (numérica, rango 0–2; el subconjunto común entre proveedores es 0–1). Recomendación operativa: 0–0.2 **solo con modelos que la aceptan**; con Sonnet 5/Opus 4.6+/Fable 5 dejarla en blanco — la paridad se mantiene por omisión (ambos caminos usan el default del proveedor, 1.0) y el apagado de reasoning en OpenRouter sigue aplicando.
3. **Razonamiento apagado explícitamente en OpenRouter** (`reasoning: {"effort":"none"}`, sintaxis documentada por OpenRouter), detectando el gateway por el host de la base URL. **Solo** a OpenRouter: los endpoints genéricos (vLLM, Ollama…) pueden rechazar parámetros no estándar. Paridad con Anthropic directo, donde el thinking está apagado salvo petición explícita.
4. **Trazabilidad de los parámetros efectivos**: cada paso registra `llm_options` (max_tokens, temperature, json) en su trace (`TraceableInterface`), visible en el panel de debug, para poder comparar qué se envió realmente por cada camino.
5. **No se persigue identidad bit-a-bit**: sin `seed` en Anthropic y con inferencia no determinista en servidor es inalcanzable. El criterio de éxito es la **tasa de acuerdo** entre ejecuciones y entre proveedores (medible repitiendo el propose sobre los mismos items).

## Consecuencias

- La divergencia entre proveedores se reduce a lo irreducible; la ficha destilada y las selecciones se vuelven repetibles con temperatura baja.
- ~~Divergencia funcional: sin rescate de PDF vía gateway~~ **Cerrada para OpenRouter (2026-07-02):** en las pruebas del destilador, esta divergencia producía fichas de <700 caracteres vía OpenRouter frente a >4000 vía Anthropic en items cuyo contenido vive en un PDF escaneado (el rescate por visión solo corría en Anthropic). OpenRouter documenta soporte de PDF de entrada (content part `file` con data URL base64; procesado nativo del modelo cuando lo soporta, p. ej. los Claude), así que `OpenAiCompatibleClient` ahora traduce el bloque neutral `document` a ese part y `supportsPdf()` devuelve `true` **solo con OpenRouter** (misma detección por host que el apagado de reasoning). En endpoints OpenAI-compatibles genéricos (vLLM, Ollama…) el documento se sigue omitiendo y el gating por capacidad lo refleja en el trace.
- `response_format: json_object` sigue siendo asimétrico (solo camino OpenAI); la paridad estructural (json_schema estricto + prefill/tool-choice en Anthropic) queda como contingencia futura si persisten fallos de formato.
- `max_tokens` pasa a ser configurable (default 1024): si la ficha llega cortada, subirlo desde la config sin tocar código.
- **Observabilidad (añadida tras el incidente del 2026-07-02):** `IndexController::aiProposeAction` registra en el log de Omeka el mensaje saneado de toda `LlmException` (status + mensaje del proveedor, nunca la clave) antes de devolver el código genérico `llm` al front; antes la excepción se tragaba en silencio y un 400 de parámetros era indiagnosticable. El mensaje de UI remite al log.

## Fuentes

- Pruebas funcionales de TASK-019 con Anthropic directo vs OpenRouter (2026-07-02, propietario).
- Documentación de OpenRouter (parámetros y reasoning: `default_enabled`, `effort: none`; defaults `temperature=1.0`, `top_p=1.0`).
- ADR-0008 (conexión LLM configurable), ADR-0011 (capa de contexto), TASK-018 (evidencia de no-determinismo).
