<?php

declare(strict_types=1);

namespace OERManager\Test\Service\Ai;

use OERManager\Service\Ai\JevLeafSelector;
use OERManager\Service\Ai\PromptBuilder;
use PHPUnit\Framework\TestCase;

/**
 * TASK-062: Jev picks knowledge/criteria with one yes/no (noul) question per
 * candidate; a candidate is proposed when P(yes) reaches the threshold.
 */
final class JevLeafSelectorTest extends TestCase
{
    /** @return list<array<string,mixed>> */
    private function candidates(int $n, int $from = 100): array
    {
        $out = [];
        for ($i = 0; $i < $n; $i++) {
            $out[] = ['id' => $from + $i, 'title' => 'PMAT03SB' . $i, 'description' => 'Saber ' . ($from + $i),
                'block' => 'III. Sentido espacial', 'courseTitle' => '3º Primaria'];
        }
        return $out;
    }

    private function selector(FakeDecisionModel $model, float $threshold = 0.6, int $max = 200): JevLeafSelector
    {
        return new JevLeafSelector($model, new PromptBuilder(), $threshold, $max);
    }

    public function testAsksOneNoulPerCandidateWithItsOwnTextAndTheResourceAsState(): void
    {
        $model = new FakeDecisionModel(static fn (): float => 0.1);
        $this->selector($model)->select('Ficha: geometría', $this->candidates(2), 'lrmi:teaches');

        $this->assertCount(1, $model->calls);
        $this->assertSame('Ficha: geometría', $model->calls[0]['state']);
        $questions = $model->calls[0]['questions'];
        $this->assertSame(['0', '1'], array_map('strval', array_keys($questions)));
        $this->assertSame('noul', $questions['0']['type']);
        $this->assertStringContainsString('[3º Primaria · III. Sentido espacial] Saber 100 (PMAT03SB0)', $questions['0']['instructions']);
        $this->assertArrayHasKey('true', $questions['0']['criteria']);
        $this->assertArrayHasKey('false', $questions['0']['criteria']);
    }

    public function testCriteriaAreAskedAsAssessmentNotTeaching(): void
    {
        $model = new FakeDecisionModel(static fn (): float => 0.1);
        $this->selector($model)->select('x', $this->candidates(1), 'lrmi:assesses');

        $this->assertStringContainsString('assess', $model->calls[0]['questions']['0']['instructions']);
    }

    public function testCandidatesAtOrAboveTheThresholdAreChosenMostLikelyFirst(): void
    {
        $p = ['0' => 0.59, '1' => 0.6, '2' => 0.95, '3' => 0.2];
        $out = $this->selector(new FakeDecisionModel(static fn (string $id): float => $p[$id]))
            ->select('x', $this->candidates(4), 'lrmi:teaches');

        $this->assertSame([102, 101], array_column($out['selected'], 'id'));
        $this->assertSame([100 => 0.59, 101 => 0.6, 102 => 0.95, 103 => 0.2], $out['probabilities']);
    }

    public function testNothingAboveTheThresholdMeansAbstention(): void
    {
        $out = $this->selector(new FakeDecisionModel(static fn (): float => 0.3))
            ->select('x', $this->candidates(3), 'lrmi:teaches');

        $this->assertSame([], $out['selected']);
    }

    public function testLargeCandidateSetsAreSplitIntoSeveralRequests(): void
    {
        $model = new FakeDecisionModel(static fn (string $id, array $q): float =>
            str_contains($q['instructions'], 'Saber 549') ? 0.9 : 0.1);
        $out = $this->selector($model, 0.6, 200)->select('x', $this->candidates(450), 'lrmi:teaches');

        $this->assertSame([200, 200, 50], array_map(static fn (array $c): int => count($c['questions']), $model->calls));
        $this->assertCount(450, $out['probabilities']);
        $this->assertSame([549], array_column($out['selected'], 'id'));
        $this->assertCount(3, $out['usage']);
    }

    public function testAMissingAnswerIsNeverChosen(): void
    {
        $out = $this->selector(new FakeDecisionModel(static fn (string $id): ?float => '0' === $id ? null : 0.9))
            ->select('x', $this->candidates(2), 'lrmi:teaches');

        $this->assertSame([101], array_column($out['selected'], 'id'));
        $this->assertSame([100 => null, 101 => 0.9], $out['probabilities']);
    }

    public function testEachRequestReportsItsUsage(): void
    {
        $out = $this->selector(new FakeDecisionModel(static fn (): float => 0.1))
            ->select('x', $this->candidates(2), 'lrmi:teaches');

        $this->assertSame(['input_tokens', 'output_tokens', 'cost', 'model', 'ms'], array_keys($out['usage'][0]));
        $this->assertSame('typesafe/jev-fake', $out['usage'][0]['model']);
    }
}
