<?php

namespace OERManager\Service\Llm;

/**
 * Resultado de una llamada al LLM: el texto generado y el uso de tokens
 * (para medir el coste, NFR-008). Inmutable.
 *
 * TASK-059: también el coste cargado por la llamada, cuando el proveedor lo
 * envía (OpenRouter lo incluye en `usage.cost` en cada respuesta), y el modelo
 * que realmente la atendió. Un coste desconocido es null, nunca 0.
 */
final class ChatResult
{
    public function __construct(
        private string $text,
        private int $inputTokens = 0,
        private int $outputTokens = 0,
        private ?float $cost = null,
        private string $model = ''
    ) {
    }

    /** Coste cargado por la llamada, en la moneda del proveedor; null si no lo envía. */
    public function cost(): ?float
    {
        return $this->cost;
    }

    /** Modelo que atendió la llamada (el de la respuesta o, si no viene, el configurado). */
    public function model(): string
    {
        return $this->model;
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
