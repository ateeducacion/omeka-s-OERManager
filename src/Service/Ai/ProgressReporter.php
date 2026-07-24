<?php

namespace OERManager\Service\Ai;

/**
 * Cómo la tubería larga del propose (AiCataloguer) informa de la fase en curso y
 * consulta si debe pararse (TASK-020). Opcional: el default es el null object, así
 * el propose síncrono y los tests no cambian de comportamiento.
 */
interface ProgressReporter
{
    /** Informa la fase actual (etiqueta legible) y el avance aproximado. */
    public function report(string $step, int $done, int $total): void;

    /** ¿El curador pidió cancelar? Se comprueba en los límites de fase. */
    public function shouldStop(): bool;
}
