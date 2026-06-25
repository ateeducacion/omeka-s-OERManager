<?php

namespace OERManager\Service\Ai;

/**
 * Mide la accuracy de las propuestas frente a la verdad-terreno (REAs ya
 * catalogados, NFR-008): precision/recall/F1 y exact-match por conjunto de ids,
 * con semántica de conjunto (ignora orden y duplicados). Lógica pura, para iterar
 * prompts/estrategia contra una métrica reproducible.
 */
final class EvaluationScorer
{
    /**
     * @param array<int|string> $proposed
     * @param array<int|string> $truth
     * @return array{precision:float,recall:float,f1:float,exact:bool,tp:int,fp:int,fn:int}
     */
    public function score(array $proposed, array $truth): array
    {
        $p = $this->idSet($proposed);
        $t = $this->idSet($truth);

        $tp = count(array_intersect($p, $t));
        $fp = count(array_diff($p, $t));
        $fn = count(array_diff($t, $p));

        $precision = 0 === $tp + $fp ? 1.0 : $tp / ($tp + $fp);
        $recall = 0 === $tp + $fn ? 1.0 : $tp / ($tp + $fn);
        $f1 = 0.0 === $precision + $recall ? 0.0 : 2 * $precision * $recall / ($precision + $recall);

        sort($p);
        sort($t);

        return [
            'precision' => $precision,
            'recall' => $recall,
            'f1' => $f1,
            'exact' => $p === $t,
            'tp' => $tp,
            'fp' => $fp,
            'fn' => $fn,
        ];
    }

    /**
     * Macro-media de varias evaluaciones (p. ej. por item o por dimensión).
     *
     * @param array<int,array{precision:float,recall:float,f1:float,exact:bool}> $scores
     * @return array{precision:float,recall:float,f1:float,exact_rate:float,count:int}
     */
    public function macroAverage(array $scores): array
    {
        $n = count($scores);
        if (0 === $n) {
            return ['precision' => 0.0, 'recall' => 0.0, 'f1' => 0.0, 'exact_rate' => 0.0, 'count' => 0];
        }
        $precision = $recall = $f1 = 0.0;
        $exact = 0;
        foreach ($scores as $s) {
            $precision += (float) $s['precision'];
            $recall += (float) $s['recall'];
            $f1 += (float) $s['f1'];
            $exact += $s['exact'] ? 1 : 0;
        }
        return [
            'precision' => $precision / $n,
            'recall' => $recall / $n,
            'f1' => $f1 / $n,
            'exact_rate' => $exact / $n,
            'count' => $n,
        ];
    }

    /**
     * @param array<int|string> $ids
     * @return int[]
     */
    private function idSet(array $ids): array
    {
        return array_values(array_unique(array_map('intval', $ids)));
    }
}
