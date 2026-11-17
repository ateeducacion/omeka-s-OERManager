<?php

declare(strict_types=1);

namespace OERManager\Test\Service\Ai;

use OERManager\Service\Ai\CurriculumTermResolver;
use OERManager\Service\CurriculumSearch;
use PHPUnit\Framework\TestCase;

/**
 * TASK-064 slice 6: the classifier's port on the `cur:` model. Families are the
 * `cur:Materia` of a stage (with id and courses); leaves are bound to stage,
 * Materia name and courses, carry `skos:definition` as their text and their
 * block. The leaf's code shown to the model is its `skos:notation`: the
 * `skos:prefLabel` («<notation> · <truncated text…>») would repeat the
 * definition in every candidate line (PromptBuilder shows «text (code)»).
 */
final class CurriculumTermResolverTest extends TestCase
{
    public function testStagesAxesAndCascadeDimensionsAreEnumeratedWithTheBoundedLimit(): void
    {
        $search = $this->createMock(CurriculumSearch::class);
        $search->expects(self::once())->method('searchEtapas')->with('', 300)->willReturn([['id' => 1]]);
        $search->expects(self::once())->method('searchAxes')->with('', 300)->willReturn([['id' => 2]]);
        $search->expects(self::once())->method('searchDimension')
            ->with('schema:about', '', ['level' => [5]], 300)->willReturn([['id' => 3]]);
        $resolver = new CurriculumTermResolver($search);

        self::assertSame([['id' => 1]], $resolver->listCandidates('etapa'));
        self::assertSame([['id' => 2]], $resolver->listCandidates('dcterms:relation'));
        self::assertSame([['id' => 3]], $resolver->listCandidates('schema:about', ['level' => [5]]));
    }

    public function testFamiliesAreTheMateriasOfTheStage(): void
    {
        $families = [['name' => 'Matemáticas (ESO)', 'id' => 70, 'courses' => [['id' => 13, 'title' => '3º ESO']]]];
        $search = $this->createMock(CurriculumSearch::class);
        $search->expects(self::once())->method('searchSubjectFamilies')->with(2, 300)->willReturn($families);

        self::assertSame($families, (new CurriculumTermResolver($search))->listSubjectFamilies(2));
    }

    public function testLeavesShowTheirNotationAsCodeAndTheirDefinitionAsText(): void
    {
        $search = $this->createMock(CurriculumSearch::class);
        $search->expects(self::once())->method('searchLeaves')
            ->with('lrmi:teaches', 2, 'Matemáticas (ESO)', 300, [13])
            ->willReturn([
                [
                    'id' => 60546, 'title' => 'SMAT01SBI.1 · Conteo.', 'notation' => 'SMAT01SBI.1',
                    'description' => 'Conteo.', 'block' => 'SMAT01SBI · I. Sentido numérico',
                    'courseId' => 13, 'courseTitle' => '1º ESO', 'subjectId' => 46626,
                ],
                // Without a notation the label stays the code.
                [
                    'id' => 7, 'title' => 'Sin código', 'notation' => '', 'description' => 'Texto',
                    'block' => '', 'courseId' => 13, 'courseTitle' => '1º ESO', 'subjectId' => 46626,
                ],
            ]);

        $leaves = (new CurriculumTermResolver($search))->listLeaves('lrmi:teaches', 2, 'Matemáticas (ESO)', [13]);

        self::assertSame([
            'id' => 60546, 'title' => 'SMAT01SBI.1', 'notation' => 'SMAT01SBI.1',
            'description' => 'Conteo.', 'block' => 'SMAT01SBI · I. Sentido numérico',
            'courseId' => 13, 'courseTitle' => '1º ESO', 'subjectId' => 46626,
        ], $leaves[0]);
        self::assertSame('Sin código', $leaves[1]['title']);
    }
}
