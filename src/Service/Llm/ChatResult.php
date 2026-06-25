<?php

namespace OERManager\Service\Llm;

/**
 * Resultado de una llamada al LLM: el texto generado y el uso de tokens
 * (para medir el coste, NFR-008). Inmutable.
 */
final class ChatResult
{
    public function __construct(
        private string $text,
        private int $inputTokens = 0,
        private int $outputTokens = 0
    ) {
    }

    public function text(): string
    {
        return $this->text;
    }

    public function inputTokens(): int
    {
        return $this->inputTokens;
    }

    public function outputTokens(): int
    {
        return $this->outputTokens;
    }

    public function totalTokens(): int
    {
        return $this->inputTokens + $this->outputTokens;
    }
}
