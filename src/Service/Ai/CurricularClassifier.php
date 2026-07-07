<?php

namespace OERManager\Service\Ai;

use OERManager\Service\Content\ItemContext;
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

    /**
     * Sesgo de inclusividad SOLO para la etapa acotadora (Fase A.1). La etapa no
     * se escribe (ADR-0009); solo delimita qué saberes/criterios llegan a las
     * fases B/C. Una etapa omitida deja fuera sus contenidos, que ya no podrán
     * proponerse; incluir una de más es inocuo (las hojas se eligen por
     * descripción y curso/materia se derivan abajo, ADR-0010). Por eso, ante duda
     * de nivel, se prima el recall. Este sesgo NO se aplica a materia ni a las
     * hojas: ahí la precisión sí importa (materia/curso salen de las hojas).
     */
    private const ETAPA_GUIDANCE = 'Ante la duda sobre el nivel educativo, sé INCLUSIVO: si el recurso podría '
        . 'encajar en varias etapas, selecciónalas TODAS. Es preferible incluir una etapa de más que dejar '
        . 'fuera la correcta, porque los contenidos (saberes y criterios) de las etapas no elegidas no podrán '
        . 'proponerse después. Excluye solo las etapas claramente inaplicables.';

    /** @var array<int,array<string,mixed>> */
    private array $trace = [];

    private int $maxTokens;
    private ?float $temperature;

    public function __construct(
        private LlmClientInterface $llm,
        private TermResolverInterface $resolver,
        private PromptBuilder $prompts,
        private ResponseParser $parser,
        int $maxTokens = 1024,
        ?float $temperature = null
    ) {
        $this->maxTokens = $maxTokens;
        $this->temperature = $temperature;
    }

    public function classify(ItemContext $context): array
    {
        // Pasos gruesos (etapa/materia/bloque) con la ficha; pasos finos
        // (saberes/criterios) con ficha + crudo de medios (ADR-0011).
        $coarse = $context->coarseText();
        $fine = $context->fineText();

        // Fase A.1 — Etapas (multi; acotan, no se escriben, ADR-0009).
        $etapaIds = $this->pickEtapaIds($this->resolver->listCandidates('etapa'), $coarse);
        if (!$etapaIds) {
            return [];
        }

        // Fase A.2 — Materias (multi; NO fijan curso; no se escriben).
        $subjectNames = $this->pickSubjectNames($this->gatherFamilies($etapaIds), $coarse);
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
            $teaches = $this->prefilterByBlock($teaches, implode(', ', $subjectNames), $coarse);
        }
        $teachesRows = $this->selectRows('Saberes básicos', $teaches, $fine);
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
        $assessesRows = $this->selectRows('Criterios de evaluación', $assesses, $fine);
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
    private function ask(
        array $candidates,
        string $label,
        string $content,
        int $maxSelections,
        string $guidance = ''
    ): array {
        $prompt = $this->prompts->buildSelectionPrompt($label, $candidates, $content, $maxSelections, $guidance);
        // Perfil de inferencia compartido: temperatura solo si está configurada
        // (los Opus 4.6+ la rechazan); se traza para comparar entre proveedores.
        $options = ['system' => $prompt['system'], 'json' => true, 'max_tokens' => $this->maxTokens];
        if (null !== $this->temperature) {
            $options['temperature'] = $this->temperature;
        }
        $response = $this->llm->chat([['role' => 'user', 'content' => $prompt['user']]], $options);
        $indices = $this->parser->parseIndices($response->text());
        $this->trace[] = [
            'step' => $label,
            'candidates' => count($candidates),
            'system' => $prompt['system'],
            'user' => $prompt['user'],
            'llm_options' => array_diff_key($options, ['system' => '']),
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
        return $this->mapIndicesToIds(
            $this->ask($candidates, 'Etapa educativa', $content, 0, self::ETAPA_GUIDANCE),
            $candidates
        );
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
