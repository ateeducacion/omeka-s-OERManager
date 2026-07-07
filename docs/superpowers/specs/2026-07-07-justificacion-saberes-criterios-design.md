# Diseño — Justificación de saberes/criterios en el clasificador (TASK-023)

- **Fecha:** 2026-07-07
- **Estado:** aprobado (brainstorming con el propietario)
- **Componente:** clasificador curricular (ALTO RIESGO — skill `recatalogador`)
- **ADR afectados:** ADR-0007 (IA propone/curador confirma), ADR-0010 (bottom-up),
  ADR-0002 (auditoría RDF vía value annotations), ADR-0011 (capa de contexto).

## Contexto y motivo

El clasificador propone saberes (`lrmi:teaches`) y criterios (`lrmi:assesses`)
eligiéndolos por su descripción (Fase B/C de ADR-0010); hoy el LLM devuelve solo
índices (`{"selected":[n,...]}`) y no explica **por qué** eligió cada hoja. El
propietario quiere que cada saber/criterio propuesto lleve una **justificación
breve** que se **persista como value-annotation** sobre el valor del alineamiento,
igual que la auditoría de la re-catalogación (quién/cuándo/qué, ADR-0002). La
justificación **no** se muestra en el panel (decisión del propietario): viaja
oculta con la propuesta y se materializa al confirmar.

Curso (`lrmi:educationalLevel`) y materia (`schema:about`) se **derivan** de las
hojas (Fase D, ADR-0010): no hay selección del LLM que justificar ahí, así que la
justificación es **solo de saberes y criterios**.

## Principio rector

Cambios **aditivos**. El mapa de alineamiento (`term → [ids]`), el preview/apply,
el `EvaluationScorer` y el chequeo de integridad **no cambian de forma**. La
justificación viaja en una estructura paralela y se materializa como un literal
más dentro de la anotación de auditoría que `RecatalogService::apply` ya escribe.

## Decisiones (defaults aprobados)

1. **Persistencia:** value-annotation sobre el valor del saber/criterio, como la
   auditoría actual.
2. **Property de la anotación:** `dcterms:description` (distinta de
   `dcterms:provenance`, que ya expresa el «qué»). Vocabulario permitido (ADR-0002).
3. **Camino de escritura:** el `propose` genera la justificación; el `apply` (tras
   confirmación del curador, ADR-0007) la escribe. Como son dos peticiones y el
   servidor no retiene la propuesta, la justificación **viaja oculta con la
   propuesta a través del panel** hasta el POST del apply.
4. **Sin UI:** la justificación no se renderiza; solo viaja como dato oculto.

## Arquitectura y flujo

```
CurricularClassifier.classify()
  ├─ pasos finos (saberes, criterios): prompt pide {"i":n,"why":"…"}
  │   └─ ResponseParser::parseSelections() → {índice → porqué}
  └─ getJustifications(): term → {itemId → texto}   (patrón TraceableInterface)
        │
AiCataloguer.propose() → añade 'justifications' al resultado
        │
IndexController::aiProposeAction() → JSON { alignment, justifications, content, debug }
        │
JS applyAiProposal() → data-justification (oculto) en el chip del saber/criterio
        │
JS collectRecatalogPayload() → POST justification[term][id]  (+ alignment[term][])
        │
IndexController::collectAlignment()/recatalogApply → pasa el mapa a apply()
        │
RecatalogService::apply() → @annotation += dcterms:description (solo teaches/assesses)
```

## Componentes (unidades con un solo propósito)

### 1. `ResponseParser::parseSelections(string $text): array<int,string>`
- Devuelve mapa **índice 1-based → justificación**. Reutiliza `decodeObject()`
  (endurecido: extrae el objeto JSON aunque venga en prosa o fences).
- **Degradante (nunca se pierde una selección por el formato):**
  - Forma nueva `{"i":n,"why":"…"}` → índice n con su porqué.
  - Falta `why` o vacío → índice con justificación `''`.
  - Entero pelado `n` (el modelo ignoró la instrucción) → índice con `''`.
  - Índices `≤0`, duplicados y tipos no numéricos se descartan (igual que hoy).
- `parseIndices()` se mantiene **intacto** para los pasos gruesos.

### 2. `PromptBuilder::buildSelectionPrompt(..., bool $withReason = false)`
- Nuevo flag opcional (aditivo, backward-compatible como el `guidance` de TASK-019).
- Con `$withReason`: el contrato de salida pasa a
  `{"selected":[{"i":n,"why":"motivo breve"}]}` y se instruye **justificación de
  ≤ ~15 palabras, una frase**, en español, sin repetir el enunciado del candidato.
- Sin el flag: contrato actual `{"selected":[n]}` sin cambios.

### 3. `CurricularClassifier`
- `selectRows()` (saberes y criterios) usa el modo `$withReason=true` y
  `parseSelections()`; mapea los índices elegidos a filas (como hoy) y guarda el
  porqué por **itemId** de la hoja elegida.
- Nuevo estado `private array $justifications` y accesor
  `getJustifications(): array<string,array<int,string>>` (paralelo a `getTrace()`),
  poblado en `classify()` solo para `lrmi:teaches`/`lrmi:assesses`. Se **resetea al
  inicio de `classify()`** (autocontenido, sin depender de `clearTrace()`).
- El pre-filtro por bloque y los pasos gruesos **no** piden justificación.

### 4. `AiCataloguer::propose()`
- Tras clasificar, si el clasificador curricular expone `getJustifications()`,
  añade `'justifications' => …` al array de retorno. Estructura:
  `{ 'lrmi:teaches': {id: texto, …}, 'lrmi:assesses': {…} }`.
- No aplica a `TagClassifier` (ejes) en esta entrega.

### 5. `IndexController`
- `aiProposeAction`: incluye `justifications` en el `JsonModel` (los ids se
  corresponden con los de `alignment`, ya enriquecidos).
- `collectAlignment()` (o un helper hermano `collectJustifications()`): recoge del
  POST `justification[term][id]` **solo** para `lrmi:teaches`/`lrmi:assesses` y lo
  pasa a `recatalogService->apply()` como cuarto argumento.
- Trata el texto como **dato**: lo acota en longitud (~200 chars) antes de pasarlo.

### 6. `RecatalogService::apply(int $itemId, array $proposed, string $contributor, array $justifications = [])`
- Firma con cuarto parámetro **opcional** (backward-compatible; el preview no cambia).
- `buildValues()` recibe el mapa `{id → texto}` de la dimensión; para cada valor de
  `lrmi:teaches`/`lrmi:assesses` con justificación, `annotation()` añade
  `dcterms:description` (literal) además de contributor/modified/provenance.
- Valores sin justificación (saberes añadidos a mano, otras dimensiones) → sin
  `dcterms:description`. Re-aplicar reescribe la anotación (como hoy).

### 7. JS (`asset/js/oer-master-view.js`)
- `applyAiProposal()`: al inyectar un chip de `lrmi:teaches`/`lrmi:assesses`, si
  hay justificación, la guarda en el chip como `data-justification` (oculto).
- El código JS que arma el payload del apply (el que empuja los pares
  `alignment[term][]`, ≈ líneas 286-297 de `oer-master-view.js`): añade
  `justification[term][id]` leyendo el `data-justification` de cada chip de esas
  dos dimensiones. Chips sin el dato → no emiten justificación.
- **No** se renderiza el texto en ningún sitio (decisión del propietario).

## Seguridad

- La justificación es texto del LLM → **dato no-instrucción** (spec §6). Se escribe
  como **literal** en la anotación (no como marcado): sin riesgo de inyección en el
  grafo.
- El POST es manipulable, pero la justificación solo alimenta la **nota de
  auditoría**; la integridad del alineamiento la garantiza `invalidTargets()`
  (validación de destino por dimensión), que no cambia. Longitud acotada (~200
  chars) para no inflar el grafo.
- Clave del proveedor nunca se registra (sin cambios).

## Coste (NFR-008 / TASK-020)

Solo **2 pasos** (saberes, criterios) piden texto y **breve**; el resto de la
cascada no cambia. El propose sigue síncrono: la solución de fondo del 504 sigue
siendo el Job (TASK-020). `max_tokens` ya es configurable (ADR-0012) por si la
salida con justificaciones se corta.

## Plan de pruebas (TDD real en host, puertos/adaptadores)

- `ResponseParserTest`: forma nueva; falta `why`; entero pelado (degradación);
  índices inválidos/duplicados; prosa/fences alrededor.
- `CurricularClassifierTest`: `getJustifications()` poblado solo para
  teaches/assesses; mapeo índice→itemId correcto; pasos gruesos sin justificación;
  degradación cuando el LLM no da `why`.
- `RecatalogServiceTest`: `apply()` con justificaciones escribe `dcterms:description`
  en el `@annotation` de teaches/assesses y **no** en las demás dimensiones ni en
  valores sin justificación; backward-compat sin el 4º argumento.
- `AiCataloguerTest`: `propose()` expone `justifications`.
- JS: `data-justification` viaja al payload; chips sin dato no lo emiten.
- Verificación funcional en **contenedor** diferida (como TASK-010/015): escritura
  real de la anotación y lectura del JSON-LD del item.

## Fuera de alcance (YAGNI)

- Justificación para etapa/materia/curso (derivados o solo acotadores).
- Justificación de ejes (`dcterms:relation`).
- Mostrar la justificación en el panel o en la vista maestra.
- Reversibilidad extendida de la anotación (sigue en TASK-007).

## Gobierno

- **TASK-023** en `docs/backlog.md` (nueva).
- Al cerrar: `docs/traceability.md`, `docs/project-memory.md`; nota de afinado en
  ADR-0010/ADR-0002 si procede (la anotación gana un campo `dcterms:description`).
