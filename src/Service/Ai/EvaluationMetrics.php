<?php

declare(strict_types=1);

namespace OERManager\Service\Ai;

/**
 * Métricas del conjunto de evaluación para comparar estrategias del catalogador
 * (TASK-059): dónde se pierde cada hoja declarada, coste y tokens reales de una
 * propuesta, precisión/recall/F1 micro y distribuciones de latencia. Funciones
 * puras: el arnés de contenedor les pasa trazas y puntuaciones ya obtenidas.
 */
final class EvaluationMetrics
{
    /** Trazas de debug de una propuesta que contienen llamadas al LLM. */
    private const TRACES = ['distillation', 'vision', 'curricular', 'tags'];

    /**
     * Dónde se quedó cada hoja declarada, en el orden del embudo: elegida,
     * mostrada al modelo y no elegida, reunida pero recortada (cupo, bloque,
     * filtro de cursos) o nunca reunida (etapa, materia o curso elegidos).
     * `$key` decide qué cuenta como la misma hoja (id, o gemela de ciclo).
     *
     * @param int[] $declared
     * @param int[] $gathered
     * @param int[] $shown
     * @param int[] $chosen
     * @param callable(int):string $key
     * @return array{chosen:int,shown_not_chosen:int,cut:int,not_gathered:int}
     */
    public static function reach(array $declared, array $gathered, array $shown, array $chosen, callable $key): array
    {
        $keys = static fn (array $ids): array => array_flip(array_map($key, $ids));
        [$gathered, $shown, $chosen] = [$keys($gathered), $keys($shown), $keys($chosen)];
        $out = ['chosen' => 0, 'shown_not_chosen' => 0, 'cut' => 0, 'not_gathered' => 0];
        foreach ($declared as $id) {
            $k = $key($id);
            $out[match (true) {
                isset($chosen[$k]) => 'chosen',
                isset($shown[$k]) => 'shown_not_chosen',
                isset($gathered[$k]) => 'cut',
                default => 'not_gathered',
            }]++;
        }
        return $out;
    }

    /**
     * Suma de las llamadas al LLM de una propuesta (su `debug`). El coste es la
     * suma de los costes conocidos, o null si ninguna llamada lo informó; las
     * llamadas sin coste se cuentan aparte para no confundirlas con coste 0.
     *
     * @param array<string,mixed> $debug
     * @return array{calls:int,input_tokens:int,output_tokens:int,cost:?float,calls_without_cost:int,
     *     llm_ms:int,by_model:array<string,array{calls:int,input_tokens:int,output_tokens:int,cost:float,
     *     calls_without_cost:int}>}
     */
    public static function usage(array $debug): array
    {
        $out = ['calls' => 0, 'input_tokens' => 0, 'output_tokens' => 0, 'cost' => null,
            'calls_without_cost' => 0, 'llm_ms' => 0, 'by_model' => []];
        foreach (self::TRACES as $trace) {
            foreach ((array) ($debug[$trace] ?? []) as $entry) {
                $u = is_array($entry) ? ($entry['usage'] ?? null) : null;
                if (!is_array($u)) {
                    continue;
                }
                $model = (string) ($u['model'] ?? '') ?: '?';
                $m = &$out['by_model'][$model];
                $m ??= [
                    'calls' => 0, 'input_tokens' => 0, 'output_tokens' => 0, 'cost' => 0.0, 'calls_without_cost' => 0,
                ];
                foreach ([&$out, &$m] as &$bucket) {
                    $bucket['calls']++;
                    $bucket['input_tokens'] += (int) ($u['input_tokens'] ?? 0);
                    $bucket['output_tokens'] += (int) ($u['output_tokens'] ?? 0);
                    if (is_numeric($u['cost'] ?? null)) {
                        $bucket['cost'] = (float) ($bucket['cost'] ?? 0.0) + (float) $u['cost'];
                    } else {
                        $bucket['calls_without_cost']++;
                    }
                }
                unset($bucket, $m);
                $out['llm_ms'] += (int) ($u['ms'] ?? 0);
            }
        }
        return $out;
    }

    /**
     * Precisión/recall/F1 micro: suma tp/fp/fn de todas las puntuaciones.
     *
     * @param array<int,array{tp:int,fp:int,fn:int}> $scores
     * @return array{precision:float,recall:float,f1:float,tp:int,fp:int,fn:int}
     */
    public static function micro(array $scores): array
    {
        $tp = $fp = $fn = 0;
        foreach ($scores as $s) {
            $tp += (int) ($s['tp'] ?? 0);
            $fp += (int) ($s['fp'] ?? 0);
            $fn += (int) ($s['fn'] ?? 0);
        }
        $precision = 0 === $tp + $fp ? 0.0 : (float) $tp / ($tp + $fp);
        $recall = 0 === $tp + $fn ? 0.0 : (float) $tp / ($tp + $fn);
        $f1 = 0.0 === $precision + $recall ? 0.0 : 2 * $precision * $recall / ($precision + $recall);
        return ['precision' => $precision, 'recall' => $recall, 'f1' => $f1, 'tp' => $tp, 'fp' => $fp, 'fn' => $fn];
    }

    /**
     * Media, mediana y percentil 90 (interpolación lineal) de una serie.
     *
     * @param array<int,int|float> $values
     * @return array{n:int,mean:float,median:float,p90:float}
     */
    public static function distribution(array $values): array
    {
        $values = array_map('floatval', array_values($values));
        $n = count($values);
        if (0 === $n) {
            return ['n' => 0, 'mean' => 0.0, 'median' => 0.0, 'p90' => 0.0];
        }
        sort($values);
        $percentile = static function (float $p) use ($values, $n): float {
            $rank = $p * ($n - 1);
            $low = (int) floor($rank);
            $high = (int) ceil($rank);
            return $values[$low] + ($values[$high] - $values[$low]) * ($rank - $low);
        };
        return [
            'n' => $n,
            'mean' => array_sum($values) / $n,
            'median' => $percentile(0.5),
            'p90' => $percentile(0.9),
        ];
    }
}
