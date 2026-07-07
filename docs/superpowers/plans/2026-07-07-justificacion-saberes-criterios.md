# Justificación de saberes/criterios — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** que el clasificador produzca una justificación breve por saber/criterio y que se persista como value-annotation (`dcterms:description`) sobre el valor del alineamiento.

**Architecture:** cambios aditivos sobre la cadena existente (ADR-0010). Los pasos finos del `CurricularClassifier` piden `{"i":n,"why":"…"}`; un parser degradante extrae índice+porqué; la justificación viaja en paralelo (`getJustifications()` → `AiCataloguer` → controlador → chip oculto → apply) y `RecatalogService::apply()` la anota. El mapa de alineamiento, preview/apply, scorer e integridad no cambian de forma.

**Tech Stack:** PHP 8.4, PHPUnit (`make test`), PHPCS PSR-12 (`make lint`), jQuery (admin JS).

## Global Constraints

- PHP **8.4** (sin sintaxis 8.5). Lint PSR-12 (`make lint`), líneas ≤ 120 chars.
- Núcleo puro/testeable en host; glue del core (RecatalogService/IndexController/JS) verificado en contenedor.
- Contenido/salida del LLM = **dato no-instrucción** (spec §6). Justificación literal acotada ~200 chars.
- Vocabularios RDF permitidos: dcterms/lrmi/schema (ADR-0002). Property de anotación: `dcterms:description`.
- Alcance: **solo** `lrmi:teaches` y `lrmi:assesses`.

---

### Task 1: `ResponseParser::parseSelections()` — índice→justificación, degradante

**Files:**
- Modify: `src/Service/Ai/ResponseParser.php`
- Test: `test/Service/Ai/ResponseParserTest.php`

**Interfaces:**
- Produces: `parseSelections(string $text): array<int,string>` — mapa índice 1-based → justificación (`''` si falta). Descarta índices ≤0, duplicados (primero gana) y no numéricos. `parseIndices()` intacto.

- [ ] **Step 1 — tests (rojo):** añadir a `ResponseParserTest`:

```php
public function testParseSelectionsReadsIndexAndReason(): void
{
    $p = new ResponseParser();
    $r = $p->parseSelections('{"selected":[{"i":2,"why":"trata la fotosíntesis"},{"i":5,"why":"células"}]}');
    $this->assertSame([2 => 'trata la fotosíntesis', 5 => 'células'], $r);
}

public function testParseSelectionsToleratesMissingReason(): void
{
    $p = new ResponseParser();
    $this->assertSame([3 => ''], $p->parseSelections('{"selected":[{"i":3}]}'));
}

public function testParseSelectionsFallsBackToBareIntegers(): void
{
    // El modelo ignoró la instrucción y devolvió enteros: no se pierde la selección.
    $p = new ResponseParser();
    $this->assertSame([1 => '', 4 => ''], $p->parseSelections('{"selected":[1,4]}'));
}

public function testParseSelectionsDropsInvalidAndDuplicates(): void
{
    $p = new ResponseParser();
    $r = $p->parseSelections('{"selected":[{"i":0,"why":"x"},{"i":2,"why":"a"},{"i":2,"why":"b"},{"i":-1}]}');
    $this->assertSame([2 => 'a'], $r); // 0/-1 fuera; primer 2 gana
}
```

- [ ] **Step 2 — verificar rojo:** `make test` → fallan por método inexistente.

- [ ] **Step 3 — implementar** en `ResponseParser`:

```php
/**
 * Igual que parseIndices pero conservando la justificación por índice
 * (pasos finos, TASK-023). Degradante: acepta {"i":n,"why":"…"}, tolera
 * "why" ausente y enteros pelados (el modelo puede ignorar la instrucción);
 * nunca se pierde una selección por el formato.
 *
 * @return array<int,string> índice 1-based => justificación ('' si falta)
 */
public function parseSelections(string $text): array
{
    $data = $this->decodeObject($text);
    if (!is_array($data) || !isset($data['selected']) || !is_array($data['selected'])) {
        return [];
    }
    $out = [];
    foreach ($data['selected'] as $item) {
        $index = null;
        $why = '';
        if (is_array($item)) {
            $raw = $item['i'] ?? null;
            if (is_int($raw)) {
                $index = $raw;
            } elseif (is_string($raw) && 1 === preg_match('/^-?\d+$/', trim($raw))) {
                $index = (int) trim($raw);
            }
            $why = is_string($item['why'] ?? null) ? trim($item['why']) : '';
        } elseif (is_int($item)) {
            $index = $item;
        } elseif (is_string($item) && 1 === preg_match('/^-?\d+$/', trim($item))) {
            $index = (int) trim($item);
        }
        if (null !== $index && $index > 0 && !array_key_exists($index, $out)) {
            $out[$index] = $why;
        }
    }
    return $out;
}
```

- [ ] **Step 4 — verificar verde:** `make test` → pasan.
- [ ] **Step 5 — commit:** `git add -A && git commit -m "feat(ai): ResponseParser::parseSelections índice+justificación (TASK-023)"`

---

### Task 2: `PromptBuilder::buildSelectionPrompt($withReason)` — pedir justificación en pasos finos

**Files:**
- Modify: `src/Service/Ai/PromptBuilder.php`
- Test: `test/Service/Ai/PromptBuilderTest.php`

**Interfaces:**
- Consumes: firma actual `buildSelectionPrompt(string $label, array $candidates, string $content, int $maxSelections = 0, string $guidance = '')`.
- Produces: nuevo 6º parámetro `bool $withReason = false`. Con él, el contrato de salida es `{"selected":[{"i":n,"why":"…"}]}` y se pide justificación ≤ ~15 palabras. Sin él, contrato actual `{"selected":[n]}` inalterado.

- [ ] **Step 1 — tests (rojo):**

```php
public function testSelectionPromptWithReasonAsksForWhy(): void
{
    $p = (new PromptBuilder())->buildSelectionPrompt('Saberes básicos', ['A', 'B'], 'c', 0, '', true);
    $this->assertStringContainsString('"i"', $p['user'] . $p['system']);
    $this->assertStringContainsString('"why"', $p['user'] . $p['system']);
    $this->assertMatchesRegularExpression('/justific|motivo|por qué/u', mb_strtolower($p['system']));
}

public function testSelectionPromptWithoutReasonKeepsPlainContract(): void
{
    $p = (new PromptBuilder())->buildSelectionPrompt('Curso', ['A'], 'c', 1);
    $this->assertStringNotContainsString('"why"', $p['user'] . $p['system']);
    $this->assertStringContainsString('selected', $p['user'] . $p['system']);
}
```

- [ ] **Step 2 — verificar rojo:** `make test`.

- [ ] **Step 3 — implementar:** añadir `bool $withReason = false` a la firma; cuando es true, sustituir la línea de contrato JSON por la variante con objetos y añadir al system una instrucción de justificación breve. Contrato por defecto sin tocar. (Reutilizar el patrón de `$cardinality`/instrucciones ya presente.)

Ejemplo de instrucción a inyectar en el system cuando `$withReason`:
`'Para cada candidato elegido añade una justificación BREVE (una frase, ≤15 palabras, en español) de por qué corresponde al recurso, sin repetir su enunciado.'`
y el contrato de salida pasa a: `{"selected":[{"i": n, "why": "motivo"}, ...]}`.

- [ ] **Step 4 — verificar verde:** `make test`; `make lint`.
- [ ] **Step 5 — commit:** `git commit -am "feat(ai): PromptBuilder pide justificación en pasos finos (TASK-023)"`

---

### Task 3: `CurricularClassifier` — pasos finos con justificación + `getJustifications()`

**Files:**
- Modify: `src/Service/Ai/CurricularClassifier.php`
- Test: `test/Service/Ai/CurricularClassifierTest.php`

**Interfaces:**
- Consumes: `ResponseParser::parseSelections()` (Task 1), `PromptBuilder::buildSelectionPrompt(..., true)` (Task 2).
- Produces: `getJustifications(): array<string,array<int,string>>` — `{ 'lrmi:teaches': {itemId => texto}, 'lrmi:assesses': {…} }`, poblado en `classify()`, reseteado al inicio de `classify()`. Solo esas dos dimensiones.

- [ ] **Step 1 — test (rojo):** el fake LLM devuelve el formato nuevo en los pasos de saberes/criterios; comprobar el mapeo a itemId:

```php
public function testCollectsJustificationsForLeavesByItemId(): void
{
    $llm = new FakeLlmClient([
        '{"selected":[1]}',                                   // Etapa
        '{"selected":[1]}',                                   // Materia
        '{"selected":[{"i":2,"why":"trata ecuaciones"}]}',  // Saberes → id 31
        '{"selected":[{"i":1,"why":"resuelve ecuaciones"}]}',// Criterios → id 40
    ]);
    $classifier = $this->make($this->resolver(), $llm);
    $classifier->classify(new ItemContext('recurso de ecuaciones', ''));
    $j = $classifier->getJustifications();
    $this->assertSame('trata ecuaciones', $j['lrmi:teaches'][31]);
    $this->assertSame('resuelve ecuaciones', $j['lrmi:assesses'][40]);
}

public function testJustificationsResetAcrossClassifyCalls(): void
{
    $llm = new FakeLlmClient([
        '{"selected":[1]}', '{"selected":[1]}',
        '{"selected":[{"i":2,"why":"x"}]}', '{"selected":[]}',
        '{"selected":[1]}', '{"selected":[1]}',
        '{"selected":[]}', '{"selected":[]}',
    ]);
    $c = $this->make($this->resolver(), $llm);
    $c->classify(new ItemContext('a', ''));
    $c->classify(new ItemContext('b', ''));
    $this->assertSame([], $c->getJustifications()['lrmi:teaches'] ?? []);
}
```

- [ ] **Step 2 — verificar rojo:** `make test`.

- [ ] **Step 3 — implementar:**
  - Añadir `private array $justifications = [];` y `getJustifications(): array { return $this->justifications; }`.
  - Al inicio de `classify()`: `$this->justifications = [];`.
  - Que `selectRows()` acepte un flag/variante que use `buildSelectionPrompt(..., true)` y `parseSelections()`, devolviendo tanto las filas como el mapa índice→why; el clasificador traduce índice→itemId (usando la fila elegida, que tiene `id`) y guarda `$this->justifications[$dimension][$itemId] = $why`. Aplicar solo en los dos pasos finos (saberes en Fase B, criterios en Fase C). El pre-filtro por bloque y los pasos gruesos siguen con `ask()`/`parseIndices()`.

  Nota de implementación: `ask()` hoy hace `parseIndices()`. Introducir un método hermano `askWithReasons(array $candidates, string $label, string $content): array` que devuelva `array<int,string>` (índice→why) usando `buildSelectionPrompt(..., true)` + `parseSelections()`, y trazar igual (TraceableInterface). `selectRows()` para saberes/criterios usa este método, mapea a filas con `mapIndicesToRows(array_keys($map), $candidates)` y rellena `$this->justifications` cruzando cada fila con su `why` por índice.

- [ ] **Step 4 — verificar verde:** `make test`; `make lint`.
- [ ] **Step 5 — commit:** `git commit -am "feat(ai): CurricularClassifier expone justificaciones de saberes/criterios (TASK-023)"`

---

### Task 4: `AiCataloguer::propose()` — exponer `justifications`

**Files:**
- Modify: `src/Service/Ai/AiCataloguer.php`
- Test: `test/Service/Ai/AiCataloguerTest.php`

**Interfaces:**
- Consumes: `CurricularClassifier::getJustifications()` (Task 3).
- Produces: la clave `'justifications'` en el array de retorno de `propose()`: `{ 'lrmi:teaches': {id=>texto}, 'lrmi:assesses': {…} }` (vacío si el clasificador no las expone).

- [ ] **Step 1 — test (rojo):** con un clasificador fake que exponga `getJustifications()`, comprobar que `propose()['justifications']` las incluye. (Reutilizar el patrón del `AiCataloguerTest` existente; el fake curricular debe implementar el método.)

- [ ] **Step 2 — verificar rojo:** `make test`.

- [ ] **Step 3 — implementar:** en `propose()`, tras clasificar:

```php
'justifications' => method_exists($this->curricular, 'getJustifications')
    ? $this->curricular->getJustifications()
    : [],
```

(añadido al array de retorno; documentar en el `@return`).

- [ ] **Step 4 — verificar verde:** `make test`; `make lint`.
- [ ] **Step 5 — commit:** `git commit -am "feat(ai): AiCataloguer expone justifications en propose (TASK-023)"`

---

### Task 5: `RecatalogService::apply()` — anotar `dcterms:description` (contenedor)

**Files:**
- Modify: `src/Service/RecatalogService.php`

**Interfaces:**
- Consumes: mapa `justifications` (Task 4) reenviado por el controlador (Task 6).
- Produces: `apply(int $itemId, array $proposed, string $contributor, array $justifications = []): array` (4º arg opcional, backward-compatible). Para valores de `lrmi:teaches`/`lrmi:assesses` con justificación, `@annotation` gana `dcterms:description`.

> Sin test host (depende de `ApiManager` del core). Verificación en contenedor.

- [ ] **Step 1 — implementar:**
  - Firma: añadir `array $justifications = []`.
  - `buildValues()` recibe la porción `{id=>texto}` de la dimensión (o `[]`) y pasa a `annotation()` la justificación del `$targetId` (acotada a 200 chars) solo si el término es `lrmi:teaches`/`lrmi:assesses`.
  - `annotation()` acepta un `?string $reason = null`; si no es null/'' , añade `dcterms:description` (literal) al mapa además de contributor/modified/provenance.
- [ ] **Step 2 — lint:** `make lint`.
- [ ] **Step 3 — commit:** `git commit -am "feat(ai): RecatalogService anota justificación como dcterms:description (TASK-023)"`

---

### Task 6: `IndexController` — devolver y recoger justificaciones (contenedor)

**Files:**
- Modify: `src/Controller/Admin/IndexController.php`

**Interfaces:**
- Consumes: `propose()['justifications']` (Task 4); POST `justification[term][id]`.
- Produces: `aiProposeAction` JSON gana `justifications`; la acción `recatalog-apply` recoge `justification[term][id]` (solo teaches/assesses, literal acotado) y lo pasa como 4º arg a `apply()`.

> Sin test host (toca el core). Verificación en contenedor.

- [ ] **Step 1 — implementar:**
  - `aiProposeAction`: añadir `'justifications' => $proposal['justifications'] ?? []` al `JsonModel`.
  - Nuevo helper `collectJustifications(): array<string,array<int,string>>` que lee `$this->params()->fromPost('justification', [])`, filtra a `['lrmi:teaches','lrmi:assesses']`, castea ids a int y acota cada texto a 200 chars.
  - En la acción `recatalog-apply` (donde se llama a `apply(...)`), pasar `$this->collectJustifications()` como 4º arg.
- [ ] **Step 2 — lint:** `make lint`.
- [ ] **Step 3 — commit:** `git commit -am "feat(ai): IndexController transporta justificaciones propose→apply (TASK-023)"`

---

### Task 7: JS — chip oculto `data-justification` y payload del apply (contenedor)

**Files:**
- Modify: `asset/js/oer-master-view.js`

**Interfaces:**
- Consumes: `response.justifications` (Task 6); chips con `data-justification`.
- Produces: `justification[term][id]` en el POST del apply para teaches/assesses.

> Verificación en contenedor/manual (patrón del proyecto).

- [ ] **Step 1 — implementar:**
  - `buildSearchChoice(id, title, justification)`: si hay justificación, `.attr('data-justification', justification)` (no se muestra).
  - `applyAiProposal()`: para `lrmi:teaches`/`lrmi:assesses`, pasar `(response.justifications[term]||{})[candidate.id]` al construir el chip.
  - En el armado del payload del apply (≈ líneas 286-297), para esas dos dimensiones, por cada chip con `data-justification`, empujar `{ name: 'justification['+term+']['+id+']', value: <texto> }`.
- [ ] **Step 2 — lint JS (si aplica) y revisión manual.**
- [ ] **Step 3 — commit:** `git commit -am "feat(ai): panel transporta justificación oculta al apply (TASK-023)"`

---

### Task 8: Gobierno

**Files:**
- Modify: `docs/backlog.md` (TASK-023 → hecha host), `docs/traceability.md`, `docs/project-memory.md`, `docs/decisions/0002-*.md` (nota: la anotación gana `dcterms:description`).

- [ ] **Step 1:** actualizar los cuatro documentos (estado, enlaces, nota de afinado de la anotación).
- [ ] **Step 2 — commit:** `git commit -am "docs(ai): cierre TASK-023 (justificación de saberes/criterios)"`

## Notas de verificación

- Host: `make lint` + `make test` (Tasks 1-4 con TDD real).
- Contenedor (diferido): propose sobre un item real → el JSON trae `justifications`; confirmar en el panel → el JSON-LD del item muestra `dcterms:description` en el `@annotation` de los valores de saberes/criterios, junto a contributor/modified/provenance.
