<?php

namespace OERManager\Service\Llm;

/**
 * Adaptador del LLM de Anthropic (Messages API, ADR-0008). Construye la petición
 * y parsea la respuesta usando HttpTransportInterface; no depende del core, así
 * que se prueba en el host con un transporte falso.
 *
 * No envía `temperature` por defecto: los modelos Opus 4.6+ la rechazan con 400.
 * El modelo concreto es configurable (sin valor por defecto: lo fija el admin).
 * La clave API viaja en la cabecera x-api-key y NUNCA se loguea ni se incluye en
 * los mensajes de error (CLAUDE.md §Seguridad).
 */
final class AnthropicClient implements LlmClientInterface
{
    private const DEFAULT_BASE_URL = 'https://api.anthropic.com';
    private const API_VERSION = '2023-06-01';
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
        $this->baseUrl = rtrim((string) ($config['base_url'] ?? self::DEFAULT_BASE_URL), '/');
    }

    public function chat(array $messages, array $options = []): ChatResult
    {
        if ('' === $this->model) {
            throw new LlmException('Modelo LLM no configurado (Anthropic).');
        }

        $payload = [
            'model' => $this->model,
            'max_tokens' => (int) ($options['max_tokens'] ?? self::DEFAULT_MAX_TOKENS),
            'messages' => array_map(
                static fn (array $m): array => ['role' => $m['role'], 'content' => $m['content']],
                $messages
            ),
        ];
        if (isset($options['system']) && '' !== (string) $options['system']) {
            $payload['system'] = (string) $options['system'];
        }
        // Solo si se pide explícitamente: los Opus 4.6+ rechazan temperature.
        if (array_key_exists('temperature', $options)) {
            $payload['temperature'] = $options['temperature'];
        }

        $headers = [
            'x-api-key' => $this->apiKey,
            'anthropic-version' => self::API_VERSION,
            'content-type' => 'application/json',
        ];

        $result = $this->transport->send(
            'POST',
            $this->baseUrl . '/v1/messages',
            $headers,
            (string) json_encode($payload)
        );

        if (!$result->isSuccess()) {
            throw new LlmException(sprintf(
                'Anthropic devolvió HTTP %d: %s',
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
            throw new LlmException('Respuesta de Anthropic no parseable.');
        }
        $text = '';
        foreach ($data['content'] ?? [] as $block) {
            if (is_array($block) && ($block['type'] ?? '') === 'text') {
                $text .= (string) ($block['text'] ?? '');
            }
        }
        $usage = $data['usage'] ?? [];
        return new ChatResult(
            $text,
            (int) ($usage['input_tokens'] ?? 0),
            (int) ($usage['output_tokens'] ?? 0)
        );
    }

    /**
     * Extrae un mensaje de error breve del cuerpo sin filtrar datos sensibles
     * (la clave viaja en cabecera, no en el cuerpo; aun así acotamos longitud).
     */
    private function safeError(string $body): string
    {
        $data = json_decode($body, true);
        $message = is_array($data) ? (string) ($data['error']['message'] ?? '') : '';
        if ('' === $message) {
            $message = 'error del proveedor';
        }
        return mb_substr($message, 0, 200);
    }
}
