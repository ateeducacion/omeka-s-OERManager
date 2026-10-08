<?php

declare(strict_types=1);

namespace OERManager\Service\Llm;

/**
 * Modelo de decisiones tipadas (TASK-062): no conversa, responde preguntas
 * tipadas sobre un estado. Jev (TypeSafe) es la primera implementación, a
 * través de OpenRouter. Interfaz propia para poder probar con un falso y
 * cambiar de proveedor sin tocar a quien lo usa.
 */
interface DecisionModelInterface
{
    /**
     * @param array<string,array<string,mixed>> $questions id => {type: noul|choice, instructions, criteria?}
     * @throws LlmException si el proveedor falla o responde algo inutilizable
     */
    public function decide(string $state, array $questions): DecisionResult;
}
