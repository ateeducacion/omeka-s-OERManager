<?php

namespace OERManager\Service\Llm;

/**
 * Respuesta HTTP mínima (status + cuerpo) devuelta por HttpTransportInterface.
 * Inmutable y sin dependencias del core, para poder simularla en los tests.
 */
final class HttpResult
{
    public function __construct(private int $status, private string $body)
    {
    }

    public function status(): int
    {
        return $this->status;
    }

    public function body(): string
    {
        return $this->body;
    }

    public function isSuccess(): bool
    {
        return $this->status >= 200 && $this->status < 300;
    }
}
