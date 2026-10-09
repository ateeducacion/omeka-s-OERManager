<?php

declare(strict_types=1);

namespace OERManager\Service\Ai;

use OERManager\Service\Llm\DecisionModelInterface;
use OERManager\Service\Llm\LlmUsage;

/**
 * Selección fina de saberes y criterios con un modelo de decisiones (TASK-062):
 * una pregunta sí/no (`noul`) por candidato, en paralelo dentro de cada
 * petición. Los candidatos cuya P(sí) alcanza el umbral son los anclas (de
 * ellos se derivan curso y criterios candidatos); se proponen solo los K más
 * probables de cada dimensión, porque la P(sí) de Jev ordena bien dentro de un
 * REA pero no está calibrada (con P 0.8-0.9 acierta el 30 %; barrido de
 * 2026-10-09). Sin tope (0), se propone todo ancla. Si ninguno alcanza el
 * umbral, no se propone nada: una arista ausente es mejor que una errónea.
 *
 * El texto de cada candidato es el mismo que ve hoy el LLM
 * (PromptBuilder::formatCandidate), para comparar las dos estrategias con
 * igualdad de información. Instrucciones en inglés (idioma principal de Jev),
 * datos del currículo en español tal como están guardados.
 */
final class JevLeafSelector
{
    private const QUESTIONS = [
        'lrmi:teaches' => [
            'instructions' => 'Does the educational resource described in `state` teach or practise this curriculum '
                . 'item (basic knowledge)? Item: %s',
            'true' => 'The resource works on this item: its activities or explanations address it.',
            'false' => 'The item is unrelated to the resource, or only mentioned in passing.',
        ],
        'lrmi:assesses' => [
            'instructions' => 'Could the educational resource described in `state` be used to assess this '
                . 'evaluation criterion? Criterion: %s',
            'true' => 'The resource has tasks or products that show whether the learner meets this criterion.',
            'false' => 'Nothing in the resource lets the learner show this criterion.',
        ],
    ];

    /** @param array<string,int> $caps máximo de hojas propuestas por dimensión (0 o ausente = sin tope) */
    public function __construct(
        private DecisionModelInterface $model,
        private PromptBuilder $prompts,
        private float $threshold = 0.6,
        private int $maxQuestionsPerRequest = 200,
        private array $caps = []
    ) {
    }

    public function threshold(): float
    {
        return $this->threshold;
    }

    /** @return array<string,int> máximo de hojas propuestas por dimensión (0 = sin tope) */
    public function caps(): array
    {
        return $this->caps;
    }

    public function capFor(string $dimension): int
    {
        return max(0, (int) ($this->caps[$dimension] ?? 0));
    }

    /**
     * @param array<int,array<string,mixed>> $candidates filas con id, title, description, block, courseTitle
     * @return array{
     *     selected: list<array<string,mixed>>,
     *     anchors: list<array<string,mixed>>,
     *     probabilities: array<int,?float>,
     *     usage: list<array<string,mixed>>
     * } propuestos (los K primeros anclas) y anclas (P(sí) ≥ umbral), de mayor a menor
     *   P(sí); P(sí) de todos los candidatos (null = sin respuesta)
     */
    public function select(string $state, array $candidates, string $dimension): array
    {
        $template = self::QUESTIONS[$dimension] ?? self::QUESTIONS['lrmi:teaches'];
        $candidates = array_values($candidates);
        $probabilities = [];
        $usage = [];
        foreach (array_chunk($candidates, max(1, $this->maxQuestionsPerRequest)) as $chunk) {
            $questions = [];
            foreach ($chunk as $i => $candidate) {
                // Id no numérico: con claves "0","1" json_encode manda una lista y Jev la rechaza.
                $questions['c' . $i] = [
                    'type' => 'noul',
                    'instructions' => sprintf($template['instructions'], $this->prompts->formatCandidate($candidate)),
                    'criteria' => ['true' => $template['true'], 'false' => $template['false']],
                ];
            }
            $startedAt = microtime(true);
            $result = $this->model->decide($state, $questions);
            $usage[] = LlmUsage::of($result->usage(), $startedAt);
            foreach ($chunk as $i => $candidate) {
                $probabilities[(int) $candidate['id']] = $result->noul('c' . $i);
            }
        }

        $anchors = array_values(array_filter(
            $candidates,
            fn (array $c): bool => ($probabilities[(int) $c['id']] ?? -1.0) >= $this->threshold
        ));
        usort(
            $anchors,
            static fn (array $a, array $b): int => $probabilities[(int) $b['id']] <=> $probabilities[(int) $a['id']]
        );
        $cap = $this->capFor($dimension);
        $selected = $cap > 0 ? array_slice($anchors, 0, $cap) : $anchors;
        return ['selected' => $selected, 'anchors' => $anchors, 'probabilities' => $probabilities, 'usage' => $usage];
    }
}
