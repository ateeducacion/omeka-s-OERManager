# Clasificador Semántico Curricular *Bottom-Up* — Implementation Plan

> **Para ejecución agentica:** REQUIRED SUB-SKILL: Use `superpowers:subagent-driven-development` (recomendado) o `superpowers:executing-plans` para implementar tarea a tarea. Los pasos usan checkbox (`- [ ]`) para tracking.

**Goal:** Eliminar las propuestas incoherentes del clasificador IA sustituyendo la cascada top-down (que adivina el curso y propaga el error) por **anclaje semántico bottom-up**: delimitar la materia, seleccionar saberes/criterios por su `dcterms:description`, y **derivar** curso+asignatura de las hojas elegidas (coherencia garantizada por construcción).

**Architecture:** Fases A→D. (A) el LLM delimita Etapa y **familia de materia** (nombre, sin fijar curso); (B/C) recupera todos los saberes/criterios de esa materia **cruzando cursos** y el LLM los selecciona sobre descripciones enriquecidas; (D) el Curso (`lrmi:educationalLevel`) y la Asignatura (`schema:about`) se **derivan** como los padres reales de las hojas seleccionadas. Se conserva un pre-filtro por bloque temático (E2) para acotar el conjunto cuando es grande. Arquitectura de puertos/adaptadores existente intacta; `TermResolverInterface` es el punto de extensión para embeddings (roadmap).

**Tech Stack:** PHP 8.4, Omeka-S 4.2, PHPUnit (`make test`, `test/phpunit.xml`), PSR-12 (`make lint`/`make fix`). Tests sin red ni core vía `FakeLlmClient`/`FakeTermResolver`.

## Global Constraints

- **PHP 8.4** — sin sintaxis 8.5.
- **`make lint` (PSR-12) en verde** — stop hook activo, el turno no cierra si falla.
- **`make test` (PHPUnit) en verde** — TDD real; la suite ya existe (66+ tests).
- **No tablas Doctrine** (NFR-002). **Extender el core, no parchear** (NFR-001; hook PreToolUse bloquea escrituras fuera del repo).
- **No romper TASK-004** (re-catalogador manual comparte `CurriculumSearch`): los cambios en `mapResults` son **aditivos** (consumidores leen por clave); los métodos nuevos no tocan `searchDimension`/`searchEtapas`/`searchAxes`.
- **`RecatalogService` no cambia**: sigue recibiendo `{dimension => int[]}`. Las claves derivadas `lrmi:educationalLevel`/`schema:about` fluyen igual.
- **Resolver por término, nunca por property_id** (específicos de instalación, ADR-0004/0009).
- **Anti prompt-injection**: el contenido del REA va entre marcas como dato no-instrucción (no regresionar).

## Mapa de archivos

| Archivo | Cambio | Test |
| --- | --- | --- |
| `src/Service/Ai/PromptBuilder.php` | `buildSelectionPrompt` acepta candidatos string\|ricos; `formatCandidate()` | `test/Service/Ai/PromptBuilderTest.php` (añadir) |
| `src/Service/CurriculumSearch.php` | `mapResults` enriquecido; `searchSubjectFamilies()`, `searchLeaves()`; helpers `firstLiteralValue/firstResourceRef/resourceRefMatchingTitle` | lint + manual (requiere ApiManager) |
| `src/Service/Ai/TermResolverInterface.php` | `listSubjectFamilies()`, `listLeaves()` | — |
| `src/Service/Ai/CurriculumTermResolver.php` | implementar los dos métodos nuevos | — |
| `test/Service/Ai/FakeTermResolver.php` | implementar los dos métodos nuevos | (es test helper) |
| `src/Service/Ai/IndexSelection.php` | `mapIndicesToRows()` | vía classifier test |
| `src/Service/Ai/CurricularClassifier.php` | reescribir `classify()` a fases A→D + derivación + pre-filtro bloque | `test/Service/Ai/CurricularClassifierTest.php` (reescribir) |
| `src/Service/Ai/TagClassifier.php` | pasar candidatos ricos | `test/Service/Ai/TagClassifierTest.php` (verificar) |
| `docs/` (ADR-0010, PEND-010, TASK-015/016, traceability, memory) | gobernanza | — |

---

## Task 1: PromptBuilder — candidatos enriquecidos (E1)

**Files:**
- Modify: `src/Service/Ai/PromptBuilder.php`
- Test: `test/Service/Ai/PromptBuilderTest.php`

**Interfaces:**
- Consumes: nada nuevo.
- Produces: `buildSelectionPrompt(string $label, array $candidates, string $content, int $maxSelections=0): array{system:string,user:string}` donde cada elemento de `$candidates` puede ser un `string` (título) **o** `array{title:string, description?:string, block?:string, courseTitle?:string, ...}`. Formato de línea rica: `[courseTitle · block] description (title)`.

- [ ] **Step 1: Escribir el test que falla**

Añade a `test/Service/Ai/PromptBuilderTest.php`:

```php
    public function testFormatsRichCandidatesWithDescriptionBlockAndCourse(): void
    {
        $prompt = (new PromptBuilder())->buildSelectionPrompt(
            'Saberes básicos',
            [[
                'id' => 30,
                'title' => 'SBIG01SBI.1',
                'description' => 'Aproximación a los pasos del método científico.',
                'block' => 'I. Proyecto científico',
                'courseTitle' => '1º ESO',
            ]],
            'recurso sobre método científico',
            0
        );
        // La descripción semántica y el contexto (curso · bloque) deben aparecer;
        // el código viaja entre paréntesis para trazabilidad.
        $this->assertStringContainsString('Aproximación a los pasos del método científico.', $prompt['user']);
        $this->assertStringContainsString('1º ESO', $prompt['user']);
        $this->assertStringContainsString('I. Proyecto científico', $prompt['user']);
        $this->assertStringContainsString('(SBIG01SBI.1)', $prompt['user']);
    }

    public function testRichCandidateWithoutDescriptionFallsBackToTitle(): void
    {
        // Etapas/cursos/asignaturas/ejes: título ya legible, sin description.
        $prompt = (new PromptBuilder())->buildSelectionPrompt(
            'Materia (asignatura)',
            [['title' => 'Biología y Geología', 'description' => '', 'block' => '']],
            'contenido',
            1
        );
        $this->assertStringContainsString('1. Biología y Geología', $prompt['user']);
    }
```

- [ ] **Step 2: Ejecutar y verificar que falla**

Run: `make test`
Expected: FAIL (los dos tests nuevos; el resto en verde).

- [ ] **Step 3: Implementar formatCandidate y adaptar buildSelectionPrompt**

En `src/Service/Ai/PromptBuilder.php`, añade el método privado (antes de `buildSelectionPrompt`):

```php
    /**
     * Formatea un candidato para la lista numerada (E1). Si tiene description
     * semántica (distinta del título), la muestra precedida del contexto
     * "[curso · bloque]" y con el código entre paréntesis; si no, solo el título
     * (etapas, cursos, asignaturas, ejes — ya legibles).
     *
     * @param array<string,mixed> $c
     */
    private function formatCandidate(array $c): string
    {
        $title = trim((string) ($c['title'] ?? ''));
        $desc = trim((string) ($c['description'] ?? ''));
        $block = trim((string) ($c['block'] ?? ''));
        $course = trim((string) ($c['courseTitle'] ?? ''));

        if ('' !== $desc && $desc !== $title) {
            $prefixParts = array_filter([$course, $block], static fn (string $p): bool => '' !== $p);
            $line = '' !== $prefixParts ? '[' . implode(' · ', $prefixParts) . '] ' . $desc : $desc;
            return '' !== $title ? $line . " ({$title})" : $line;
        }
        return $title;
    }
```

Reemplaza el bucle de construcción de la lista dentro de `buildSelectionPrompt` (las líneas que hacen `foreach (array_values($candidates) as $i => $title)`) por:

```php
        $list = '';
        foreach (array_values($candidates) as $i => $candidate) {
            $formatted = is_array($candidate) ? $this->formatCandidate($candidate) : (string) $candidate;
            $list .= sprintf("%d. %s\n", $i + 1, $formatted);
        }
        if ('' === $list) {
            $list = "(sin candidatos)\n";
        }
```

Actualiza el docblock del parámetro `$candidates`:

```php
     * @param array<int,string|array<string,mixed>> $candidates títulos o candidatos
     *   ricos {title, description?, block?, courseTitle?}
```

- [ ] **Step 4: Ejecutar y verificar que pasa**

Run: `make test`
Expected: PASS (toda la suite, incluidos los dos tests nuevos y los existentes con `string[]`).

- [ ] **Step 5: Lint**

Run: `make lint`
Expected: PASS.

- [ ] **Step 6: Commit**

```bash
git add src/Service/Ai/PromptBuilder.php test/Service/Ai/PromptBuilderTest.php
git commit -m "feat(ai): candidatos enriquecidos con descripción semántica (E1)

buildSelectionPrompt acepta candidatos ricos {title, description, block,
courseTitle} además de strings. formatCandidate muestra
'[curso · bloque] descripción (código)' para hojas curriculares y solo el
título para nodos ya legibles (etapas, cursos, materias, ejes).

Co-Authored-By: Claude Opus 4.8 <noreply@anthropic.com>"
```

---

## Task 2: CurriculumSearch — recuperación por materia (cross-grade) + linaje

**Files:**
- Modify: `src/Service/CurriculumSearch.php`

**Interfaces:**
- Produces:
  - `searchSubjectFamilies(int $etapaId, int $limit=self::RESULT_LIMIT): array<int,array{name:string}>` — nombres distintos de materia de la etapa (canónico = `schema:about`).
  - `searchLeaves(string $dimension, int $etapaId, string $subjectName, int $limit=self::RESULT_LIMIT): array<int,array{id:int,title:string,description:string,block:string,courseId:int,courseTitle:string,subjectId:int}>` — saberes (`lrmi:teaches`) o criterios (`lrmi:assesses`) de la materia cruzando cursos, con linaje.
  - `mapResults` enriquecido (aditivo): añade `description`, `block` a `{id,title}`.
- Consumes: `Omeka\Api\Manager`, `ItemRepresentation` (core).

**Nota TDD:** `CurriculumSearch` depende del `ApiManager` del core → no es testeable en host (igual que hoy: no tiene test unitario). Verificación por `make lint` + e2e (Task 6). La lógica pura ya está cubierta por los tests del clasificador vía el fake.

- [ ] **Step 1: Añadir helpers de extracción**

En `src/Service/CurriculumSearch.php`, añade antes de `mapResults()`:

```php
    private function firstLiteralValue($item, string $term): string
    {
        foreach ($item->value($term, ['all' => true, 'default' => []]) as $v) {
            if ('literal' === $v->type()) {
                return (string) $v->value();
            }
        }
        return '';
    }

    /** @return array{id:int,title:string} */
    private function firstResourceRef($item, string $term): array
    {
        foreach ($item->value($term, ['all' => true, 'default' => []]) as $v) {
            $res = $v->valueResource();
            if (null !== $res) {
                return ['id' => (int) $res->id(), 'title' => (string) $res->displayTitle()];
            }
        }
        return ['id' => 0, 'title' => ''];
    }

    /**
     * De las referencias resource de $term, el id cuyo título coincide con $title.
     * Para un criterio, schema:inDefinedTermSet apunta a {Competencia, Asignatura};
     * la Asignatura es la que tiene por título el nombre de la materia (la
     * Competencia es un código). Fallback: primera referencia.
     */
    private function resourceRefMatchingTitle($item, string $term, string $title): int
    {
        $first = 0;
        foreach ($item->value($term, ['all' => true, 'default' => []]) as $v) {
            $res = $v->valueResource();
            if (null === $res) {
                continue;
            }
            $rid = (int) $res->id();
            if (0 === $first) {
                $first = $rid;
            }
            if (trim((string) $res->displayTitle()) === trim($title)) {
                return $rid;
            }
        }
        return $first;
    }
```

- [ ] **Step 2: Enriquecer mapResults (aditivo)**

Reemplaza `mapResults()` por:

```php
    /**
     * @param iterable $items
     * @return array<int,array{id:int,title:string,description:string,block:string}>
     */
    private function mapResults($items): array
    {
        $results = [];
        foreach ($items as $item) {
            $results[] = [
                'id' => $item->id(),
                'title' => (string) $item->displayTitle(),
                'description' => $this->firstLiteralValue($item, 'dcterms:description'),
                'block' => $this->firstLiteralValue($item, 'dcterms:subject'),
            ];
        }
        return $results;
    }
```

- [ ] **Step 3: Añadir searchSubjectFamilies()**

Añade el método público (junto a `searchEtapas`):

```php
    /**
     * Nombres distintos de materia (asignatura) de una etapa, para la
     * delimitación gruesa de la IA (Fase A.2). No fija curso: agrupa por nombre
     * canónico (schema:about, fallback título). Acota por etapa + tipo (NFR-004).
     *
     * @return array<int,array{name:string}>
     */
    public function searchSubjectFamilies(int $etapaId, int $limit = self::RESULT_LIMIT): array
    {
        if ($etapaId <= 0) {
            return [];
        }
        $typeValue = trim((string) $this->settings->get(self::TYPE_SETTINGS['schema:about']));
        if ('' === $typeValue) {
            return [];
        }
        $query = [
            'property' => [
                ['property' => self::TYPE_TERM, 'type' => 'eq', 'text' => $typeValue],
                ['property' => 'dcterms:isPartOf', 'type' => 'res', 'text' => (string) $etapaId],
            ],
            'sort_by' => 'title',
            'sort_order' => 'asc',
            'per_page' => $limit,
        ];
        $names = [];
        foreach ($this->api->search('items', $query)->getContent() as $item) {
            $name = $this->firstLiteralValue($item, 'schema:about');
            if ('' === $name) {
                $name = trim((string) $item->displayTitle());
            }
            if ('' !== $name) {
                $names[$name] = true;
            }
        }
        return array_map(static fn (string $n): array => ['name' => $n], array_keys($names));
    }
```

- [ ] **Step 4: Añadir searchLeaves()**

Añade el método público:

```php
    /**
     * Saberes/criterios de una MATERIA cruzando todos sus cursos (Fase B/C), con
     * linaje (courseId/subjectId) para la derivación bottom-up (Fase D). Acota por
     * etapa + nombre de materia (schema:about) + tipo; nunca carga el árbol
     * completo (NFR-004).
     *
     * @return array<int,array{id:int,title:string,description:string,block:string,courseId:int,courseTitle:string,subjectId:int}>
     */
    public function searchLeaves(
        string $dimension,
        int $etapaId,
        string $subjectName,
        int $limit = self::RESULT_LIMIT
    ): array {
        if (!isset(self::TYPE_SETTINGS[$dimension]) || $etapaId <= 0 || '' === trim($subjectName)) {
            return [];
        }
        $typeValue = trim((string) $this->settings->get(self::TYPE_SETTINGS[$dimension]));
        if ('' === $typeValue) {
            return [];
        }
        $query = [
            'property' => [
                ['property' => self::TYPE_TERM, 'type' => 'eq', 'text' => $typeValue],
                ['property' => 'schema:about', 'type' => 'eq', 'text' => trim($subjectName)],
                ['property' => 'dcterms:isPartOf', 'type' => 'res', 'text' => (string) $etapaId],
            ],
            'sort_by' => 'title',
            'sort_order' => 'asc',
            'per_page' => $limit,
        ];
        $results = [];
        foreach ($this->api->search('items', $query)->getContent() as $item) {
            $course = $this->firstResourceRef($item, 'lrmi:educationalAlignment');
            $results[] = [
                'id' => (int) $item->id(),
                'title' => (string) $item->displayTitle(),
                'description' => $this->firstLiteralValue($item, 'dcterms:description'),
                'block' => $this->firstLiteralValue($item, 'dcterms:subject'),
                'courseId' => $course['id'],
                'courseTitle' => $course['title'],
                'subjectId' => $this->resourceRefMatchingTitle($item, self::IN_TERMSET_TERM, $subjectName),
            ];
        }
        return $results;
    }
```

- [ ] **Step 5: Lint**

Run: `make lint`
Expected: PASS.

- [ ] **Step 6: Commit**

```bash
git add src/Service/CurriculumSearch.php
git commit -m "feat: recuperación de saberes/criterios por materia cross-grade + linaje

Nuevos searchSubjectFamilies() (nombres de materia de una etapa, sin fijar
curso) y searchLeaves() (saberes/criterios de una materia cruzando cursos,
con courseId/courseTitle/subjectId para la derivación bottom-up). mapResults
ahora incluye description y block (aditivo, no rompe TASK-004).

Co-Authored-By: Claude Opus 4.8 <noreply@anthropic.com>"
```

---

## Task 3: Extender el puerto TermResolverInterface + adaptador + fake

**Files:**
- Modify: `src/Service/Ai/TermResolverInterface.php`
- Modify: `src/Service/Ai/CurriculumTermResolver.php`
- Modify: `test/Service/Ai/FakeTermResolver.php`

**Interfaces:**
- Produces (en `TermResolverInterface`):
  - `listSubjectFamilies(int $etapaId): array<int,array{name:string}>`
  - `listLeaves(string $dimension, int $etapaId, string $subjectName): array<int,array{id:int,title:string,description:string,block:string,courseId:int,courseTitle:string,subjectId:int}>`
  - `listCandidates(string $dimension, array $context=[]): array` se mantiene (usado para `etapa` y `dcterms:relation`).

- [ ] **Step 1: Añadir métodos al interface**

En `src/Service/Ai/TermResolverInterface.php`, dentro de la interfaz, añade tras `listCandidates`:

```php
    /**
     * Nombres distintos de materia (asignatura) de una etapa (Fase A.2): delimita
     * la materia sin fijar el curso.
     *
     * @return array<int,array{name:string}>
     */
    public function listSubjectFamilies(int $etapaId): array;

    /**
     * Saberes ('lrmi:teaches') o criterios ('lrmi:assesses') de una materia
     * cruzando todos sus cursos (Fase B/C), con linaje para derivar curso+materia.
     *
     * @return array<int,array{id:int,title:string,description:string,block:string,courseId:int,courseTitle:string,subjectId:int}>
     */
    public function listLeaves(string $dimension, int $etapaId, string $subjectName): array;
```

- [ ] **Step 2: Implementar en CurriculumTermResolver**

En `src/Service/Ai/CurriculumTermResolver.php`, añade tras `listCandidates`:

```php
    public function listSubjectFamilies(int $etapaId): array
    {
        return $this->search->searchSubjectFamilies($etapaId, self::ENUM_LIMIT);
    }

    public function listLeaves(string $dimension, int $etapaId, string $subjectName): array
    {
        return $this->search->searchLeaves($dimension, $etapaId, $subjectName, self::ENUM_LIMIT);
    }
```

- [ ] **Step 3: Implementar en FakeTermResolver**

Reemplaza el contenido de `test/Service/Ai/FakeTermResolver.php` por:

```php
<?php

declare(strict_types=1);

namespace OERManager\Test\Service\Ai;

use OERManager\Service\Ai\TermResolverInterface;

/**
 * Resolutor de términos falso para TDD del flujo bottom-up: candidatos por
 * dimensión (etapa/ejes), familias de materia por etapa, y hojas por dimensión.
 * Registra las llamadas para verificar la delimitación y la derivación.
 */
final class FakeTermResolver implements TermResolverInterface
{
    /** @var array<string,array<int,array<string,mixed>>> */
    public array $byDimension;
    /** @var array<int,array<int,array{name:string}>> */
    public array $families = [];
    /** @var array<string,array<int,array<string,mixed>>> */
    public array $leaves = [];
    /** @var array<int,array<string,mixed>> */
    public array $calls = [];

    /** @param array<string,array<int,array<string,mixed>>> $byDimension */
    public function __construct(array $byDimension = [])
    {
        $this->byDimension = $byDimension;
    }

    public function listCandidates(string $dimension, array $context = []): array
    {
        $this->calls[] = ['dimension' => $dimension, 'context' => $context];
        return $this->byDimension[$dimension] ?? [];
    }

    public function listSubjectFamilies(int $etapaId): array
    {
        $this->calls[] = ['subjectFamilies' => $etapaId];
        return $this->families[$etapaId] ?? [];
    }

    public function listLeaves(string $dimension, int $etapaId, string $subjectName): array
    {
        $this->calls[] = ['leaves' => $dimension, 'etapa' => $etapaId, 'subject' => $subjectName];
        return $this->leaves[$dimension] ?? [];
    }
}
```

- [ ] **Step 4: Ejecutar la suite (rojo esperado en CurricularClassifierTest)**

Run: `make test`
Expected: el código compila; `CurricularClassifierTest` aún usa el flujo viejo → fallará/quedará obsoleto (se reescribe en Task 4). `PromptBuilderTest`, `TagClassifierTest`, `ModuleConfigTest` deben pasar.

- [ ] **Step 5: Lint**

Run: `make lint`
Expected: PASS.

- [ ] **Step 6: Commit**

```bash
git add src/Service/Ai/TermResolverInterface.php src/Service/Ai/CurriculumTermResolver.php test/Service/Ai/FakeTermResolver.php
git commit -m "feat(ai): puerto del resolutor para delimitación de materia y hojas

TermResolverInterface gana listSubjectFamilies() y listLeaves(); el adaptador
delega en CurriculumSearch y el fake los soporta para TDD del flujo bottom-up.

Co-Authored-By: Claude Opus 4.8 <noreply@anthropic.com>"
```

---

## Task 4: CurricularClassifier — flujo bottom-up + derivación (núcleo)

**Files:**
- Modify: `src/Service/Ai/IndexSelection.php`
- Modify: `src/Service/Ai/CurricularClassifier.php`
- Test: `test/Service/Ai/CurricularClassifierTest.php` (reescribir)

**Interfaces:**
- Consumes: `TermResolverInterface` (Task 3), `PromptBuilder` (Task 1), `ResponseParser`, `LlmClientInterface`.
- Produces: `CurricularClassifier::classify(string $content): array<string,int[]>` con claves posibles `lrmi:teaches`, `lrmi:assesses`, `lrmi:educationalLevel` (derivada), `schema:about` (derivada). **Nunca** `etapa`. `IndexSelection::mapIndicesToRows(int[] $indices, array $candidates): array` devuelve las filas elegidas (dedup por id).

- [ ] **Step 1: Reescribir el test (rojo)**

Reemplaza `test/Service/Ai/CurricularClassifierTest.php` por:

```php
<?php

declare(strict_types=1);

namespace OERManager\Test\Service\Ai;

use OERManager\Service\Ai\CurricularClassifier;
use OERManager\Service\Ai\PromptBuilder;
use OERManager\Service\Ai\ResponseParser;
use PHPUnit\Framework\TestCase;

/**
 * TDD del clasificador bottom-up (ADR-0010): Etapa + familia de materia delimitan
 * (Fase A); el LLM selecciona saberes/criterios por descripción (Fase B/C); el
 * Curso (lrmi:educationalLevel) y la Materia (schema:about) se DERIVAN de las
 * hojas elegidas (Fase D) → coherencia por construcción.
 */
final class CurricularClassifierTest extends TestCase
{
    private function resolver(): FakeTermResolver
    {
        $r = new FakeTermResolver(['etapa' => [['id' => 1, 'title' => 'ESO']]]);
        $r->families = [1 => [['name' => 'Matemáticas'], ['name' => 'Lengua']]];
        $r->leaves = [
            'lrmi:teaches' => [
                ['id' => 30, 'title' => 'MAT01.1', 'description' => 'Números naturales',
                    'block' => 'I. Sentido numérico', 'courseId' => 10, 'courseTitle' => '1º ESO', 'subjectId' => 20],
                ['id' => 31, 'title' => 'MAT03.5', 'description' => 'Ecuaciones de primer grado',
                    'block' => 'IV. Sentido algebraico', 'courseId' => 12, 'courseTitle' => '3º ESO', 'subjectId' => 22],
            ],
            'lrmi:assesses' => [
                ['id' => 40, 'title' => 'MATCE.1', 'description' => 'Resuelve ecuaciones',
                    'block' => '', 'courseId' => 12, 'courseTitle' => '3º ESO', 'subjectId' => 22],
            ],
        ];
        return $r;
    }

    private function make(FakeTermResolver $resolver, FakeLlmClient $llm): CurricularClassifier
    {
        return new CurricularClassifier($llm, $resolver, new PromptBuilder(), new ResponseParser());
    }

    public function testDerivesCourseAndSubjectFromSelectedLeaves(): void
    {
        $llm = new FakeLlmClient([
            '{"selected":[1]}',   // Etapa: ESO
            '{"selected":[1]}',   // Materia: Matemáticas
            '{"selected":[2]}',   // Saberes: Ecuaciones (id 31, course 12, subject 22)
            '{"selected":[1]}',   // Criterios: id 40 (course 12, subject 22)
        ]);
        $result = $this->make($this->resolver(), $llm)->classify('recurso de ecuaciones');

        $this->assertSame([31], $result['lrmi:teaches']);
        $this->assertSame([40], $result['lrmi:assesses']);
        // Curso y materia DERIVADOS de las hojas (coherencia por construcción):
        $this->assertSame([12], $result['lrmi:educationalLevel']);
        $this->assertSame([22], $result['schema:about']);
        // La etapa nunca se escribe (ADR-0009):
        $this->assertArrayNotHasKey('etapa', $result);
    }

    public function testCrossGradeSelectionDerivesMultipleCourses(): void
    {
        $llm = new FakeLlmClient([
            '{"selected":[1]}',     // ESO
            '{"selected":[1]}',     // Matemáticas
            '{"selected":[1,2]}',   // saberes de 1º (id30,course10,subj20) y 3º (id31,course12,subj22)
            '{"selected":[]}',      // criterios: ninguno
        ]);
        $result = $this->make($this->resolver(), $llm)->classify('proyecto transversal');

        $this->assertSame([30, 31], $result['lrmi:teaches']);
        $this->assertSame([10, 12], $result['lrmi:educationalLevel']); // ambos cursos derivados
        $this->assertSame([20, 22], $result['schema:about']);
        $this->assertArrayNotHasKey('lrmi:assesses', $result);
    }

    public function testNoEtapaReturnsEmpty(): void
    {
        $r = new FakeTermResolver(['etapa' => []]);
        $llm = new FakeLlmClient(['{"selected":[1]}']);
        $this->assertSame([], $this->make($r, $llm)->classify('x'));
    }

    public function testNoSubjectReturnsEmpty(): void
    {
        $r = new FakeTermResolver(['etapa' => [['id' => 1, 'title' => 'ESO']]]);
        $r->families = [1 => []];
        $llm = new FakeLlmClient(['{"selected":[1]}', '{"selected":[1]}']);
        $this->assertSame([], $this->make($r, $llm)->classify('x'));
    }

    public function testDelimitationPassesEtapaAndSubjectToLeaves(): void
    {
        $llm = new FakeLlmClient([
            '{"selected":[1]}', '{"selected":[1]}', '{"selected":[]}', '{"selected":[]}',
        ]);
        $resolver = $this->resolver();
        $this->make($resolver, $llm)->classify('contenido');

        $leafCalls = array_values(array_filter($resolver->calls, static fn ($c) => isset($c['leaves'])));
        $this->assertSame('lrmi:teaches', $leafCalls[0]['leaves']);
        $this->assertSame(1, $leafCalls[0]['etapa']);
        $this->assertSame('Matemáticas', $leafCalls[0]['subject']);
    }

    public function testOutOfRangeLeafIndicesAreDropped(): void
    {
        $llm = new FakeLlmClient([
            '{"selected":[1]}', '{"selected":[1]}', '{"selected":[99,1]}', '{"selected":[]}',
        ]);
        $result = $this->make($this->resolver(), $llm)->classify('x');
        $this->assertSame([30], $result['lrmi:teaches']); // 99 descartado; 1 = id 30
        $this->assertSame([10], $result['lrmi:educationalLevel']);
    }

    public function testBlockPrefilterTriggersAboveThreshold(): void
    {
        // 31 saberes en 2 bloques (> BLOCK_THRESHOLD=30) → paso de bloques antes
        // de elegir saberes. El LLM elige el bloque "B" y luego el saber de "B".
        $leaves = [];
        for ($i = 1; $i <= 30; $i++) {
            $leaves[] = ['id' => 100 + $i, 'title' => "A{$i}", 'description' => "desc A {$i}",
                'block' => 'Bloque A', 'courseId' => 10, 'courseTitle' => '1º ESO', 'subjectId' => 20];
        }
        $leaves[] = ['id' => 200, 'title' => 'B1', 'description' => 'desc B 1',
            'block' => 'Bloque B', 'courseId' => 10, 'courseTitle' => '1º ESO', 'subjectId' => 20];
        $r = new FakeTermResolver(['etapa' => [['id' => 1, 'title' => 'ESO']]]);
        $r->families = [1 => [['name' => 'Matemáticas']]];
        $r->leaves = ['lrmi:teaches' => $leaves, 'lrmi:assesses' => []];

        $llm = new FakeLlmClient([
            '{"selected":[1]}',   // ESO
            '{"selected":[1]}',   // Matemáticas
            '{"selected":[2]}',   // Bloques: ["Bloque A","Bloque B"] → elige "Bloque B"
            '{"selected":[1]}',   // Saberes (ya filtrados a Bloque B): id 200
            '{"selected":[]}',    // Criterios
        ]);
        $result = $this->make($r, $llm)->classify('contenido de B');
        $this->assertSame([200], $result['lrmi:teaches']);
    }
}
```

- [ ] **Step 2: Ejecutar y verificar que falla**

Run: `make test`
Expected: FAIL (la clase aún tiene el flujo viejo y `mapIndicesToRows` no existe).

- [ ] **Step 3: Añadir mapIndicesToRows al trait**

En `src/Service/Ai/IndexSelection.php`, añade dentro del trait:

```php
    /**
     * Como mapIndicesToIds pero devuelve las FILAS elegidas (necesario para el
     * linaje en la derivación bottom-up). Dedup por id; descarta fuera de rango.
     *
     * @param int[] $indices índices 1-based
     * @param array<int,array<string,mixed>> $candidates lista 0-based
     * @return array<int,array<string,mixed>>
     */
    private function mapIndicesToRows(array $indices, array $candidates): array
    {
        $candidates = array_values($candidates);
        $rows = [];
        $seen = [];
        foreach ($indices as $index) {
            $position = $index - 1;
            if (!isset($candidates[$position])) {
                continue;
            }
            $id = (int) ($candidates[$position]['id'] ?? 0);
            if ($id > 0 && in_array($id, $seen, true)) {
                continue;
            }
            $seen[] = $id;
            $rows[] = $candidates[$position];
        }
        return $rows;
    }
```

- [ ] **Step 4: Reescribir CurricularClassifier**

Reemplaza el cuerpo de la clase en `src/Service/Ai/CurricularClassifier.php` (mantén el namespace, los `use`, `implements ClassifierInterface`, `use IndexSelection;`, el constructor y `$maxTokens`). Sustituye la constante `STEPS` y los métodos `classify()`/`select()` por:

```php
    /** Dimensiones-hoja a clasificar por descripción (Fase B/C). */
    private const LEAF_DIMENSIONS = [
        'lrmi:teaches' => 'Saberes básicos',
        'lrmi:assesses' => 'Criterios de evaluación',
    ];

    private const TEACHES = 'lrmi:teaches';

    /** Si los saberes superan este número, pre-filtrar por bloque temático (E2). */
    private const BLOCK_THRESHOLD = 30;

    public function classify(string $content): array
    {
        // Fase A.1 — Etapa (acota; no se escribe, ADR-0009).
        $etapaId = $this->pickFirstId($this->resolver->listCandidates('etapa'), 'Etapa educativa', $content);
        if (0 === $etapaId) {
            return [];
        }

        // Fase A.2 — Familia de materia (acota; NO fija el curso; no se escribe).
        $subjectName = $this->pickSubjectName($this->resolver->listSubjectFamilies($etapaId), $content);
        if ('' === $subjectName) {
            return [];
        }

        $result = [];
        $courseIds = [];
        $subjectIds = [];

        // Fase B/C — Saberes y Criterios por descripción, cruzando cursos.
        foreach (self::LEAF_DIMENSIONS as $dimension => $label) {
            $candidates = $this->resolver->listLeaves($dimension, $etapaId, $subjectName);
            if (self::TEACHES === $dimension && count($candidates) > self::BLOCK_THRESHOLD) {
                $candidates = $this->prefilterByBlock($candidates, $subjectName, $content);
            }
            $rows = $this->selectRows($label, $candidates, $content);
            if (!$rows) {
                continue;
            }
            $result[$dimension] = array_map(static fn (array $c): int => (int) $c['id'], $rows);
            foreach ($rows as $c) {
                $cId = (int) ($c['courseId'] ?? 0);
                $sId = (int) ($c['subjectId'] ?? 0);
                if ($cId > 0) {
                    $courseIds[$cId] = true;
                }
                if ($sId > 0) {
                    $subjectIds[$sId] = true;
                }
            }
        }

        // Fase D — Coherencia por derivación: curso y materia = padres de las hojas.
        if ($courseIds) {
            $result['lrmi:educationalLevel'] = array_keys($courseIds);
        }
        if ($subjectIds) {
            $result['schema:about'] = array_keys($subjectIds);
        }

        return $result;
    }

    /**
     * @param array<int,array<string,mixed>> $candidates
     * @return int[] índices 1-based devueltos por el LLM
     */
    private function ask(array $candidates, string $label, string $content, int $maxSelections): array
    {
        $prompt = $this->prompts->buildSelectionPrompt($label, $candidates, $content, $maxSelections);
        $response = $this->llm->chat(
            [['role' => 'user', 'content' => $prompt['user']]],
            ['system' => $prompt['system'], 'json' => true, 'max_tokens' => $this->maxTokens]
        );
        return $this->parser->parseIndices($response->text());
    }

    /**
     * @param array<int,array{id:int,title:string}> $candidates
     */
    private function pickFirstId(array $candidates, string $label, string $content): int
    {
        if (!$candidates) {
            return 0;
        }
        $ids = $this->mapIndicesToIds($this->ask($candidates, $label, $content, 1), $candidates);
        return $ids[0] ?? 0;
    }

    /**
     * @param array<int,array{name:string}> $families
     */
    private function pickSubjectName(array $families, string $content): string
    {
        if (!$families) {
            return '';
        }
        $candidates = array_map(static fn (array $f): array => ['title' => (string) $f['name']], $families);
        foreach ($this->ask($candidates, 'Materia (asignatura)', $content, 1) as $idx) {
            $pos = $idx - 1;
            if (isset($families[$pos])) {
                return (string) $families[$pos]['name'];
            }
        }
        return '';
    }

    /**
     * @param array<int,array<string,mixed>> $candidates
     * @return array<int,array<string,mixed>> filas elegidas
     */
    private function selectRows(string $label, array $candidates, string $content): array
    {
        if (!$candidates) {
            return [];
        }
        return $this->mapIndicesToRows($this->ask($candidates, $label, $content, 0), $candidates);
    }

    /**
     * E2: si hay muchos saberes, el LLM elige bloques temáticos y se filtran los
     * candidatos. Fallback: sin bloque elegido → todos (no se pierde cobertura).
     *
     * @param array<int,array<string,mixed>> $candidates
     * @return array<int,array<string,mixed>>
     */
    private function prefilterByBlock(array $candidates, string $subjectName, string $content): array
    {
        $blocks = array_values(array_unique(array_filter(array_map(
            static fn (array $c): string => trim((string) ($c['block'] ?? '')),
            $candidates
        ))));
        if (!$blocks) {
            return $candidates;
        }
        $blockCandidates = array_map(static fn (string $b): array => ['title' => $b], $blocks);
        $selected = [];
        foreach ($this->ask($blockCandidates, 'Bloques temáticos de ' . $subjectName, $content, 0) as $idx) {
            $pos = $idx - 1;
            if (isset($blocks[$pos])) {
                $selected[$blocks[$pos]] = true;
            }
        }
        if (!$selected) {
            return $candidates;
        }
        return array_values(array_filter(
            $candidates,
            static fn (array $c): bool => isset($selected[trim((string) ($c['block'] ?? ''))])
        ));
    }
```

Actualiza el docblock de cabecera de la clase para reflejar el flujo bottom-up (ADR-0010) en lugar del top-down.

- [ ] **Step 5: Ejecutar y verificar que pasa**

Run: `make test`
Expected: PASS (toda la suite, incluidos los 7 tests nuevos del clasificador).

- [ ] **Step 6: Lint**

Run: `make lint`
Expected: PASS.

- [ ] **Step 7: Commit**

```bash
git add src/Service/Ai/IndexSelection.php src/Service/Ai/CurricularClassifier.php test/Service/Ai/CurricularClassifierTest.php
git commit -m "feat(ai): clasificador curricular bottom-up con coherencia derivada

Reemplaza la cascada top-down (adivina el curso, propaga el error) por:
A) delimitar Etapa + familia de materia; B/C) seleccionar saberes/criterios
por descripción cruzando cursos; D) DERIVAR curso (lrmi:educationalLevel) y
materia (schema:about) de las hojas elegidas → subgrafo coherente por
construcción. Conserva el pre-filtro por bloque (E2). Nuevo
IndexSelection::mapIndicesToRows para arrastrar el linaje. Implementa ADR-0010.

Co-Authored-By: Claude Opus 4.8 <noreply@anthropic.com>"
```

---

## Task 5: TagClassifier — candidatos ricos (sin cambio de comportamiento)

**Files:**
- Modify: `src/Service/Ai/TagClassifier.php`
- Test: `test/Service/Ai/TagClassifierTest.php` (verificar)

**Interfaces:**
- Consumes: `PromptBuilder::buildSelectionPrompt` (acepta filas ricas).
- Produces: salida idéntica (`['dcterms:relation' => int[]]`); los ejes no tienen description → `formatCandidate` cae al título.

- [ ] **Step 1: Pasar candidatos ricos**

En `src/Service/Ai/TagClassifier.php`, dentro de `classify()`, sustituye:

```php
            array_map(static fn (array $c): string => (string) $c['title'], $candidates),
```

por:

```php
            $candidates,
```

- [ ] **Step 2: Ejecutar la suite**

Run: `make test`
Expected: PASS. `TagClassifierTest` debe seguir verde (salida idéntica: los ejes se formatean solo con título). Si algún assert compara el prompt carácter a carácter y falla por un espacio, ajústalo para comprobar que el título del eje aparece en la lista numerada.

- [ ] **Step 3: Lint**

Run: `make lint`
Expected: PASS.

- [ ] **Step 4: Commit**

```bash
git add src/Service/Ai/TagClassifier.php test/Service/Ai/TagClassifierTest.php
git commit -m "refactor(ai): TagClassifier pasa candidatos ricos a PromptBuilder

Sin cambio de comportamiento (los ejes no tienen description → título).
Unifica el contrato de candidatos con el clasificador curricular.

Co-Authored-By: Claude Opus 4.8 <noreply@anthropic.com>"
```

---

## Task 6: Gobernanza (ADR-0010, PEND-010, TASK-015/016, trazabilidad, memoria)

**Files:**
- Create: `docs/decisions/0010-anclaje-curricular-bottom-up.md`
- Modify: `docs/requirements.md` (PEND-010), `docs/backlog.md` (TASK-015, TASK-016), `docs/traceability.md`, `docs/project-memory.md`

**Interfaces:** documentación; sin código.

- [ ] **Step 1: Crear ADR-0010**

Crea `docs/decisions/0010-anclaje-curricular-bottom-up.md` siguiendo el formato de los ADR existentes:

```markdown
# ADR-0010: Anclaje curricular bottom-up (revisa la cascada top-down)

## Estado

Aceptado (2026-06-26). Revisa la estrategia de clasificación de ADR-0009/NFR-008
(cascada top-down) manteniendo intactos el modelo del grafo y el mapeo RDF.

## Contexto

El clasificador IA (TASK-010) proponía saberes/criterios incoherentes por dos
causas: (1) candidatos mostrados como códigos opacos, sin la `dcterms:description`
sobre la que clasificar; (2) la cascada top-down fija Etapa→Curso→Asignatura ANTES
de mirar las hojas, y el Curso es ambiguo desde el contenido (un tema aparece en
varios cursos): un error de curso propaga incoherencia a todo el subárbol.

## Decisión

1. **Anclaje bottom-up.** Delimitar gruesamente Etapa + **familia de materia**
   (nombre, sin fijar curso); seleccionar saberes/criterios por su
   `dcterms:description` cruzando los cursos de la materia; **derivar** Curso
   (`lrmi:educationalLevel`) y Asignatura (`schema:about`) como los padres reales
   de las hojas elegidas → coherencia por construcción.
2. **E1 — candidatos enriquecidos.** El prompt muestra `[curso · bloque]
   descripción (código)` para las hojas; solo título para nodos ya legibles.
3. **E2 — pre-filtro por bloque** cuando los saberes superan un umbral; fallback a
   todos si no se elige bloque. No aplica a criterios (su `dcterms:subject` son
   códigos de competencia).
4. **Sin embeddings** en esta entrega: se difiere a TASK-016/PEND-010. El punto de
   extensión es `TermResolverInterface`.

## Consecuencias

- `CurricularClassifier::classify` se reescribe a fases A→D; `CurriculumSearch`
  gana `searchSubjectFamilies`/`searchLeaves` (consulta por materia cross-grade
  con linaje). El mapeo RDF (ADR-0004) y el grafo (ADR-0009) no cambian.
- La coherencia deja de depender de aciertos en cadena: se garantiza por
  derivación. La ambigüedad de curso se resuelve por la semántica de las hojas.
- La IA sigue proponiendo; el curador confirma (ADR-0007). Si el recurso es
  transversal a cursos, se proponen varios y el curador poda.

## Fuentes

- Análisis y decisión de plan mode con el propietario, 2026-06-26.
- Diseño: docs/superpowers/specs/2026-06-26-clasificador-semantico-design.md.
- ADR-0009 (grafo), ADR-0004 (mapeo), ADR-0007 (IA propone/curador confirma).
```

- [ ] **Step 2: Añadir PEND-010 en requirements.md**

Añade (respetando el formato y la sección de PENDIENTES de `docs/requirements.md`):

```markdown
- **PEND-010** [PENDIENTE] Infraestructura de embeddings para la recuperación
  semántica (TASK-016): proveedor y endpoint. Anthropic no ofrece embeddings
  nativos; un proveedor OpenAI-compatible sí (`/embeddings`). Decidir proveedor,
  modelo, dimensión y almacenamiento del índice (fichero, sin tablas Doctrine,
  NFR-002). Bloquea TASK-016.
```

- [ ] **Step 3: Añadir TASK-015 y TASK-016 en backlog.md**

Añade dos filas siguiendo el formato de la tabla de `docs/backlog.md`:

```markdown
| TASK-015 | **Clasificador semántico bottom-up (rework de TASK-010).** Anclaje bottom-up (ADR-0010): E1 candidatos con `dcterms:description`; delimitación por familia de materia; selección de saberes/criterios cruzando cursos; derivación de curso+materia de las hojas (coherencia por construcción); E2 pre-filtro por bloque. TDD real (PromptBuilder, clasificador, fake) + lint PSR-12. | en curso | RF-009, RF-010, NFR-004, NFR-008, ADR-0004, ADR-0007, ADR-0009, ADR-0010 | TASK-010 |
| TASK-016 | **Recuperación semántica por embeddings (roadmap).** `EmbeddingTermResolver` sobre `TermResolverInterface`: índice en fichero de las descripciones, top-K por coseno, LLM rerank, misma derivación bottom-up. Bloqueada por PEND-010. | pendiente | NFR-004, NFR-008, ADR-0010, PEND-010 | TASK-015 |
```

- [ ] **Step 4: Actualizar traceability.md y project-memory.md**

- En `docs/traceability.md`: enlazar ADR-0010 ↔ TASK-015 ↔ RF-009/RF-010/NFR-008 siguiendo el formato existente.
- En `docs/project-memory.md`: una entrada datada (2026-06-26) resumiendo el giro a bottom-up y por qué (ambigüedad de curso + coherencia por derivación), con punteros a ADR-0010 y al spec.

- [ ] **Step 5: Commit**

```bash
git add docs/decisions/0010-anclaje-curricular-bottom-up.md docs/requirements.md docs/backlog.md docs/traceability.md docs/project-memory.md
git commit -m "docs: ADR-0010 anclaje bottom-up + PEND-010 + TASK-015/016 + gobernanza

Registra el giro de cascada top-down a anclaje bottom-up (revisa ADR-0009/
NFR-008), la decisión pendiente de embeddings (PEND-010), y las tareas
TASK-015 (este rework) y TASK-016 (embeddings, roadmap).

Co-Authored-By: Claude Opus 4.8 <noreply@anthropic.com>"
```

---

## Task 7: Verificación end-to-end + cierre

**Files:** ninguno nuevo (validación).

- [ ] **Step 1: Suite completa + lint**

Run: `make test && make lint`
Expected: ambos PASS.

- [ ] **Step 2: Arrancar Omeka**

Run: `docker compose up -d` (espera ~10s; entra en `http://localhost:8080/admin/`).

- [ ] **Step 3: Clasificar un REA de Biología/método científico**

Abre un item REA con contenido claro (título + descripción + medio adjunto) y ve a `http://localhost:8080/admin/item/{id}/ai-propose`.

Verifica (inspeccionando la respuesta JSON en DevTools → Network):
- Los candidatos de saberes muestran `[curso · bloque] descripción (código)`, **no** códigos sueltos.
- Los saberes/criterios propuestos son **coherentes** con el contenido.
- `lrmi:educationalLevel` y `schema:about` propuestos son **los padres reales** de los saberes elegidos (coherencia por derivación).

- [ ] **Step 4: Clasificar un REA de Matemáticas (umbral de bloque)**

Con una materia de muchos saberes cross-grade (Matemáticas), confirma que se dispara el paso de **bloques** (una llamada LLM extra) y que solo aparecen saberes de los bloques elegidos. Para contar llamadas, revisa logs del contenedor o añade logging temporal en `CurricularClassifier::ask()` (elimínalo después).

- [ ] **Step 5: Verificar string-exactness de schema:about (linchpin)**

Confirma que para la materia elegida `searchLeaves` devuelve candidatos (no vacío). Si vuelve vacío, el `schema:about` del saber no coincide exactamente con el nombre de familia: revisa que `searchSubjectFamilies` use el literal `schema:about` de la asignatura (Task 2, Step 3) y compáralo con el de un saber vía API.

- [ ] **Step 6: No regresión del re-catalogador manual (TASK-004)**

Abre el panel de re-catalogación manual y comprueba que la búsqueda incremental de cada dimensión sigue funcionando (los campos extra de `mapResults` se ignoran sin romper la UI).

- [ ] **Step 7: Cierre**

- Elimina cualquier logging temporal.
- Cierra TASK-015 en `docs/backlog.md` (estado → hecha) si la verificación e2e es satisfactoria, en un commit aparte.
- `docker compose down` si procede.

---

## Self-Review (spec coverage)

- **Causa 1 (etiquetas opacas):** Task 1 (E1) + Task 2 (description/block en candidatos). ✅
- **Causa 2 (cascada frágil):** Task 4 (flujo bottom-up + derivación Fase D). ✅
- **Delimitación de materia (Fase A):** Task 2 (`searchSubjectFamilies`) + Task 3 (puerto) + Task 4 (pasos A.1/A.2). ✅
- **Selección por descripción (Fase B/C):** Task 2 (`searchLeaves`) + Task 4 (`selectRows`). ✅
- **Coherencia por derivación (Fase D):** Task 4 (`courseIds`/`subjectIds` → `lrmi:educationalLevel`/`schema:about`). Test `testDerivesCourseAndSubjectFromSelectedLeaves`. ✅
- **E2 pre-filtro por bloque:** Task 4 (`prefilterByBlock`, `BLOCK_THRESHOLD`). Test `testBlockPrefilterTriggersAboveThreshold`. ✅
- **E3 embeddings (roadmap):** spec §E3 + Task 6 (PEND-010, TASK-016). ✅
- **No romper TASK-004 / RecatalogService / NFR-002 / NFR-004:** Global Constraints + Task 2 (aditivo) + Task 7 Step 6. ✅
- **Anti prompt-injection:** intacto (no se toca el envoltorio de `buildSelectionPrompt`); `PromptBuilderTest` existentes siguen verdes. ✅

**Consistencia de tipos:** candidato rico `{id,title,description,block,courseId,courseTitle,subjectId}` usado igual en `searchLeaves` (Task 2), interface (Task 3), `selectRows`/derivación (Task 4). `mapIndicesToRows` (Task 4) lee `['id']`. `mapIndicesToIds` (existente) usado para etapa. Sin discrepancias.

**Placeholders:** ninguno — todo el código está completo en los pasos.
