<?php

declare(strict_types=1);

namespace OERManager\Service\Llm;

/**
 * Respuestas tipadas de un modelo de decisiones, ya validadas (TASK-062): solo
 * contiene respuestas a preguntas que se hicieron y del tipo pedido, con
 * probabilidades numéricas en [0, 1]. El uso (tokens, coste real, modelo
 * servido) va en un ChatResult para reutilizar LlmUsage en las trazas.
 */
final class DecisionResult
{
    /**
     * @param array<string,array<string,mixed>> $answers id => respuesta validada
     */
    public function __construct(private array $answers, private ChatResult $usage)
    {
    }

    /** @return array<string,mixed>|null */
    public function answer(string $id): ?array
    {
        return $this->answers[$id] ?? null;
    }

    /** P(sí) de una pregunta noul, o null si no hubo respuesta válida. */
    public function noul(string $id): ?float
    {
        $answer = $this->answers[$id] ?? null;
        return is_array($answer) && 'noul' === $answer['type'] ? (float) $answer['noul'] : null;
    }

    public function usage(): ChatResult
    {
        return $this->usage;
    }
}
