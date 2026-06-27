<?php

namespace OERManager\Service\Ai;

use OERManager\Service\Llm\LlmClientInterface;

/**
 * Clasificador de ejes temáticos (dcterms:relation). El set de ejes es pequeño y
 * plano (configurable, ADR-0006), así que cabe entero en un único prompt: el LLM
 * elige los relevantes y se mapean a ids de la lista cerrada. Clasificación
 * barata y de alta accuracy sin recuperación previa.
 */
final class TagClassifier implements ClassifierInterface
{
    use IndexSelection;

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
        $candidates = $this->resolver->listCandidates('dcterms:relation');
        if (!$candidates) {
            return [];
        }
        $prompt = $this->prompts->buildSelectionPrompt(
            'Ejes temáticos',
            $candidates,
            $content,
            0
        );
        $response = $this->llm->chat(
            [['role' => 'user', 'content' => $prompt['user']]],
            ['system' => $prompt['system'], 'json' => true, 'max_tokens' => $this->maxTokens]
        );
        $ids = $this->mapIndicesToIds($this->parser->parseIndices($response->text()), $candidates);
        return $ids ? ['dcterms:relation' => $ids] : [];
    }
}
