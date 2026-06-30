# Extracción JSON genérica (TASK-017, Fase 1) — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Que el texto útil de un paquete SCORM (p.ej. el `project.json` de Netex) llegue al clasificador IA, extrayendo valores string significativos de cualquier JSON, de forma genérica y segura.

**Architecture:** Se extiende el dispatch por extensión del `ContentExtractor` (clase pura, sin core ni LLM) para tratar `.json` — tanto como fichero suelto como entrada de un ZIP. Un recorrido recursivo con tope de nodos recoge los valores string y los filtra con heurísticas (descartar ids/rutas/urls/hashes; conservar frases). Toda la lógica se prueba con TDD real en el host. La fuente de medios (`OmekaMediaSource`, glue del core) añade `json` a su whitelist y se verifica en el contenedor.

**Tech Stack:** PHP 8.4, `json_decode` nativo, PHPUnit 11.5, PSR-12 (PHPCS). Sin dependencias nuevas.

## Global Constraints

- Omeka-S **4.2**, PHP **8.4** — sin sintaxis de PHP 8.5.
- Lint **PSR-12**: `make lint`. Tests: `make test`. **Stop hook** corre ambos; el turno no cierra si fallan.
- **Sin análisis estático** (no PHPStan/Psalm). **Sin dependencias nuevas**.
- El `ContentExtractor` debe seguir siendo **puro** (sin core de Omeka, sin LLM): toda su lógica testeable en host.
- No tocar la seguridad ZIP existente (zip-slip, zip-bomb, ratio, conteo, sin recursión).
- Mapeo RDF (ADR-0004) y grafo (ADR-0009): no cambian. Componente de ALTO RIESGO (skill `recatalogador`).

---

### Task 1: Extracción JSON genérica en ContentExtractor

**Files:**
- Modify: `src/Service/Content/ContentExtractor.php`
- Test: `test/Service/Content/ContentExtractorTest.php`

**Interfaces:**
- Consumes: `ContentExtractor::extract(string $metadataText, array $files): ExtractedContent` (existente), `ExtractedContent::text()`, `::skipped()`, `::sources()`.
- Produces: tratamiento de extensión `json` en `extractFile()` y en `extractZip()`; nuevos límites `max_json_nodes` (default 5000) y `min_text_len` (default 25) en `DEFAULTS`; `json` añadido a `DEFAULTS['whitelist']`. Métodos privados nuevos: `extractJsonFile(string $path, string $name): ?string`, `parseJson(string $bytes, string $name): ?string`, `collectJsonStrings(mixed $node, array &$out, int &$nodes, int $maxNodes): void`, `meaningfulText(string $raw): ?string`.

- [ ] **Step 1: Escribir los tests que fallan**

Añadir estos métodos al final de la sección de tests de `test/Service/Content/ContentExtractorTest.php` (antes de `// --- helpers ---`):

```php
    public function testJsonContentValuesAreExtracted(): void
    {
        $json = json_encode([
            'title' => 'Partes de la célula',
            'pages' => [
                ['text' => 'Identificación de la célula como unidad estructural y funcional.'],
                ['text' => 'Diferenciación entre célula procariota y eucariota.'],
            ],
        ]);
        $file = $this->tempFile('project.json', (string) $json);
        $content = (new ContentExtractor())->extract('', [['path' => $file]]);
        $this->assertStringContainsString('unidad estructural y funcional', $content->text());
        $this->assertStringContainsString('procariota y eucariota', $content->text());
        $this->assertContains('project.json', $content->sources());
    }

    public function testJsonTechnicalNoiseIsFiltered(): void
    {
        $json = json_encode([
            'id' => '581_573_215_0',
            'asset' => 'resources/celula_graficos_30.jpg',
            'url' => 'https://example.com/x',
            'hash' => 'a630fdcd12abcdef',
            'cssClass' => 'panel_view',
            'body' => 'Valoración de la importancia de la célula como unidad de vida.',
        ]);
        $file = $this->tempFile('p.json', (string) $json);
        $content = (new ContentExtractor())->extract('', [['path' => $file]]);
        $this->assertStringContainsString('importancia de la célula', $content->text());
        $this->assertStringNotContainsString('581_573_215_0', $content->text());
        $this->assertStringNotContainsString('celula_graficos_30.jpg', $content->text());
        $this->assertStringNotContainsString('example.com', $content->text());
        $this->assertStringNotContainsString('a630fdcd12abcdef', $content->text());
    }

    public function testHtmlInsideJsonStringIsStripped(): void
    {
        $json = json_encode(['html' => '<h1>Geología</h1><p>Rocas y minerales del entorno</p>']);
        $file = $this->tempFile('h.json', (string) $json);
        $content = (new ContentExtractor())->extract('', [['path' => $file]]);
        $this->assertStringContainsString('Rocas y minerales del entorno', $content->text());
        $this->assertStringNotContainsString('<h1>', $content->text());
    }

    public function testInvalidJsonIsSkippedGracefully(): void
    {
        $file = $this->tempFile('broken.json', '{not valid json,,,');
        $content = (new ContentExtractor())->extract('meta', [['path' => $file]]);
        $this->assertStringContainsString('meta', $content->text());
        $this->assertArrayHasKey('broken.json', $content->skipped());
        $this->assertSame('json_invalid', $content->skipped()['broken.json']);
    }

    public function testJsonNodeBudgetIsCapped(): void
    {
        $values = [];
        for ($i = 0; $i < 2000; $i++) {
            $values[] = 'Frase de contenido educativo número ' . $i . ' sobre la célula.';
        }
        $file = $this->tempFile('big.json', (string) json_encode($values));
        $extractor = new ContentExtractor(['max_json_nodes' => 50]);
        $content = $extractor->extract('', [['path' => $file]]);
        // No revienta y respeta el tope: no aparece la última frase.
        $this->assertStringNotContainsString('número 1999', $content->text());
    }

    public function testJsonEntryInsideZipIsExtracted(): void
    {
        $json = json_encode(['lesson' => 'Comparación de los niveles de organización de la materia viva.']);
        $zip = $this->tempZip('scorm.zip', [
            'project.json' => (string) $json,
            'index.html' => '<p>shell</p>',
        ]);
        $content = (new ContentExtractor())->extract('', [['path' => $zip]]);
        $this->assertStringContainsString('niveles de organización de la materia viva', $content->text());
    }
```

- [ ] **Step 2: Ejecutar los tests para verificar que fallan**

Run: `make test`
Expected: FAIL — los nuevos tests fallan (el `.json` se salta como `unsupported:json`, así que el texto no aparece y `sources` no contiene `project.json`).

- [ ] **Step 3: Implementar la extracción JSON**

En `src/Service/Content/ContentExtractor.php`:

(a) Añadir los límites y `json` a `DEFAULTS` (dentro del array, junto a los existentes):

```php
        'max_pdf_bytes' => 20971520, // 20 MB
        // Tope de nodos del recorrido JSON (anti-JSON patológico/profundo).
        'max_json_nodes' => 5000,
        // Longitud mínima para aceptar un string suelto (sin varias palabras).
        'min_text_len' => 25,
        'whitelist' => ['txt', 'html', 'htm', 'xml', 'pdf', 'json'],
```

(b) En `extractFile()`, añadir el caso `json` al `match`:

```php
        return match ($ext) {
            'pdf' => $this->extractPdfFile($path, $name),
            'zip' => $this->extractZip($path, $name),
            'json' => $this->extractJsonFile($path, $name),
            'txt', 'html', 'htm', 'xml' => $this->readTextFile($path, $ext),
            default => $this->skipReturn($name, 'unsupported:' . $ext),
        };
```

(c) En `extractZip()`, sustituir la línea de routing del texto de la entrada:

```php
            $text = 'pdf' === $ext ? $this->parsePdf($bytes, $entryName) : $this->normalizeText($bytes, $ext);
```

por:

```php
            $text = match ($ext) {
                'pdf' => $this->parsePdf($bytes, $entryName),
                'json' => $this->parseJson($bytes, $entryName),
                default => $this->normalizeText($bytes, $ext),
            };
```

(d) Añadir los métodos nuevos (p.ej. justo después de `readTextFile()`):

```php
    private function extractJsonFile(string $path, string $name): ?string
    {
        $bytes = file_get_contents($path, false, null, 0, (int) $this->limits['max_entry_bytes']);
        if (false === $bytes) {
            $this->skip($name, 'unreadable');
            return null;
        }
        return $this->parseJson($bytes, $name);
    }

    /**
     * Extrae los valores string significativos de un JSON (cualquier herramienta).
     * JSON inválido se salta; el recorrido está acotado por nº de nodos.
     */
    private function parseJson(string $bytes, string $name): ?string
    {
        try {
            $data = json_decode($bytes, true, 32, JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            $this->skip($name, 'json_invalid');
            return null;
        }
        $out = [];
        $nodes = 0;
        $this->collectJsonStrings($data, $out, $nodes, (int) $this->limits['max_json_nodes']);
        $text = trim($this->normalizeWhitespace(implode("\n", $out)));
        if ('' === $text) {
            $this->skip($name, 'json_empty');
            return null;
        }
        return $text;
    }

    /**
     * Recorre el árbol JSON recogiendo strings, con tope de nodos visitados.
     *
     * @param mixed $node
     * @param string[] $out
     */
    private function collectJsonStrings(mixed $node, array &$out, int &$nodes, int $maxNodes): void
    {
        if ($nodes++ >= $maxNodes) {
            return;
        }
        if (is_array($node)) {
            foreach ($node as $value) {
                $this->collectJsonStrings($value, $out, $nodes, $maxNodes);
            }
            return;
        }
        if (is_string($node)) {
            $text = $this->meaningfulText($node);
            if (null !== $text) {
                $out[] = $text;
            }
        }
    }

    /**
     * ¿El string es texto de contenido (no un id/ruta/url/hash/nombre de fichero)?
     * Devuelve el texto saneado (HTML stripped) o null si es ruido técnico.
     */
    private function meaningfulText(string $raw): ?string
    {
        $value = trim($raw);
        if ('' === $value) {
            return null;
        }
        if (1 === preg_match('/<[a-z][^>]*>/i', $value)) {
            $value = trim($this->normalizeText($value, 'html'));
            if ('' === $value) {
                return null;
            }
        }
        // Descartar tokens técnicos.
        if (
            1 === preg_match('#^https?://#i', $value)
            || str_contains($value, '/')
            || 1 === preg_match('/^[0-9a-f]{8,}$/i', $value)
            || 1 === preg_match('/^[\w.-]+\.(png|jpe?g|gif|svg|css|js|json|woff2?|ttf|eot|mp[34]|html?|xml|xsd|dtd)$/i', $value)
        ) {
            return null;
        }
        $words = preg_split('/\s+/', $value) ?: [];
        $multiWord = count(array_filter($words, static fn (string $w): bool => mb_strlen($w) > 1)) >= 2;
        if (!$multiWord && mb_strlen($value) < (int) $this->limits['min_text_len']) {
            return null;
        }
        return $value;
    }
```

- [ ] **Step 4: Ejecutar los tests para verificar que pasan**

Run: `make test`
Expected: PASS — los 6 tests nuevos pasan y los existentes siguen verdes.

- [ ] **Step 5: Lint**

Run: `make lint`
Expected: sin violaciones PSR-12.

- [ ] **Step 6: Commit**

```bash
git add src/Service/Content/ContentExtractor.php test/Service/Content/ContentExtractorTest.php
git commit -m "feat(content): extracción JSON genérica en ContentExtractor (TASK-017)

Recorrido recursivo acotado por nodos que recoge valores string de cualquier
JSON, filtrando ruido técnico (ids/rutas/urls/hashes/nombres de fichero) y
saneando HTML embebido. Trata .json como fichero suelto y como entrada de ZIP,
sin tocar la seguridad ZIP existente. TDD real en host.

Co-Authored-By: Claude Opus 4.8 <noreply@anthropic.com>"
```

---

### Task 2: Whitelist `json` en OmekaMediaSource (glue del core)

**Files:**
- Modify: `src/Service/Content/OmekaMediaSource.php:19`

**Interfaces:**
- Consumes: nada nuevo. `OmekaMediaSource::filesFor(int $itemId): array` ya filtra por `self::WHITELIST`.
- Produces: `json` incluido en `OmekaMediaSource::WHITELIST`, de modo que un medio `.json` suelto del item se entregue al extractor (el JSON dentro de un `.zip` ya llega vía la entrada ZIP de la Task 1).

> Nota: `OmekaMediaSource` depende del core de Omeka (ApiManager, Store) y **no es testeable en el host** (limitación del arnés, TASK-008). Este cambio se verifica en el contenedor, no con PHPUnit.

- [ ] **Step 1: Añadir `json` a la whitelist**

En `src/Service/Content/OmekaMediaSource.php`, sustituir:

```php
    private const WHITELIST = ['txt', 'html', 'htm', 'xml', 'pdf', 'zip'];
```

por:

```php
    private const WHITELIST = ['txt', 'html', 'htm', 'xml', 'pdf', 'zip', 'json'];
```

- [ ] **Step 2: Lint**

Run: `make lint`
Expected: sin violaciones PSR-12.

- [ ] **Step 3: Commit**

```bash
git add src/Service/Content/OmekaMediaSource.php
git commit -m "feat(content): aceptar medios .json en OmekaMediaSource (TASK-017)

El extractor ya sabe parsear JSON (Task 1); habilitar también el .json suelto
como medio del item. Verificación funcional en el contenedor (glue del core).

Co-Authored-By: Claude Opus 4.8 <noreply@anthropic.com>"
```

---

### Task 3: Verificación funcional en el contenedor

**Files:** ninguno (verificación).

- [ ] **Step 1: Reclasificar el item #3181 (SCORM Netex)**

Recarga forzada del admin (el JS sale cacheado como `?v=0.0.0`) y vuelve a pulsar "Proponer con IA". En la consola, comprobar en `[Extracción de medios]` que `project.json` ya **no** aparece en `saltados` por `unsupported:json`, y que `[Contenido enviado al LLM]` incluye texto real del recurso (saberes/criterios sobre la célula), no solo los metadatos.

- [ ] **Step 2: Cerrar el residuo de TASK-017 en gobernanza**

Actualizar `docs/backlog.md` (TASK-017 → `hecha`, Fase 1) y `docs/project-memory.md` con el resultado de la verificación. Dejar constancia de que la Fase 2 (visión, TASK-012/RF-012) queda como tarea aparte con su spec ya escrito.

---

## Notas de alcance

- El artefacto `h1 p` que se vio en el HTML del SCORM es un residuo menor de la extracción de HTML suelto; para el item #3181 el contenido real viene del `project.json`, así que no se aborda en esta fase (no merece tarea). Si reaparece como problema, se trata aparte.
- `.js` queda **fuera** de la whitelist en Fase 1 (texto de JS minificado demasiado ruidoso). Reconsiderar solo si `project.json` resulta insuficiente en la verificación.
- La **Fase 2 (visión)** tiene su diseño en `docs/superpowers/specs/2026-06-29-extraccion-contenido-medios-design.md §5` y se planifica como TASK-012/RF-012.
