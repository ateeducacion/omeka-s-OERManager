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
     * @param array<int,array{role:string,content:string}> $messages
     * @param array<string,mixed> $options Opciones del proveedor: 'system'
     *   (string), 'json' (bool, fuerza salida JSON si el proveedor lo soporta),
     *   'max_tokens' (int), 'temperature' (float).
     *
     * @throws LlmException si el transporte o el proveedor devuelven error.
     */
    public function chat(array $messages, array $options = []): ChatResult;
}
