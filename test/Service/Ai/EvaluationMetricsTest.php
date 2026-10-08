<?php

declare(strict_types=1);

namespace OERManager\Test\Service\Ai;

use OERManager\Service\Ai\EvaluationMetrics;
use PHPUnit\Framework\TestCase;

/**
 * TASK-059: metrics of the evaluation set that compare strategies — where each
 * declared leaf was lost, real cost and tokens, micro scores and latency.
 */
final class EvaluationMetricsTest extends TestCase
{
    public function testEachDeclaredLeafIsCountedWhereItWasLost(): void
    {
        $reach = EvaluationMetrics::reach(
            [1, 2, 3, 4],      // declared
            [1, 2, 3, 9],      // gathered
            [1, 2, 9],         // shown to the model
            [1, 9],            // chosen
            static fn (int $id): string => (string) $id
        );

        $this->assertSame(['chosen' => 1, 'shown_not_chosen' => 1, 'cut' => 1, 'not_gathered' => 1], $reach);
    }

    public function testReachCanCountCycleTwinsAsTheSameLeaf(): void
    {
        $key = static fn (int $id): string => (string) intdiv($id, 10); // 31 and 32 are twins
        $reach = EvaluationMetrics::reach([31], [32], [32], [32], $key);

        $this->assertSame(1, $reach['chosen']);
    }

    public function testUsageAddsUpEveryCallOfAProposal(): void
    {
        $debug = [
            'distillation' => [['step' => 'distillation',
                'usage' => ['input_tokens' => 100, 'output_tokens' => 50, 'cost' => 0.001, 'model' => 'nano', 'ms' => 900]]],
            'vision' => [['step' => 'vision', 'skipped' => 'no_candidates']],
            'curricular' => [
                ['step' => 'Etapa educativa',
                    'usage' => ['input_tokens' => 10, 'output_tokens' => 5, 'cost' => 0.0002, 'model' => 'mini', 'ms' => 300]],
                ['step' => 'leaf_cap', 'available' => 250, 'kept' => 200],
                ['step' => 'Saberes básicos',
                    'usage' => ['input_tokens' => 20, 'output_tokens' => 5, 'cost' => null, 'model' => 'mini', 'ms' => 400]],
            ],
            'tags' => [],
        ];

        $usage = EvaluationMetrics::usage($debug);

        $this->assertSame(3, $usage['calls']);
        $this->assertSame(130, $usage['input_tokens']);
        $this->assertSame(60, $usage['output_tokens']);
        $this->assertEqualsWithDelta(0.0012, $usage['cost'], 1e-12);
        $this->assertSame(1, $usage['calls_without_cost']);
        $this->assertSame(1600, $usage['llm_ms']);
        $this->assertSame(2, $usage['by_model']['mini']['calls']);
        $this->assertSame(30, $usage['by_model']['mini']['input_tokens']);
        $this->assertSame(1, $usage['by_model']['mini']['calls_without_cost']);
    }

    public function testUsageCostIsUnknownWhenNoCallReportsIt(): void
    {
        $usage = EvaluationMetrics::usage(['curricular' => [
            ['step' => 'x', 'usage' => ['input_tokens' => 1, 'output_tokens' => 1, 'cost' => null, 'model' => 'm', 'ms' => 1]],
        ]]);

        $this->assertNull($usage['cost']);
    }

    public function testMicroScoresAddUpTruePositivesAcrossItems(): void
    {
        $micro = EvaluationMetrics::micro([
            ['tp' => 1, 'fp' => 1, 'fn' => 0],
            ['tp' => 0, 'fp' => 2, 'fn' => 2],
        ]);

        $this->assertSame(1, $micro['tp']);
        $this->assertEqualsWithDelta(0.25, $micro['precision'], 1e-9);
        $this->assertEqualsWithDelta(1 / 3, $micro['recall'], 1e-9);
        $this->assertEqualsWithDelta(2 * 0.25 * (1 / 3) / (0.25 + 1 / 3), $micro['f1'], 1e-9);
    }

    public function testMicroScoresOfNothingAreZero(): void
    {
        $this->assertSame(0.0, EvaluationMetrics::micro([])['f1']);
    }

    public function testDistributionGivesMeanMedianAndP90(): void
    {
        $d = EvaluationMetrics::distribution([10, 20, 30, 40, 50, 60, 70, 80, 90, 100]);

        $this->assertSame(10, $d['n']);
        $this->assertEqualsWithDelta(55.0, $d['mean'], 1e-9);
        $this->assertEqualsWithDelta(55.0, $d['median'], 1e-9);
        $this->assertEqualsWithDelta(91.0, $d['p90'], 1e-9);
        $this->assertSame(['n' => 0, 'mean' => 0.0, 'median' => 0.0, 'p90' => 0.0], EvaluationMetrics::distribution([]));
    }
}
