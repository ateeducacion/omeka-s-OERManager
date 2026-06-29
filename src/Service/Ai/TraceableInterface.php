<?php

namespace OERManager\Service\Ai;

/**
 * Clasificadores que implementan esta interfaz exponen el trace de sus llamadas
 * al LLM: prompt (system+user), número de candidatos y respuesta raw. Útil para
 * auditar la calidad de la clasificación en las pruebas funcionales (TASK-015).
 */
interface TraceableInterface
{
    /**
     * Entradas del trace desde la última llamada a clearTrace(). Cada entrada:
     * {step:string, candidates:int, system:string, user:string,
     *  response:string, selected_indices:int[]}.
     *
     * @return array<int,array<string,mixed>>
     */
    public function getTrace(): array;

    public function clearTrace(): void;
}
