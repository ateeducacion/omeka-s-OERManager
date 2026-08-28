<?php

declare(strict_types=1);

namespace OERManager\Service\Stats;

/**
 * Cruce de 2 dimensiones (RF-007, TASK-006). Puro, mismo array de "hechos"
 * que DimensionCounter. Misma semántica de cardinalidad múltiple: un item con
 * varios valores en una dimensión aporta a CADA combinación (a, b) posible
 * entre sus valores de A y sus valores de B.
 */
final class DimensionCrosser
{
    /**
     * @param array<int, array<string, int[]|string|null>> $facts
     * @return array<int|string, array<int|string, int>>
     */
    public function cross(array $facts, string $dimensionA, string $dimensionB): array
    {
        $table = [];
        foreach ($facts as $row) {
            $valuesA = $this->normalize($row[$dimensionA] ?? null);
            $valuesB = $this->normalize($row[$dimensionB] ?? null);
            foreach ($valuesA as $a) {
                foreach ($valuesB as $b) {
                    $table[$a][$b] = ($table[$a][$b] ?? 0) + 1;
                }
            }
        }
        return $table;
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
