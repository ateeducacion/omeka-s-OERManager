<?php

namespace OERManager\Service\Llm;

/**
 * Adaptador para endpoints OpenAI-compatible (chat/completions): cubre un modelo
 * local (llama.cpp, vLLM, Ollama) o cualquier endpoint OpenAI-compatible con un
 * solo contrato (ADR-0008). Construye la petición y parsea la respuesta vía
 * HttpTransportInterface; sin dependencias del core (testeable en host).
 *
 * El `system` se inyecta como primer mensaje role=system (no hay campo aparte).
 * La clave viaja en Authorization: Bearer y nunca se loguea.
 */
final class OpenAiCompatibleClient implements LlmClientInterface
{
    private const DEFAULT_MAX_TOKENS = 1024;

    private string $apiKey;
    private string $model;
    private string $baseUrl;

    /**
     * @param array<string,mixed> $config 'api_key', 'model', 'base_url'
     */
    public function __construct(private HttpTransportInterface $transport, array $config = [])
    {
        $this->apiKey = (string) ($config['api_key'] ?? '');
        $this->model = trim((string) ($config['model'] ?? ''));
        $this->baseUrl = rtrim((string) ($config['base_url'] ?? ''), '/');
    }

    public function chat(array $messages, array $options = []): ChatResult
    {
        if ('' === $this->model) {
            throw new LlmException('Modelo LLM no configurado (OpenAI-compatible).');
        }
        if ('' === $this->baseUrl) {
            throw new LlmException('Base URL del LLM no configurada (OpenAI-compatible).');
        }

        $chatMessages = [];
        if (isset($options['system']) && '' !== (string) $options['system']) {
            $chatMessages[] = ['role' => 'system', 'content' => (string) $options['system']];
        }
        foreach ($messages as $m) {
            $chatMessages[] = ['role' => $m['role'], 'content' => $m['content']];
        }

        $payload = [
            'model' => $this->model,
            'messages' => $chatMessages,
            'max_tokens' => (int) ($options['max_tokens'] ?? self::DEFAULT_MAX_TOKENS),
        ];
        if (!empty($options['json'])) {
            $payload['response_format'] = ['type' => 'json_object'];
        }
        if (array_key_exists('temperature', $options)) {
            $payload['temperature'] = $options['temperature'];
        }

        $headers = [
            'Authorization' => 'Bearer ' . $this->apiKey,
            'content-type' => 'application/json',
        ];

        $result = $this->transport->send(
            'POST',
            $this->baseUrl . '/chat/completions',
            $headers,
            (string) json_encode($payload)
        );

        if (!$result->isSuccess()) {
            throw new LlmException(sprintf(
                'Endpoint OpenAI-compatible devolvió HTTP %d: %s',
                $result->status(),
                $this->safeError($result->body())
            ));
        }

        return $this->parse($result->body());
    }

    private function parse(string $body): ChatResult
    {
        $data = json_decode($body, true);
        if (!is_array($data)) {
            throw new LlmException('Respuesta OpenAI-compatible no parseable.');
        }
        $text = (string) ($data['choices'][0]['message']['content'] ?? '');
        $usage = $data['usage'] ?? [];
        return new ChatResult(
            $text,
            (int) ($usage['prompt_tokens'] ?? 0),
            (int) ($usage['completion_tokens'] ?? 0)
        );
    }

    private function safeError(string $body): string
    {
        $data = json_decode($body, true);
        $message = '';
        if (is_array($data)) {
            $error = $data['error'] ?? '';
            $message = is_array($error) ? (string) ($error['message'] ?? '') : (string) $error;
        }
        if ('' === $message) {
            $message = 'error del proveedor';
        }
        return mb_substr($message, 0, 200);
    }
}
