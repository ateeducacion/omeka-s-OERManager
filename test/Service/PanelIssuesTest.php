<?php

declare(strict_types=1);

namespace OERManager\Test\Service;

use OERManager\Service\PanelIssues;
use PHPUnit\Framework\TestCase;

/**
 * TASK-065 (spec A6): the panel's integrity issues, shown in the tab that fixes
 * them, in plain language, with the missing anchors grouped in one sentence.
 */
final class PanelIssuesTest extends TestCase
{
    private const LABELS = [
        'lrmi:educationalLevel' => 'Curso',
        'schema:about' => 'Materia',
        'lrmi:teaches' => 'Saberes básicos',
        'lrmi:assesses' => 'Criterios de evaluación',
        'dcterms:license' => 'Licencia',
    ];

    private static function issue(string $code, string $field, string $message, string $severity = 'warning'): array
    {
        return ['severity' => $severity, 'code' => $code, 'field' => $field, 'message' => $message];
    }

    private static function missing(string $term): array
    {
        return self::issue('missing_alignment', $term, sprintf('Falta el campo de anclaje "%s".', $term));
    }

    public function testEachFieldBelongsToTheTabThatFixesIt(): void
    {
        $this->assertSame('curriculum', PanelIssues::tabOf('lrmi:teaches'));
        $this->assertSame('curriculum', PanelIssues::tabOf('dcterms:relation'));
        $this->assertSame('governance', PanelIssues::tabOf('dcterms:license'));
        $this->assertSame('governance', PanelIssues::tabOf('dcterms:creator'));
        $this->assertSame('flow', PanelIssues::tabOf('curation:status'));
        $this->assertSame('flow', PanelIssues::tabOf('o:site'));
        $this->assertSame('resource', PanelIssues::tabOf('dcterms:title'));
    }

    /** The owner's example: four missing anchors read as one sentence, not four lines of RDF. */
    public function testMissingAnchorsAreOneSentenceWithLabels(): void
    {
        $panel = PanelIssues::build(array_map(
            [self::class, 'missing'],
            ['lrmi:educationalLevel', 'schema:about', 'lrmi:teaches', 'lrmi:assesses']
        ), self::LABELS);

        $this->assertSame([[
            'severity' => 'warning',
            'text' => 'Falta el anclaje curricular: curso, materia, saberes básicos y criterios de evaluación.',
        ]], $panel['tabs']['curriculum']);
        $this->assertSame(['warning' => 4], $panel['counts']['curriculum']);
        $this->assertSame('curriculum', $panel['first']);
    }

    public function testOneMissingAnchorHasNoConjunction(): void
    {
        $panel = PanelIssues::build([self::missing('lrmi:teaches')], self::LABELS);

        $this->assertSame(
            'Falta el anclaje curricular: saberes básicos.',
            $panel['tabs']['curriculum'][0]['text']
        );
    }

    public function testOtherMessagesNameTheFieldByItsLabel(): void
    {
        $panel = PanelIssues::build([
            self::issue('dead_link', 'lrmi:teaches', 'El valor de "lrmi:teaches" apunta a un recurso inexistente.', 'error'),
            self::issue('missing_license', 'dcterms:license', 'El REA no tiene licencia asignada.'),
        ], self::LABELS);

        $this->assertSame(
            [['severity' => 'error', 'text' => 'El valor de «Saberes básicos» apunta a un recurso inexistente.']],
            $panel['tabs']['curriculum']
        );
        $this->assertSame('El REA no tiene licencia asignada.', $panel['tabs']['governance'][0]['text']);
        $this->assertSame(['error' => 1], $panel['counts']['curriculum']);
        $this->assertSame(['warning' => 1], $panel['counts']['governance']);
    }

    public function testErrorsComeFirstAndTheFirstTabIsTheFirstInTabOrder(): void
    {
        $panel = PanelIssues::build([
            self::issue('missing_license', 'dcterms:license', 'El REA no tiene licencia asignada.'),
            self::missing('lrmi:teaches'),
            self::issue('dead_link', 'schema:about', 'El valor de "schema:about" apunta a un recurso inexistente.', 'error'),
        ], self::LABELS);

        $this->assertSame(['error', 'warning'], array_column($panel['tabs']['curriculum'], 'severity'));
        $this->assertSame('curriculum', $panel['first']);
    }

    /** Workflow issues are not a tab's: they go to the flow bar, and do not set the first tab. */
    public function testFlowIssuesGoToTheFlowBar(): void
    {
        $panel = PanelIssues::build([
            self::issue('published_out_of_flow', 'curation:status', 'Publicado fuera del flujo: el REA es público sin el estado Publicado.'),
        ], self::LABELS);

        $this->assertSame(
            [['severity' => 'warning', 'text' => 'Publicado fuera del flujo: el REA es público sin el estado Publicado.']],
            $panel['flow']
        );
        $this->assertSame([], $panel['tabs']);
        $this->assertSame([], $panel['counts']);
        $this->assertNull($panel['first']);
    }

    public function testTheTranslatorTranslatesTheGroupedSentence(): void
    {
        $panel = PanelIssues::build(
            [self::missing('lrmi:teaches'), self::missing('lrmi:assesses')],
            self::LABELS,
            static fn (string $text): string => strtr($text, [
                'Falta el anclaje curricular: %s.' => 'Missing alignment: %s.',
                '%1$s y %2$s' => '%1$s and %2$s',
            ])
        );

        $this->assertSame(
            'Missing alignment: saberes básicos and criterios de evaluación.',
            $panel['tabs']['curriculum'][0]['text']
        );
    }

    public function testOnlyIssuesThePublishGateChecksCount(): void
    {
        $this->assertSame(1, PanelIssues::blockingPublish([
            self::missing('lrmi:teaches'),
            self::issue('published_out_of_flow', 'curation:status', 'x'),
        ]));
        $this->assertSame(0, PanelIssues::blockingPublish([]));
    }

    public function testNoIssuesIsAnEmptyPanel(): void
    {
        $this->assertSame(
            ['tabs' => [], 'flow' => [], 'counts' => [], 'first' => null],
            PanelIssues::build([], self::LABELS)
        );
    }
}
