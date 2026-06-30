# TASK-018 — Afinado de la secuencia bottom-up del clasificador — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Que el clasificador curricular pueda proponer varias etapas y materias, y que los criterios de evaluación queden acotados a los cursos derivados de los saberes elegidos (más precisos), manteniendo la derivación de curso/materia sin LLM.

**Architecture:** Refactor de la orquestación de `CurricularClassifier::classify()`. Etapa y Materia pasan a multi-select; saberes y criterios se reúnen cruzando etapas×materias; los criterios se filtran a los cursos de los saberes elegidos (fallback: sin saberes → criterios de la materia). Curso (`lrmi:educationalLevel`) y materia (`schema:about`) se siguen DERIVANDO de las hojas (Fase D). No cambian las firmas de `TermResolverInterface`/`CurriculumSearch`.

**Tech Stack:** PHP 8.4, PHPUnit 11.5, PSR-12. Sin dependencias nuevas.

## Global Constraints

- Omeka-S 4.2, PHP 8.4 — sin sintaxis de PHP 8.5.
- Lint PSR-12 (`make lint`) y tests (`make test`) verdes; Stop hook los corre.
- Sin dependencias nuevas. Componente de ALTO RIESGO (skill `recatalogador`).
- `CurricularClassifier` es PURO (sin core, sin red): TDD en host con los fakes existentes.
- Etapa nunca se escribe en el REA (ADR-0009). Mapeo RDF (ADR-0004) y grafo (ADR-0009) no cambian. Saberes y criterios son de primera clase; dimensión con selección vacía se omite (ADR-0010 §5).
- NO cambiar las firmas de `TermResolverInterface` ni de `CurriculumSearch`.

---

### Task 1: Refactor multi-etapa/multi-materia + criterios acotados

**Files:**
- Modify: `src/Service/Ai/CurricularClassifier.php` (reescritura de `classify()` y helpers)
- Modify: `test/Service/Ai/FakeTermResolver.php` (hojas por materia)
- Test: `test/Service/Ai/CurricularClassifierTest.php` (adaptar + añadir)

**Interfaces:**
- Consumes: `TermResolverInterface::listCandidates('etapa')`, `::listSubjectFamilies(int): array{name}[]`, `::listLeaves(string,int,string): array` (sin cambios); trait `IndexSelection` (`mapIndicesToIds`, `mapIndicesToRows`); `PromptBuilder::buildSelectionPrompt`; `ResponseParser::parseIndices`.
- Produces: `CurricularClassifier::classify(string): array<string,int[]>` con la nueva orquestación. Helpers privados nuevos: `pickEtapaIds`, `gatherFamilies`, `pickSubjectNames`, `gatherLeaves`, `collectLineage`. Constante nueva `LEAF_CAP = 200`, `ASSESSES = 'lrmi:assesses'`. Se eliminan `pickFirstId`, `pickSubjectName` (singular) y `LEAF_DIMENSIONS`.

- [ ] **Step 1: Extender el fake para hojas por materia**

En `test/Service/Ai/FakeTermResolver.php`, sustituir el cuerpo de `listLeaves`:

```php
    public function listLeaves(string $dimension, int $etapaId, string $subjectName): array
    {
        $this->calls[] = ['leaves' => $dimension, 'etapa' => $etapaId, 'subject' => $subjectName];
        return $this->leaves["{$dimension}|{$subjectName}"] ?? $this->leaves[$dimension] ?? [];
    }
```

(Compatibilidad: los tests que usan la clave por dimensión siguen funcionando.)

- [ ] **Step 2: Escribir/adaptar los tests (fallarán)**

Reemplazar el cuerpo de la clase `CurricularClassifierTest` (métodos de test; conservar `resolver()`/`make()` helpers tal cual) añadiendo estos tests nuevos al final, antes del cierre de la clase. Los existentes se mantienen y deben seguir pasando con la nueva orquestación.

```php
    public function testMultipleEtapasAreQueried(): void
    {
        $r = new FakeTermResolver(['etapa' => [
            ['id' => 1, 'title' => 'Primaria'], ['id' => 2, 'title' => 'ESO'],
        ]]);
        $r->families = [1 => [['name' => 'Conocimiento del Medio']], 2 => [['name' => 'Biología y Geología']]];
        $r->leaves = ['lrmi:teaches' => [], 'lrmi:assesses' => []];
        $llm = new FakeLlmClient([
            '{"selected":[1,2]}', // dos etapas
            '{"selected":[1,2]}', // dos materias
            '{"selected":[]}', '{"selected":[]}',
        ]);
        $this->make($r, $llm)->classify('x');

        $families = array_values(array_filter($r->calls, static fn ($c) => isset($c['subjectFamilies'])));
        $this->assertSame([1, 2], array_map(static fn ($c) => $c['subjectFamilies'], $families));
        $etapasEnHojas = array_values(array_unique(array_map(
            static fn ($c) => $c['etapa'],
            array_filter($r->calls, static fn ($c) => isset($c['leaves']))
        )));
        $this->assertSame([1, 2], $etapasEnHojas);
    }

    public function testLeavesGatheredAcrossMultipleSubjects(): void
    {
        $r = new FakeTermResolver(['etapa' => [['id' => 1, 'title' => 'ESO']]]);
        $r->families = [1 => [['name' => 'Matemáticas'], ['name' => 'Física y Química']]];
        $r->leaves = [
            'lrmi:teaches|Matemáticas' => [['id' => 30, 'title' => 'M', 'description' => 'Números',
                'block' => 'I', 'courseId' => 10, 'courseTitle' => '1º', 'subjectId' => 20]],
            'lrmi:teaches|Física y Química' => [['id' => 50, 'title' => 'F', 'description' => 'Energía',
                'block' => 'II', 'courseId' => 11, 'courseTitle' => '1º', 'subjectId' => 21]],
            'lrmi:assesses' => [],
        ];
        $llm = new FakeLlmClient([
            '{"selected":[1]}',     // ESO
            '{"selected":[1,2]}',   // ambas materias
            '{"selected":[1,2]}',   // ambos saberes (id30, id50)
            '{"selected":[]}',
        ]);
        $result = $this->make($r, $llm)->classify('transversal');
        $this->assertSame([30, 50], $result['lrmi:teaches']);
        $this->assertSame([10, 11], $result['lrmi:educationalLevel']);
        $this->assertSame([20, 21], $result['schema:about']);
    }

    public function testCriteriaAreConstrainedToCoursesOfSelectedSaberes(): void
    {
        $r = new FakeTermResolver(['etapa' => [['id' => 1, 'title' => 'ESO']]]);
        $r->families = [1 => [['name' => 'Matemáticas']]];
        $r->leaves = [
            'lrmi:teaches' => [['id' => 30, 'title' => 'M', 'description' => 'Ecuaciones',
                'block' => 'IV', 'courseId' => 12, 'courseTitle' => '3º', 'subjectId' => 22]],
            'lrmi:assesses' => [
                ['id' => 40, 'title' => 'CEX', 'description' => 'crit curso 12',
                    'block' => '', 'courseId' => 12, 'courseTitle' => '3º', 'subjectId' => 22],
                ['id' => 41, 'title' => 'CEY', 'description' => 'crit curso 99',
                    'block' => '', 'courseId' => 99, 'courseTitle' => '4º', 'subjectId' => 22],
            ],
        ];
        $llm = new FakeLlmClient([
            '{"selected":[1]}',   // ESO
            '{"selected":[1]}',   // Matemáticas
            '{"selected":[1]}',   // saber id30 (curso 12)
            '{"selected":[1,2]}', // criterios: la lista YA filtrada a curso 12 solo tiene id40
        ]);
        $result = $this->make($r, $llm)->classify('ecuaciones');
        // Solo el criterio del curso 12 es elegible; el del curso 99 se filtró fuera.
        $this->assertSame([40], $result['lrmi:assesses']);
    }

    public function testCriteriaFallbackWhenNoSaberesSelected(): void
    {
        $r = new FakeTermResolver(['etapa' => [['id' => 1, 'title' => 'ESO']]]);
        $r->families = [1 => [['name' => 'Matemáticas']]];
        $r->leaves = [
            'lrmi:teaches' => [['id' => 30, 'title' => 'M', 'description' => 'd',
                'block' => 'IV', 'courseId' => 12, 'courseTitle' => '3º', 'subjectId' => 22]],
            'lrmi:assesses' => [
                ['id' => 40, 'title' => 'CEX', 'description' => 'c12',
                    'block' => '', 'courseId' => 12, 'courseTitle' => '3º', 'subjectId' => 22],
                ['id' => 41, 'title' => 'CEY', 'description' => 'c99',
                    'block' => '', 'courseId' => 99, 'courseTitle' => '4º', 'subjectId' => 23],
            ],
        ];
        $llm = new FakeLlmClient([
            '{"selected":[1]}',   // ESO
            '{"selected":[1]}',   // Matemáticas
            '{"selected":[]}',    // saberes: ninguno → sin cursos derivados
            '{"selected":[1,2]}', // criterios: sin filtro, ambos elegibles
        ]);
        $result = $this->make($r, $llm)->classify('x');
        $this->assertSame([40, 41], $result['lrmi:assesses']);
        // Curso/materia derivados solo de los criterios (fallback).
        $this->assertSame([12, 99], $result['lrmi:educationalLevel']);
        $this->assertSame([22, 23], $result['schema:about']);
    }
```

- [ ] **Step 3: Ejecutar tests para ver fallos**

Run: `make test`
Expected: FAIL en los nuevos (multi-select y filtro de criterios aún no existen); puede haber fallos en alguno existente hasta implementar.

- [ ] **Step 4: Reescribir `CurricularClassifier`**

Reemplazar el contenido de `src/Service/Ai/CurricularClassifier.php` por:

```php
<?php

namespace OERManager\Service\Ai;

use OERManager\Service\Llm\LlmClientInterface;

/**
 * Clasificador curricular bottom-up (ADR-0010). Etapa(s) y materia(s) delimitan
 * el contexto (Fase A) en multi-select, sin fijar curso; el LLM selecciona
 * saberes y criterios por su descripción cruzando etapas/materias/cursos (Fases
 * B/C); los criterios se acotan a los cursos derivados de los saberes elegidos
 * (más precisos; fallback sin saberes → criterios de la materia). Curso
 * (lrmi:educationalLevel) y materia (schema:about) se DERIVAN de las hojas
 * elegidas (Fase D) → subgrafo coherente por construcción. La etapa solo acota:
 * nunca se escribe (ADR-0009).
 */
final class CurricularClassifier implements ClassifierInterface, TraceableInterface
{
    use IndexSelection;

    private const TEACHES = 'lrmi:teaches';
    private const ASSESSES = 'lrmi:assesses';

    /** Si los saberes superan este número, pre-filtrar por bloque temático (E2). */
    private const BLOCK_THRESHOLD = 30;

    /** Tope de hojas mergeadas presentadas al LLM (coste de tokens, NFR-004/NFR-008). */
    private const LEAF_CAP = 200;

    /** @var array<int,array<string,mixed>> */
    private array $trace = [];

    private int $maxTokens;

    public function __construct(
        private LlmClientInterface $llm,
        private TermResolverInterface $resolver,
        private PromptBuilder $prompts,
        private ResponseParser $parser,
        int $maxTokens = 1024
    ) {
        $this->maxTokens = $maxTokens;
    }

    public function classify(string $content): array
    {
        // Fase A.1 — Etapas (multi; acotan, no se escriben, ADR-0009).
        $etapaIds = $this->pickEtapaIds($this->resolver->listCandidates('etapa'), $content);
        if (!$etapaIds) {
            return [];
        }

        // Fase A.2 — Materias (multi; NO fijan curso; no se escriben).
        $subjectNames = $this->pickSubjectNames($this->gatherFamilies($etapaIds), $content);
        if (!$subjectNames) {
            return [];
        }

        $result = [];
        /** @var array<int,bool> $courseIds */
        $courseIds = [];
        /** @var array<int,bool> $subjectIds */
        $subjectIds = [];

        // Fase B — Saberes por descripción, cruzando etapas/materias/cursos.
        $teaches = $this->gatherLeaves(self::TEACHES, $etapaIds, $subjectNames);
        if (count($teaches) > self::BLOCK_THRESHOLD) {
            $teaches = $this->prefilterByBlock($teaches, implode(', ', $subjectNames), $content);
        }
        $teachesRows = $this->selectRows('Saberes básicos', $teaches, $content);
        if ($teachesRows) {
            $result[self::TEACHES] = array_map(static fn (array $c): int => (int) $c['id'], $teachesRows);
            $this->collectLineage($teachesRows, $courseIds, $subjectIds);
        }

        // Fase C — Criterios; acotados a los cursos de los saberes elegidos (si los hay).
        $assesses = $this->gatherLeaves(self::ASSESSES, $etapaIds, $subjectNames);
        if ($courseIds) {
            $assesses = array_values(array_filter(
                $assesses,
                static fn (array $c): bool => isset($courseIds[(int) ($c['courseId'] ?? 0)])
            ));
        }
        $assessesRows = $this->selectRows('Criterios de evaluación', $assesses, $content);
        if ($assessesRows) {
            $result[self::ASSESSES] = array_map(static fn (array $c): int => (int) $c['id'], $assessesRows);
            $this->collectLineage($assessesRows, $courseIds, $subjectIds);
        }

        // Fase D — Derivación: curso y materia = padres reales de las hojas.
        if ($courseIds) {
            $result['lrmi:educationalLevel'] = array_keys($courseIds);
        }
        if ($subjectIds) {
            $result['schema:about'] = array_keys($subjectIds);
        }

        return $result;
    }

    public function getTrace(): array
    {
        return $this->trace;
    }

    public function clearTrace(): void
    {
        $this->trace = [];
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
        $indices = $this->parser->parseIndices($response->text());
        $this->trace[] = [
            'step' => $label,
            'candidates' => count($candidates),
            'system' => $prompt['system'],
            'user' => $prompt['user'],
            'response' => $response->text(),
            'selected_indices' => $indices,
        ];
        return $indices;
    }

    /**
     * Etapas elegidas (multi): acotan el contexto, no se escriben.
     *
     * @param array<int,array{id:int,title:string}> $candidates
     * @return int[]
     */
    private function pickEtapaIds(array $candidates, string $content): array
    {
        if (!$candidates) {
            return [];
        }
        return $this->mapIndicesToIds($this->ask($candidates, 'Etapa educativa', $content, 0), $candidates);
    }

    /**
     * Une las familias de materia de todas las etapas elegidas, dedup por nombre.
     *
     * @param int[] $etapaIds
     * @return array<int,array{name:string}>
     */
    private function gatherFamilies(array $etapaIds): array
    {
        $names = [];
        foreach ($etapaIds as $etapaId) {
            foreach ($this->resolver->listSubjectFamilies($etapaId) as $family) {
                $name = trim((string) ($family['name'] ?? ''));
                if ('' !== $name) {
                    $names[$name] = true;
                }
            }
        }
        return array_map(static fn (string $n): array => ['name' => $n], array_keys($names));
    }

    /**
     * Materias elegidas (multi).
     *
     * @param array<int,array{name:string}> $families
     * @return string[]
     */
    private function pickSubjectNames(array $families, string $content): array
    {
        if (!$families) {
            return [];
        }
        $families = array_values($families);
        $candidates = array_map(static fn (array $f): array => ['title' => (string) $f['name']], $families);
        $names = [];
        foreach ($this->ask($candidates, 'Materia (asignatura)', $content, 0) as $idx) {
            $pos = $idx - 1;
            if (isset($families[$pos])) {
                $names[(string) $families[$pos]['name']] = true;
            }
        }
        return array_keys($names);
    }

    /**
     * Reúne las hojas de una dimensión cruzando etapas×materias; dedup por id y
     * tope LEAF_CAP (coste de tokens). Combos inexistentes devuelven [] (inocuo).
     *
     * @param int[] $etapaIds
     * @param string[] $subjectNames
     * @return array<int,array<string,mixed>>
     */
    private function gatherLeaves(string $dimension, array $etapaIds, array $subjectNames): array
    {
        $merged = [];
        foreach ($etapaIds as $etapaId) {
            foreach ($subjectNames as $subjectName) {
                foreach ($this->resolver->listLeaves($dimension, $etapaId, $subjectName) as $leaf) {
                    $id = (int) ($leaf['id'] ?? 0);
                    if ($id > 0 && !isset($merged[$id])) {
                        $merged[$id] = $leaf;
                        if (count($merged) >= self::LEAF_CAP) {
                            return array_values($merged);
                        }
                    }
                }
            }
        }
        return array_values($merged);
    }

    /**
     * Acumula el linaje (curso/materia) de las filas elegidas como conjuntos.
     *
     * @param array<int,array<string,mixed>> $rows
     * @param array<int,bool> $courseIds
     * @param array<int,bool> $subjectIds
     */
    private function collectLineage(array $rows, array &$courseIds, array &$subjectIds): void
    {
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
    private function prefilterByBlock(array $candidates, string $subjectLabel, string $content): array
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
        foreach ($this->ask($blockCandidates, 'Bloques temáticos de ' . $subjectLabel, $content, 0) as $idx) {
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
}
```

- [ ] **Step 5: Ejecutar tests (verde) y lint**

Run: `make test` → Expected: PASS (todos, incluidos los nuevos y los existentes adaptados).
Run: `make lint` → Expected: sin violaciones PSR-12.

- [ ] **Step 6: Commit**

```bash
git add src/Service/Ai/CurricularClassifier.php test/Service/Ai/CurricularClassifierTest.php test/Service/Ai/FakeTermResolver.php
git commit -m "feat(ai): etapa/materia multi-select y criterios acotados a cursos (TASK-018)

Refactor bottom-up: el LLM puede proponer varias etapas y materias; saberes y
criterios se reúnen cruzando etapas×materias; los criterios se acotan a los
cursos derivados de los saberes elegidos (fallback sin saberes → materia). Curso
y materia se siguen derivando de las hojas (Fase D). TDD en host.

Co-Authored-By: Claude Opus 4.8 <noreply@anthropic.com>"
```

---

### Task 2: Cierre en gobernanza + verificación en contenedor

**Files:** `docs/backlog.md`, `docs/project-memory.md`, `docs/decisions/0010-anclaje-curricular-bottom-up.md`

- [ ] **Step 1: Verificación en contenedor (humano)**

Reclasificar el item #3181 y un REA transversal; en el trazado del intercambio LLM confirmar: (a) puede proponer varias etapas/materias; (b) la lista de criterios queda acotada a los cursos de los saberes elegidos (mucho más corta); (c) la propuesta es estable entre pasadas o, si es ambigua, ofrece ambas etapas para que el curador elija.

- [ ] **Step 2: Actualizar gobernanza**

`docs/backlog.md`: TASK-018 → `hecha` con resumen. `docs/project-memory.md`: estado. `docs/decisions/0010-...md`: nota de que la secuencia pasa a multi-etapa/multi-materia y criterios acotados por curso (afinado, no cambia el mapeo RDF ni el grafo).

- [ ] **Step 3: Commit**

```bash
git add docs/backlog.md docs/project-memory.md docs/decisions/0010-anclaje-curricular-bottom-up.md
git commit -m "docs(ai): cerrar TASK-018 (multi-etapa/materia + criterios acotados) verificado

Co-Authored-By: Claude Opus 4.8 <noreply@anthropic.com>"
```

## Notas

- No se cambian firmas de `TermResolverInterface`/`CurriculumSearch`: el multi se resuelve en bucle dentro del clasificador. El no-determinismo de la etapa se mitiga permitiendo varias etapas (la IA propone, el curador confirma).
- El trace de `TraceableInterface` se mantiene (cada `ask` lo registra), así el debug de calidad sigue visible.
