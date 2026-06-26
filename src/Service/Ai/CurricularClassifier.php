<?php

namespace OERManager\Service\Ai;

use OERManager\Service\Llm\LlmClientInterface;

/**
 * Clasificador curricular bottom-up (ADR-0010): Etapa + familia de materia
 * delimitan el contexto (Fase A) sin fijar el curso; el LLM selecciona saberes
 * y criterios por su descripción semántica, cruzando todos los cursos de la
 * materia (Fases B/C); curso (lrmi:educationalLevel) y materia (schema:about) se
 * DERIVAN de las hojas elegidas (Fase D) → el subgrafo es coherente por
 * construcción. Si los saberes superan BLOCK_THRESHOLD, se pre-filtra por bloque
 * temático (E2) antes de presentarlos al LLM. La Etapa solo acota el contexto:
 * nunca se escribe en el REA (ADR-0009).
 */
final class CurricularClassifier implements ClassifierInterface
{
    use IndexSelection;

    /** Dimensiones-hoja a clasificar por descripción (Fase B/C). */
    private const LEAF_DIMENSIONS = [
        'lrmi:teaches' => 'Saberes básicos',
        'lrmi:assesses' => 'Criterios de evaluación',
    ];

    private const TEACHES = 'lrmi:teaches';

    /** Si los saberes superan este número, pre-filtrar por bloque temático (E2). */
    private const BLOCK_THRESHOLD = 30;

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
}
