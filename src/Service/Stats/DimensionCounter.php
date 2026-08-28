<?php

declare(strict_types=1);

namespace OERManager\Service\Stats;

/**
 * Conteo simple por dimensión (RF-007, TASK-006). Puro: recibe los "hechos"
 * ya extraídos por DimensionFacts (Task 5), nunca un ItemRepresentation.
 *
 * Semántica de cardinalidad múltiple (PEND-007, confirmada en TASK-036): un
 * item con varios valores en una dimensión cuenta UNA VEZ POR CADA VALOR, como
 * un facetado por etiquetas — la suma de conteos puede superar el número de
 * items.
 */
final class DimensionCounter
{
    /**
     * @param array<int, array<string, int[]|string|null>> $facts
     * @return array<int|string, int>
     */
    public function count(array $facts, string $dimension): array
    {
        $counts = [];
        foreach ($facts as $row) {
            $value = $row[$dimension] ?? null;
            foreach ($this->normalize($value) as $single) {
                $counts[$single] = ($counts[$single] ?? 0) + 1;
            }
        }
        return $counts;
    }

    /** @return list<int|string> */
    private function normalize(int|string|array|null $value): array
    {
        if (is_array($value)) {
            return $value;
        }
        return null !== $value ? [$value] : [];
    }
}
