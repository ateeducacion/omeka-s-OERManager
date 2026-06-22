# ADR-0008: Conexión LLM configurable por proveedor

## Estado

Aceptado (2026-06-22)

## Contexto

La catalogación IA-assistida (ADR-0007, RF-009) necesita llamar a un modelo de lenguaje. El propietario decidió (plan mode, 2026-06-22) que el proveedor sea **configurable**: un modelo local, Anthropic, o cualquier endpoint OpenAI-compatible. Hay que decidir cómo se abstrae el proveedor, cómo se transporta la petición y dónde vive la clave API, respetando las reglas del repo (sin secretos en repo/código/logs; sin dependencias nuevas sin autorización).

## Decisión

1. **Abstracción por adaptadores.** Interfaz `LlmClientInterface` con dos adaptadores: `AnthropicClient` (Messages API) y `OpenAiCompatibleClient` (cubre modelo local y OpenAI-compatible, que comparten contrato de API). El proveedor activo se elige por configuración.
2. **Transporte HTTP con `Laminas\Http\Client`** (incluido en el core de Omeka): **sin dependencia composer nueva** para la conexión LLM.
3. **Configuración en settings nativos de Omeka** (`Omeka\Settings`), editable en la página de configuración del módulo: proveedor, base URL/endpoint, modelo y clave API.
4. **La clave API nunca se expone:** no se escribe en el repo, ni en el código, ni en `CLAUDE.md`, ni en logs; el formulario de configuración no la devuelve en claro una vez guardada. (CLAUDE.md §Seguridad y límites.)
5. **Modelos por defecto razonables**, consultando la skill `claude-api` para los IDs vigentes al implementar el adaptador Anthropic; el modelo concreto es configurable.

## Consecuencias

- El módulo pasa a depender, en runtime, de un servicio externo (el LLM) cuando la asistencia IA está activa; si no está configurado, el re-catalogador manual (4a) funciona igual.
- La única dependencia composer nueva del conjunto TASK-004 es `smalot/pdfparser` (extracción de PDF, ADR-0007), **no** la conexión LLM. Su instalación requiere autorización del propietario (regla CLAUDE.md).
- Pruebas de la conexión LLM: se verifican manualmente en el contenedor (la limitación del arnés de tests impide instanciar clases que tocan el core; igual que TASK-003/005).

## Fuentes

- Decisiones de plan mode con el propietario, 2026-06-22 (TASK-004).
- ADR-0007 (catalogación IA-assistida).
- CLAUDE.md (seguridad: sin secretos; dependencias requieren confirmación).
