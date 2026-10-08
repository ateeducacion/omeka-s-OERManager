<?php

declare(strict_types=1);

namespace OERManager\Service\Llm;

/**
 * Entrada de uso de una llamada al LLM para las trazas (TASK-059): tokens, coste
 * cargado (null si el proveedor no lo envía), modelo que la atendió y latencia.
 * Con ella el conjunto de evaluación compara estrategias por coste y tiempo
 * reales, no por una tabla de precios.
 */
final class LlmUsage
{
    /**
     * @param float $startedAt microtime(true) tomado justo antes de la llamada
     * @return array{input_tokens:int,output_tokens:int,cost:?float,model:string,ms:int}
     */
    public static function of(ChatResult $result, float $startedAt): array
    {
        return [
            'input_tokens' => $result->inputTokens(),
            'output_tokens' => $result->outputTokens(),
            'cost' => $result->cost(),
            'model' => $result->model(),
            'ms' => (int) round((microtime(true) - $startedAt) * 1000),
        ];
    }
}
