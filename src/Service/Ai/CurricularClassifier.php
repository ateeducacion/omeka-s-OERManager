<?php

namespace OERManager\Service\Ai;

use OERManager\Service\Llm\LlmClientInterface;

/**
 * Clasificador curricular jerárquico top-down (NFR-008): clasifica Etapa→Curso→
 * Asignatura y, fijada la Asignatura, Saberes/Criterios SOLO entre sus hijos
 * (reusa la acotación contextual del grafo, RF-014). En cada nivel el conjunto de
 * candidatos es pequeño → menos tokens y mejor accuracy.
 *
 * La IA elige de la lista cerrada y devuelve etiquetas; se mapean a ids (ADR-0007;
 * nunca se vuelca el árbol completo). La Etapa solo acota el contexto: no es una
 * property que se escriba en el REA (ADR-0009).
 */
final class CurricularClassifier implements ClassifierInterface
{
    use LabelMatching;

    /**
     * Pasos de la cascada (ADR-0009). 'context' = clave donde guardar el id
     * elegido para acotar el siguiente nivel; 'write' = si la property se
     * propone para el REA (la Etapa no se escribe).
     */
    private const STEPS = [
        ['dimension' => 'etapa', 'label' => 'Etapa educativa',
            'multi' => false, 'context' => 'etapa', 'write' => false],
        ['dimension' => 'lrmi:educationalLevel', 'label' => 'Curso',
            'multi' => false, 'context' => 'level', 'write' => true],
        ['dimension' => 'schema:about', 'label' => 'Asignatura',
            'multi' => false, 'context' => 'about', 'write' => true],
        ['dimension' => 'lrmi:teaches', 'label' => 'Saberes básicos',
            'multi' => true, 'context' => null, 'write' => true],
        ['dimension' => 'lrmi:assesses', 'label' => 'Criterios de evaluación',
            'multi' => true, 'context' => null, 'write' => true],
    ];

    private int $maxTokens;

    public function __construct(
        private LlmClientInterface $llm,
        private TermResolverInterface $resolver,
        private PromptBuilder $prompts,
        private ResponseParser $parser,
        int $maxTokens = 512
    ) {
        $this->maxTokens = $maxTokens;
    }

    public function classify(string $content): array
    {
        $context = [];
        $result = [];
        foreach (self::STEPS as $step) {
            $candidates = $this->resolver->listCandidates($step['dimension'], $context);
            $ids = $this->select($step, $candidates, $content);
            if (null !== $step['context'] && $ids) {
                $context[$step['context']] = $ids[0];
            }
            if ($step['write'] && $ids) {
                $result[$step['dimension']] = $ids;
            }
        }
        return $result;
    }

    /**
     * @param array{dimension:string,label:string,multi:bool,context:?string,write:bool} $step
     * @param array<int,array{id:int,title:string}> $candidates
     * @return int[]
     */
    private function select(array $step, array $candidates, string $content): array
    {
        if (!$candidates) {
            return [];
        }
        $prompt = $this->prompts->buildSelectionPrompt(
            $step['label'],
            array_map(static fn (array $c): string => (string) $c['title'], $candidates),
            $content,
            $step['multi'] ? 0 : 1
        );
        $response = $this->llm->chat(
            [['role' => 'user', 'content' => $prompt['user']]],
            ['system' => $prompt['system'], 'json' => true, 'max_tokens' => $this->maxTokens]
        );
        $ids = $this->mapLabelsToIds($this->parser->parseSelection($response->text()), $candidates);
        return $step['multi'] ? $ids : array_slice($ids, 0, 1);
    }
}
