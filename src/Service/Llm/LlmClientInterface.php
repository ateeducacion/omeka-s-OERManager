<?php

namespace OERManager\Service\Llm;

/**
 * Cliente LLM configurable por proveedor (ADR-0008). Dos adaptadores
 * (AnthropicClient, OpenAiCompatibleClient) comparten este contrato; el
 * proveedor activo se elige por configuración del módulo.
 */
interface LlmClientInterface
{
    /**
     * Envía una conversación y devuelve la respuesta del modelo.
     *
     * El `content` de cada mensaje es un string (texto) O una lista de partes
     * neutrales multimodales (ADR-0011): cada parte es
     * `['type' => 'text', 'text' => string]`,
     * `['type' => 'image', 'media_type' => string, 'data' => base64]` o
     * `['type' => 'document', 'media_type' => 'application/pdf', 'data' => base64]`.
     * Cada adaptador traduce las partes a su formato de cabo (bloques de Anthropic,
     * `image_url` de OpenAI…); las partes que el proveedor no soporta se omiten (el
     * gating de visión/PDF lo decide quien llama, vía supportsImages()/supportsPdf()).
     *
     * @param array<int,array{role:string,content:mixed}> $messages
     * @param array<string,mixed> $options Opciones del proveedor: 'system'
     *   (string), 'json' (bool, fuerza salida JSON si el proveedor lo soporta),
     *   'max_tokens' (int), 'temperature' (float).
     *
     * @throws LlmException si el transporte o el proveedor devuelven error.
     */
    public function chat(array $messages, array $options = []): ChatResult;

    /** ¿El proveedor/modelo acepta bloques de imagen (visión)? */
    public function supportsImages(): bool;

    /** ¿El proveedor/modelo acepta documentos PDF nativos? */
    public function supportsPdf(): bool;
}
