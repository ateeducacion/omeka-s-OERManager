<?php

declare(strict_types=1);

namespace OERManager\Service\Stats;

use OERManager\Service\IntegrityResult;

/**
 * % de completitud del catálogo (RF-006/RF-007, TASK-006). Puro: agrega los
 * status ya calculados por IntegrityChecker::check()->getStatus() — no vuelve
 * a leer el item.
 */
final class CompletenessAggregator
{
    /**
     * @param list<string> $statuses Cada elemento: IntegrityResult::STATUS_OK/WARNING/ERROR
     * @return array{ok:int,warning:int,error:int,total:int,okPercent:float}
     */
    public function aggregate(array $statuses): array
    {
        $counts = [
            IntegrityResult::STATUS_OK => 0,
            IntegrityResult::STATUS_WARNING => 0,
            IntegrityResult::STATUS_ERROR => 0,
        ];
        foreach ($statuses as $status) {
            if (isset($counts[$status])) {
                $counts[$status]++;
            }
        }
        $total = count($statuses);
        return [
            'ok' => $counts[IntegrityResult::STATUS_OK],
            'warning' => $counts[IntegrityResult::STATUS_WARNING],
            'error' => $counts[IntegrityResult::STATUS_ERROR],
            'total' => $total,
            'okPercent' => $total > 0
                ? round($counts[IntegrityResult::STATUS_OK] / $total * 100, 1)
                : 0.0,
        ];
    }
}
