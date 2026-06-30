<?php

declare(strict_types=1);

namespace OERManager\Test\Service\Ai;

use OERManager\Service\Llm\ChatResult;
use OERManager\Service\Llm\LlmClientInterface;

/**
 * LLM falso: devuelve respuestas encoladas (texto) en orden de llamada y registra
 * cada llamada, para TDD de los clasificadores sin red ni proveedor real.
 */
final class FakeLlmClient implements LlmClientInterface
{
    /** @var string[] */
    private array $queue;
    /** @var array<int,array{messages:array,options:array}> */
    public array $calls = [];

    public bool $supportsImages = true;
    public bool $supportsPdf = true;

    /** @param string[] $responses cuerpos de texto (JSON) en orden de llamada */
    public function __construct(array $responses = [])
    {
        $this->queue = $responses;
    }

    public function chat(array $messages, array $options = []): ChatResult
    {
        $this->calls[] = ['messages' => $messages, 'options' => $options];
        $text = $this->queue ? array_shift($this->queue) : '{"selected":[]}';
        return new ChatResult($text);
    }

    public function supportsImages(): bool
    {
        return $this->supportsImages;
    }

    public function supportsPdf(): bool
    {
        return $this->supportsPdf;
    }
}
