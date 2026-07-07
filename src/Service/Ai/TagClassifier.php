<?php

namespace OERManager\Service\Ai;

use OERManager\Service\Content\ItemContext;
use OERManager\Service\Llm\LlmClientInterface;

/**
 * Clasificador de ejes temáticos (dcterms:relation). El set de ejes es pequeño y
 * plano (configurable, ADR-0006), así que cabe entero en un único prompt: el LLM
 * elige los relevantes y se mapean a ids de la lista cerrada. Clasificación
 * barata y de alta accuracy sin recuperación previa.
 */
final class TagClassifier implements ClassifierInterface, TraceableInterface
{
    use IndexSelection;

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

    public function getTrace(): array
    {
        return $this->trace;
    }

    public function clearTrace(): void
    {
        $this->trace = [];
    }

    public function classify(ItemContext $context): array
    {
        $candidates = $this->resolver->listCandidates('dcterms:relation');
        if (!$candidates) {
            return [];
        }
        // Ejes temáticos: el detalle del contenido importa → contexto fino (ADR-0011).
        $prompt = $this->prompts->buildSelectionPrompt(
            'Ejes temáticos',
            $candidates,
            $context->fineText(),
            0
        );
        // Perfil de inferencia compartido: temperatura solo si está configurada
        // (los Opus 4.6+ la rechazan); se traza para comparar entre proveedores.
        $options = ['system' => $prompt['system'], 'json' => true, 'max_tokens' => $this->maxTokens];
        if (null !== $this->temperature) {
            $options['temperature'] = $this->temperature;
        }
        $response = $this->llm->chat([['role' => 'user', 'content' => $prompt['user']]], $options);
        $indices = $this->parser->parseIndices($response->text());
        $this->trace[] = [
            'step' => 'Ejes temáticos',
            'candidates' => count($candidates),
            'system' => $prompt['system'],
            'user' => $prompt['user'],
            'llm_options' => array_diff_key($options, ['system' => '']),
            'response' => $response->text(),
            'selected_indices' => $indices,
        ];
        $ids = $this->mapIndicesToIds($indices, $candidates);
        return $ids ? ['dcterms:relation' => $ids] : [];
    }
}
