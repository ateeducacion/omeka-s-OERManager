<?php

declare(strict_types=1);

namespace OERManager\Test\Service\Governance;

use OERManager\Service\Governance\CurricularSummary;
use PHPUnit\Framework\TestCase;

/**
 * La columna Curricular fusiona lrmi:educationalLevel y schema:about. El caso
 * que la motiva es real: el currículo repite «Matemáticas» en cuatro cursos y
 * la tabla pintaba cuatro chips idénticos (TASK-027 §3).
 */
final class CurricularSummaryTest extends TestCase
{
    private function link(string $title): array
    {
        return ['title' => $title, 'isLiteral' => false];
    }

    public function testSingleSubjectAndStage(): void
    {
        $result = CurricularSummary::summarise([$this->link('Biología')], [$this->link('3º ESO')]);

        $this->assertSame(['Biología'], $result['subjects']);
        $this->assertSame('3º ESO', $result['primaryStage']);
        $this->assertSame(0, $result['extraStages']);
        $this->assertFalse($result['hasLiteral']);
    }

    public function testRepeatedSubjectTitleIsDeduplicated(): void
    {
        $result = CurricularSummary::summarise(
            [$this->link('Matemáticas'), $this->link('Matemáticas'), $this->link('Matemáticas')],
            [$this->link('1º ESO'), $this->link('2º ESO')]
        );

        $this->assertSame(['Matemáticas'], $result['subjects']);
    }

    public function testExtraStagesAreCountedNotListed(): void
    {
        $result = CurricularSummary::summarise(
            [$this->link('Matemáticas')],
            [$this->link('1º ESO'), $this->link('2º ESO'), $this->link('3º ESO'), $this->link('4º ESO')]
        );

        $this->assertSame('1º ESO', $result['primaryStage']);
        $this->assertSame(3, $result['extraStages']);
    }

    public function testRepeatedStageTitleIsDeduplicatedBeforeCounting(): void
    {
        $result = CurricularSummary::summarise(
            [$this->link('Matemáticas')],
            [$this->link('1º ESO'), $this->link('1º ESO')]
        );

        $this->assertSame(0, $result['extraStages']);
    }

    /** D2: un literal en una property de enlace se marca; los 4 casos del catálogo real. */
    public function testALiteralAnywhereRaisesTheFlag(): void
    {
        $result = CurricularSummary::summarise(
            [['title' => 'Matemáticas', 'isLiteral' => true]],
            [$this->link('1º ESO')]
        );

        $this->assertTrue($result['hasLiteral']);
    }

    public function testLiteralInStagesAlsoRaisesTheFlag(): void
    {
        $result = CurricularSummary::summarise(
            [$this->link('Matemáticas')],
            [['title' => '1º ESO', 'isLiteral' => true]]
        );

        $this->assertTrue($result['hasLiteral']);
    }

    public function testTooltipListsEverythingWithoutTruncating(): void
    {
        $result = CurricularSummary::summarise(
            [$this->link('Matemáticas')],
            [$this->link('1º ESO'), $this->link('2º ESO')]
        );

        $this->assertSame('Matemáticas — 1º ESO, 2º ESO', $result['tooltip']);
    }

    public function testEmptyInputIsNotAnError(): void
    {
        $result = CurricularSummary::summarise([], []);

        $this->assertSame([], $result['subjects']);
        $this->assertSame('', $result['primaryStage']);
        $this->assertSame(0, $result['extraStages']);
        $this->assertFalse($result['hasLiteral']);
        $this->assertSame('', $result['tooltip']);
    }

    public function testBlankTitlesAreDiscarded(): void
    {
        $result = CurricularSummary::summarise([$this->link('  ')], [$this->link('')]);

        $this->assertSame([], $result['subjects']);
        $this->assertSame('', $result['primaryStage']);
    }
}
