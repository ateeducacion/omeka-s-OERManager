<?php

declare(strict_types=1);

namespace OERManager\Test\Service\Llm;

use OERManager\Service\Llm\HttpResult;
use OERManager\Service\Llm\HttpTransportInterface;

/**
 * Transporte HTTP falso para TDD de los adaptadores LLM: registra la última
 * petición y devuelve una respuesta predefinida, sin red ni Laminas\Http\Client.
 */
final class FakeTransport implements HttpTransportInterface
{
    public ?string $method = null;
    public ?string $url = null;
    /** @var array<string,string> */
    public array $headers = [];
    public ?string $body = null;

    public function __construct(private HttpResult $result)
    {
    }

    public function send(string $method, string $url, array $headers, string $body): HttpResult
    {
        $this->method = $method;
        $this->url = $url;
        $this->headers = $headers;
        $this->body = $body;
        return $this->result;
    }

    /** @return array<string,mixed> */
    public function decodedBody(): array
    {
        return json_decode((string) $this->body, true) ?? [];
    }
}
