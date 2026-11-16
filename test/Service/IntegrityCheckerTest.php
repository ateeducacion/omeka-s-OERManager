<?php

declare(strict_types=1);

namespace OERManager\Test\Service;

use OERManager\Service\Curriculum\CurModel;
use OERManager\Service\Curriculum\CurriculumTerms;
use OERManager\Service\Governance\VocabEntries;
use OERManager\Service\IntegrityChecker;
use OERManager\Test\Support\RepresentationFactory;
use PHPUnit\Framework\TestCase;

/**
 * TASK-064 slice 5 (ADR-0022 §6): a REA linked to a retired curriculum term.
 * The checker compares target ids against the retired set's ids, resolved
 * once per request by CurriculumTerms; it never reads a current term.
 */
final class IntegrityCheckerTest extends TestCase
{
    use RepresentationFactory;

    private function rea(): \Omeka\Api\Representation\ItemRepresentation
    {
        $successor = $this->curItem(31, CurModel::SABER, 'SMAT01.2 · Nuevo');
        $retired = $this->curItem(30, CurModel::SABER, 'SMAT01.1 · Antiguo', [
            'skos:prefLabel' => [$this->value('SMAT01.1 · Antiguo')],
            'skos:changeNote' => [$this->value('Retirado en 1.2.0')],
            'dcterms:isReplacedBy' => [$this->value('', $successor, 'resource:item')],
        ]);
        $current = $this->curItem(40, CurModel::MATERIA_CURSO, 'Matemáticas · 1º ESO');
        return $this->item(1, 'REA', [
            'schema:about' => [$this->value('', $current, 'resource:item')],
            'lrmi:teaches' => [$this->value('', $retired, 'resource:item')],
            'dcterms:license' => [$this->value('', null, 'uri', 'https://license')],
        ]);
    }

    private function checker(?CurriculumTerms $terms): IntegrityChecker
    {
        return new IntegrityChecker(new VocabEntries(null, static fn (int $id): array => []), $terms);
    }

    public function testLinkToARetiredIdWarnsWithItsReplacement(): void
    {
        $terms = $this->createMock(CurriculumTerms::class);
        $terms->expects($this->once())->method('retiredIds')->willReturn([30, 99]);

        $issues = $this->checker($terms)->check($this->rea())->getIssuesBySeverity('warning');
        $retired = array_values(array_filter($issues, static fn (array $i): bool => 'retired_term' === $i['code']));

        $this->assertCount(1, $retired);
        $this->assertSame('lrmi:teaches', $retired[0]['field']);
        $this->assertStringContainsString('«SMAT01.1 · Antiguo»', $retired[0]['message']);
        $this->assertStringContainsString('«SMAT01.2 · Nuevo»', $retired[0]['message']);
    }

    public function testMasterViewCheckWarnsTooWithoutTheDeadLinkRule(): void
    {
        $terms = $this->createMock(CurriculumTerms::class);
        $terms->expects($this->once())->method('retiredIds')->willReturn([30]);

        $codes = array_column($this->checker($terms)->check($this->rea(), false)->getIssues(), 'code');

        $this->assertContains('retired_term', $codes);
        $this->assertNotContains('dead_link', $codes);
    }

    public function testADeadLinkIsNotAlsoReportedAsRetired(): void
    {
        $terms = $this->createMock(CurriculumTerms::class);
        $terms->method('retiredIds')->willReturn([30]);
        $item = $this->item(3, 'REA', ['lrmi:teaches' => [$this->value('', null, 'resource:item')]]);

        $codes = array_column($this->checker($terms)->check($item)->getIssues(), 'code');

        $this->assertContains('dead_link', $codes);
        $this->assertNotContains('retired_term', $codes);
    }

    public function testNoRetiredIdsOrNoCurriculumMeansNoRetiredWarning(): void
    {
        $terms = $this->createMock(CurriculumTerms::class);
        $terms->method('retiredIds')->willReturn([]);
        foreach ([$terms, null] as $source) {
            $codes = array_column($this->checker($source)->check($this->rea())->getIssues(), 'code');
            $this->assertNotContains('retired_term', $codes);
        }
    }

    public function testRetiredSetIsNotResolvedForAnItemWithoutLinks(): void
    {
        $terms = $this->createMock(CurriculumTerms::class);
        $terms->expects($this->never())->method('retiredIds');

        $this->checker($terms)->check($this->item(2, 'Empty'));
    }
}
