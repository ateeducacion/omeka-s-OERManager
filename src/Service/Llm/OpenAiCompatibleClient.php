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
            $chatMessages[] = ['role' => $m['role'], 'content' => $this->normalizeContent($m['content'])];
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
        // Paridad con Anthropic directo (razonamiento off por defecto): en OpenRouter
        // algunos modelos traen reasoning activado (default_enabled) y sus tokens
        // consumen max_tokens → JSON truncado. Solo se envía a OpenRouter: los
        // endpoints genéricos (vLLM, Ollama…) pueden rechazar params no estándar.
        if ($this->isOpenRouter()) {
            $payload['reasoning'] = ['effort' => 'none'];
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

    private function isOpenRouter(): bool
    {
        $host = strtolower((string) parse_url($this->baseUrl, PHP_URL_HOST));
        return 'openrouter.ai' === $host || str_ends_with($host, '.openrouter.ai');
    }

    public function supportsImages(): bool
    {
        return true;
    }

    public function supportsPdf(): bool
    {
        // chat/completions no tiene un bloque de documento PDF estándar, PERO
        // OpenRouter sí lo acepta (content part `file`, procesado nativo del
        // modelo cuando lo soporta) → el rescate de PDF escaneado (ADR-0011)
        // funciona por ese camino. Endpoints genéricos: sigue omitido (gating
        // en el extractor). Cierra la divergencia documentada en ADR-0012.
        return $this->isOpenRouter();
    }

    /**
     * Traduce el contenido: un string se reenvía tal cual; una lista de partes
     * neutrales (ADR-0011) se mapea a las partes de chat/completions — texto e
     * imágenes como `image_url` (data URL); el documento PDF como part `file`
     * SOLO en OpenRouter (en endpoints genéricos no es representable y se omite).
     *
     * @param mixed $content
     * @return mixed string o array<int,array<string,mixed>>
     */
    private function normalizeContent(mixed $content): mixed
    {
        if (!is_array($content)) {
            return $content;
        }
        $parts = [];
        foreach ($content as $part) {
            if (!is_array($part)) {
                continue;
            }
            $type = (string) ($part['type'] ?? '');
            if ('text' === $type) {
                $parts[] = ['type' => 'text', 'text' => (string) ($part['text'] ?? '')];
            } elseif ('image' === $type) {
                $mediaType = (string) ($part['media_type'] ?? '');
                $data = (string) ($part['data'] ?? '');
                $parts[] = ['type' => 'image_url', 'image_url' => ['url' => "data:{$mediaType};base64,{$data}"]];
            } elseif ('document' === $type && $this->isOpenRouter()) {
                // OpenRouter: content part `file` (data URL base64); con modelos
                // Anthropic el PDF va al procesado nativo del modelo. En endpoints
                // genéricos el documento se sigue omitiendo (sin part estándar).
                $mediaType = (string) ($part['media_type'] ?? 'application/pdf');
                $data = (string) ($part['data'] ?? '');
                $parts[] = ['type' => 'file', 'file' => [
                    'filename' => (string) ($part['name'] ?? 'document.pdf'),
                    'file_data' => "data:{$mediaType};base64,{$data}",
                ]];
            }
        }
        return $parts;
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
