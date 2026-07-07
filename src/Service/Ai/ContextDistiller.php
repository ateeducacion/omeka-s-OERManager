<?php

declare(strict_types=1);

namespace OERManager\Service\Ai;

use OERManager\Service\Content\ItemContext;
use OERManager\Service\Llm\LlmClientInterface;

/**
 * Destilador fiel del contexto del item (ADR-0011, afinado TASK-022). El LLM de
 * extracción (barato, vision-capable) lee el crudo (metadatos + texto de medios +
 * descripciones de visión) y produce una FICHA estructurada (tema, conceptos
 * clave, vocabulario, qué enseña, nivel citado textualmente), calibrada con
 * few-shot de casos reales del catálogo. NO infiere currículo: no propone
 * etapa/materia/curso salvo que esté literal en el recurso; la inferencia
 * curricular es del clasificador (con grafo, ADR-0010). Conservador: prima la
 * fidelidad sobre el ahorro de tokens.
 *
 * El contenido del recurso viaja como dato no-instrucción (spec §6, igual que en
 * la selección). Puro: usa LlmClientInterface (inyectado, fake en tests) y
 * PromptBuilder; sin dependencias del core → TDD real en host. Implementa
 * TraceableInterface para auditar la destilación en el panel de debug.
 */
final class ContextDistiller implements TraceableInterface
{
    /** @var array<int,array<string,mixed>> */
    private array $trace = [];

    private int $maxTokens;
    private ?float $temperature;

    public function __construct(
        private LlmClientInterface $llm,
        private PromptBuilder $prompts,
        int $maxTokens = 1024,
        ?float $temperature = null
    ) {
        $this->maxTokens = $maxTokens;
        $this->temperature = $temperature;
    }

    /**
     * Produce la ficha fiel del recurso a partir del contexto crudo. Sin señal no
     * se gasta ni un token (devuelve '').
     */
    public function distill(ItemContext $context): string
    {
        $this->trace = [];
        if ($context->isEmpty()) {
            return '';
        }
        $raw = $context->rawForDistillation();
        if ('' === trim($raw)) {
            return '';
        }
        $prompt = $this->prompts->buildDistillationPrompt($raw);
        // Perfil de inferencia compartido: temperatura solo si está configurada
        // (los Opus 4.6+ la rechazan); se traza para comparar entre proveedores.
        $options = ['system' => $prompt['system'], 'max_tokens' => $this->maxTokens];
        if (null !== $this->temperature) {
            $options['temperature'] = $this->temperature;
        }
        $response = $this->llm->chat([['role' => 'user', 'content' => $prompt['user']]], $options);
        $ficha = trim($response->text());
        $this->trace[] = [
            'step' => 'distillation',
            'system' => $prompt['system'],
            'user' => $prompt['user'],
            'llm_options' => array_diff_key($options, ['system' => '']),
            'response' => $response->text(),
            'ficha' => $ficha,
        ];
        return $ficha;
    }

    public function getTrace(): array
    {
        return $this->trace;
    }

    public function clearTrace(): void
    {
        $this->trace = [];
    }
}
