<?php

declare(strict_types=1);

namespace OERManager\Service\Ai;

use OERManager\Service\Llm\DecisionModelInterface;
use OERManager\Service\Llm\LlmUsage;

/**
 * Selección fina de saberes y criterios con un modelo de decisiones (TASK-062):
 * una pregunta sí/no (`noul`) por candidato, en paralelo dentro de cada
 * petición; se propone el candidato cuya P(sí) alcanza el umbral. Si ninguno
 * lo alcanza, no se propone nada: una arista ausente es mejor que una errónea.
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

    public function __construct(
        private DecisionModelInterface $model,
        private PromptBuilder $prompts,
        private float $threshold = 0.6,
        private int $maxQuestionsPerRequest = 200
    ) {
    }

    public function threshold(): float
    {
        return $this->threshold;
    }

    /**
     * @param array<int,array<string,mixed>> $candidates filas con id, title, description, block, courseTitle
     * @return array{
     *     selected: list<array<string,mixed>>,
     *     probabilities: array<int,?float>,
     *     usage: list<array<string,mixed>>
     * } elegidos de mayor a menor P(sí); P(sí) de todos los candidatos (null = sin respuesta)
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

        $selected = array_values(array_filter(
            $candidates,
            fn (array $c): bool => ($probabilities[(int) $c['id']] ?? -1.0) >= $this->threshold
        ));
        usort(
            $selected,
            static fn (array $a, array $b): int => $probabilities[(int) $b['id']] <=> $probabilities[(int) $a['id']]
        );
        return ['selected' => $selected, 'probabilities' => $probabilities, 'usage' => $usage];
    }
}
