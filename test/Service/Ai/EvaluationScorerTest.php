<?php

declare(strict_types=1);

namespace OERManager\Test\Service\Ai;

use OERManager\Service\Ai\EvaluationScorer;
use PHPUnit\Framework\TestCase;

/**
 * TDD del scorer de evaluación de accuracy (NFR-008): precision/recall/F1 y
 * exact-match de los ids propuestos frente a la verdad-terreno (REAs ya
 * catalogados). Lógica pura, sin core.
 */
final class EvaluationScorerTest extends TestCase
{
    private EvaluationScorer $scorer;

    protected function setUp(): void
    {
        $this->scorer = new EvaluationScorer();
    }

    public function testPerfectMatch(): void
    {
        $s = $this->scorer->score([1, 2], [1, 2]);
        $this->assertEqualsWithDelta(1.0, $s['precision'], 1e-9);
        $this->assertEqualsWithDelta(1.0, $s['recall'], 1e-9);
        $this->assertEqualsWithDelta(1.0, $s['f1'], 1e-9);
        $this->assertTrue($s['exact']);
    }

    public function testOverPrediction(): void
    {
        $s = $this->scorer->score([1, 2, 3], [1, 2]);
        $this->assertEqualsWithDelta(2 / 3, $s['precision'], 1e-9);
        $this->assertEqualsWithDelta(1.0, $s['recall'], 1e-9);
        $this->assertEqualsWithDelta(0.8, $s['f1'], 1e-9);
        $this->assertFalse($s['exact']);
    }

    public function testBothEmptyIsPerfect(): void
    {
        $s = $this->scorer->score([], []);
        $this->assertEqualsWithDelta(1.0, $s['precision'], 1e-9);
        $this->assertEqualsWithDelta(1.0, $s['recall'], 1e-9);
        $this->assertTrue($s['exact']);
    }

    public function testEmptyProposalAgainstTruth(): void
    {
        $s = $this->scorer->score([], [1, 2]);
        $this->assertEqualsWithDelta(1.0, $s['precision'], 1e-9); // sin falsos positivos
        $this->assertEqualsWithDelta(0.0, $s['recall'], 1e-9);
        $this->assertEqualsWithDelta(0.0, $s['f1'], 1e-9);
        $this->assertFalse($s['exact']);
    }

    public function testSetSemanticsIgnoreOrderAndDuplicates(): void
    {
        $s = $this->scorer->score([2, 1, 1], [1, 2]);
        $this->assertTrue($s['exact']);
        $this->assertEqualsWithDelta(1.0, $s['precision'], 1e-9);
    }

    public function testMacroAverage(): void
    {
        $perfect = $this->scorer->score([1], [1]);
        $partial = $this->scorer->score([1, 2, 3], [1, 2]);
        $avg = $this->scorer->macroAverage([$perfect, $partial]);
        $this->assertEqualsWithDelta((1.0 + 2 / 3) / 2, $avg['precision'], 1e-9);
        $this->assertEqualsWithDelta(1.0, $avg['recall'], 1e-9);
        $this->assertEqualsWithDelta(0.5, $avg['exact_rate'], 1e-9);
    }
}
