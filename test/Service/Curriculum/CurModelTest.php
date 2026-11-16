<?php

declare(strict_types=1);

namespace OERManager\Test\Service\Curriculum;

use OERManager\Service\Curriculum\CurModel;
use OERManager\Test\Support\RepresentationFactory;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * TASK-064 slice 1: the `cur:` model of ADR-0022 as pure data and readers.
 */
final class CurModelTest extends TestCase
{
    use RepresentationFactory;

    public static function dimensions(): array
    {
        return [
            ['lrmi:educationalLevel', 'cur:Curso'],
            ['schema:about', 'cur:MateriaCurso'],
            ['lrmi:teaches', 'cur:SaberBasico'],
            ['lrmi:assesses', 'cur:CriterioEvaluacion'],
        ];
    }

    #[DataProvider('dimensions')]
    public function testEveryRecatalogDimensionHasItsClass(string $dimension, string $class): void
    {
        $this->assertSame($class, CurModel::classOf($dimension));
        $this->assertContains($dimension, CurModel::dimensions());
    }

    public function testUnknownDimensionsHaveNoClassScopeOrQualifier(): void
    {
        $this->assertNull(CurModel::classOf('dcterms:relation'));
        $this->assertSame([], CurModel::scopeEdges('dcterms:relation'));
        $this->assertNull(CurModel::scopeFor('dcterms:relation', ['etapa' => 1]));
        $this->assertNull(CurModel::parentEdge('dcterms:relation'));
        $this->assertSame([], CurModel::qualifierEdges('dcterms:relation'));
    }

    public function testScopeEdgesFollowTheOwnerCascade(): void
    {
        $this->assertSame(['etapa' => 'cur:etapa'], CurModel::scopeEdges('lrmi:educationalLevel'));
        $this->assertSame(['level' => 'cur:curso', 'etapa' => 'cur:etapa'], CurModel::scopeEdges('schema:about'));
        $leaf = ['about' => 'cur:materiaCurso', 'level' => 'cur:curso', 'etapa' => 'cur:etapa'];
        $this->assertSame($leaf, CurModel::scopeEdges('lrmi:teaches'));
        $this->assertSame($leaf, CurModel::scopeEdges('lrmi:assesses'));
        $this->assertSame('cur:etapa', CurModel::parentEdge('lrmi:educationalLevel'));
        $this->assertSame('cur:curso', CurModel::parentEdge('schema:about'));
        $this->assertSame('cur:materiaCurso', CurModel::parentEdge('lrmi:teaches'));
    }

    public function testScopeForPicksTheClosestChosenAncestorAndKeepsEveryId(): void
    {
        $context = ['etapa' => '3', 'level' => ['5', '6', '5'], 'about' => ''];
        $this->assertSame(['cur:curso', [5, 6]], CurModel::scopeFor('lrmi:teaches', $context));
        $this->assertSame(['cur:materiaCurso', [9]], CurModel::scopeFor('lrmi:teaches', ['about' => 9] + $context));
        $this->assertSame(['cur:etapa', [3]], CurModel::scopeFor('lrmi:educationalLevel', $context));
        $this->assertNull(CurModel::scopeFor('lrmi:educationalLevel', ['level' => 5]));
        $this->assertNull(CurModel::scopeFor('schema:about', []));
    }

    public function testLeavesAreQualifiedByTheirCourseSubjectOnly(): void
    {
        $this->assertSame([], CurModel::qualifierEdges('lrmi:educationalLevel'));
        $this->assertSame([], CurModel::qualifierEdges('schema:about'));
        $this->assertSame(['cur:materiaCurso'], CurModel::qualifierEdges('lrmi:teaches'));
        $this->assertSame(['cur:materiaCurso'], CurModel::qualifierEdges('lrmi:assesses'));
        $this->assertSame('cur:curso', CurModel::COURSE_EDGE);
    }

    public function testTermsQueryIsBoundedToCurrentTermsOfOneClassInTheScheme(): void
    {
        $this->assertSame([
            'item_set_id' => 46259,
            'resource_class_id' => 4764,
            'property' => [
                ['property' => 'skos:changeNote', 'type' => 'nex'],
            ],
            'sort_by' => 'title',
            'sort_order' => 'asc',
            'page' => 1,
            'per_page' => 25,
        ], CurModel::termsQuery(46259, 4764));
    }

    public function testTermsQueryFiltersByPrefLabelAndPaginates(): void
    {
        $query = CurModel::termsQuery(1, 2, '  geometría ', 10, 3);
        $this->assertSame(
            ['property' => 'skos:prefLabel', 'type' => 'in', 'text' => 'geometría', 'joiner' => 'and'],
            $query['property'][1]
        );
        $this->assertSame(3, $query['page']);
        $this->assertSame(10, $query['per_page']);
        $this->assertSame(1, CurModel::termsQuery(1, 2, '', 0, 0)['per_page']);
        $this->assertSame(1, CurModel::termsQuery(1, 2, '', 0, 0)['page']);
    }

    public function testScopeRowsAreScalarAndAppended(): void
    {
        $base = CurModel::termsQuery(1, 2);
        $scoped = CurModel::withResource($base, 'cur:curso', 7);
        $this->assertSame(
            ['property' => 'cur:curso', 'type' => 'res', 'text' => '7', 'joiner' => 'and'],
            end($scoped['property'])
        );
        $named = CurModel::withLabel($scoped, 'cur:materia', ' Matemáticas (ESO) ');
        $this->assertSame(
            ['property' => 'cur:materia', 'type' => 'eq', 'text' => 'Matemáticas (ESO)', 'joiner' => 'and'],
            end($named['property'])
        );
        $this->assertCount(3, $named['property']);
    }

    public function testLookupQueriesForSchemeRetiredSetAndClass(): void
    {
        $this->assertSame([
            'property' => [['property' => 'dcterms:identifier', 'type' => 'ew', 'text' => '/id/curriculo']],
            'page' => 1,
            'per_page' => 10,
        ], CurModel::schemeQuery());
        $this->assertSame([
            'property' => [[
                'property' => 'dcterms:identifier',
                'type' => 'eq',
                'text' => 'https://example.org/id/curriculo/retirados',
            ]],
            'page' => 1,
            'per_page' => 1,
        ], CurModel::retiredSetQuery('https://example.org/id/curriculo'));
        $this->assertSame(['term' => 'cur:Curso'], CurModel::classQuery('cur:Curso'));
    }

    public function testSchemeUriIsTheIdentifierUriEndingInCurriculo(): void
    {
        $set = $this->item(46259, 'Currículo', ['dcterms:identifier' => [
            $this->value('cur', null, 'literal'),
            $this->value('', null, 'uri', 'https://example.org/id/curriculo/retirados'),
            $this->value('', null, 'uri', 'https://example.org/id/curriculo'),
        ]]);
        $this->assertSame('https://example.org/id/curriculo', CurModel::schemeUri($set));
        $this->assertTrue(CurModel::isSchemeUri('https://x/id/curriculo'));
        $this->assertFalse(CurModel::isSchemeUri('https://x/id/curriculo/retirados'));
        $this->assertSame('', CurModel::schemeUri($this->item(1)));
    }

    public function testLiteralReadersPreferSkosAndFallBackToTheTitle(): void
    {
        $item = $this->item(1, 'Display', [
            'skos:prefLabel' => [$this->value('', null, 'uri'), $this->value(' SMAT01CE1.1 · Interpretar… ')],
            'skos:notation' => [$this->value('SMAT01CE1.1')],
            'skos:definition' => [$this->value('Interpretar problemas')],
        ]);
        $this->assertSame('SMAT01CE1.1 · Interpretar…', CurModel::label($item));
        $this->assertSame('SMAT01CE1.1', CurModel::notation($item));
        $this->assertSame('Interpretar problemas', CurModel::definition($item));
        $bare = $this->item(2, ' Bare ');
        $this->assertSame('Bare', CurModel::label($bare));
        $this->assertSame('', CurModel::notation($bare));
        $this->assertSame('', CurModel::definition($bare));
    }

    public function testRetirementAndReplacement(): void
    {
        $successor = $this->item(8, 'New');
        $retired = $this->item(1, 'Old', [
            'skos:changeNote' => [$this->value('Retirado en la versión 1.2.0')],
            'dcterms:isReplacedBy' => [$this->value(), $this->value('', $successor, 'resource:item')],
        ]);
        $this->assertTrue(CurModel::isRetired($retired));
        $this->assertSame([8], CurModel::replacedBy($retired));
        $this->assertFalse(CurModel::isRetired($this->item(2)));
        $this->assertSame([], CurModel::replacedBy($this->item(2)));
    }

    public function testRefsReadResourceEdges(): void
    {
        $course = $this->item(5, '1º ESO');
        $other = $this->item(6, '2º ESO');
        $item = $this->item(1, 'Materia', ['cur:curso' => [
            $this->value('literal'),
            $this->value('', $course, 'resource:item'),
            $this->value('', $other, 'resource:item'),
        ]]);
        $this->assertSame(['id' => 5, 'title' => '1º ESO'], CurModel::ref($item, 'cur:curso'));
        $this->assertSame(
            [['id' => 5, 'title' => '1º ESO'], ['id' => 6, 'title' => '2º ESO']],
            CurModel::refs($item, 'cur:curso')
        );
        $this->assertSame(['id' => 0, 'title' => ''], CurModel::ref($item, 'cur:etapa'));
        $this->assertSame($course, CurModel::target($item, 'cur:curso'));
        $this->assertNull(CurModel::target($item, 'cur:etapa'));
    }

    public function testClassTermIsEmptyWithoutClass(): void
    {
        $this->assertSame('', CurModel::classTerm($this->item(1)));
        $this->assertSame('cur:Curso', CurModel::classTerm($this->curItem(1, 'cur:Curso')));
    }

    public function testCriterionBlockIsItsCompetenceBlock(): void
    {
        $block = $this->curItem(30, 'cur:BloqueCompetencial', 'Matemáticas · 1º ESO · bloque competencial C1');
        $criterion = $this->curItem(1, 'cur:CriterioEvaluacion', 'SMAT01CE1.1', [
            'cur:bloqueCompetencial' => [$this->value('', $block, 'resource:item')],
        ]);
        $this->assertSame('Matemáticas · 1º ESO · bloque competencial C1', CurModel::block($criterion));
    }

    public function testKnowledgeBlockIsItsKnowledgeBlockAtAnyDepth(): void
    {
        $block = $this->curItem(40, 'cur:BloqueSaberes', 'SMAT01SBI · I. Sentido numérico');
        $parent = $this->curItem(41, 'cur:SaberBasico', 'SMAT01SBI.1', [
            'skos:broader' => [$this->value('', $block, 'resource:item')],
        ]);
        $leaf = $this->curItem(42, 'cur:SaberBasico', 'SMAT01SBI.1.1', [
            'skos:broader' => [$this->value('', $parent, 'resource:item')],
        ]);
        $this->assertSame('SMAT01SBI · I. Sentido numérico', CurModel::block($leaf));
        $this->assertSame('SMAT01SBI · I. Sentido numérico', CurModel::block($parent));
    }

    public function testBlockIsEmptyForOtherClassesAndBrokenChains(): void
    {
        $this->assertSame('', CurModel::block($this->curItem(1, 'cur:Curso', '1º ESO')));
        $orphan = $this->curItem(2, 'cur:SaberBasico', 'X');
        $this->assertSame('', CurModel::block($orphan));
        // A loop in skos:broader must not hang the reader.
        $loop = $this->curItem(3, 'cur:SaberBasico', 'Loop');
        $self = $this->curItem(4, 'cur:SaberBasico', 'Self', [
            'skos:broader' => [$this->value('', $loop, 'resource:item')],
        ]);
        $deep = $self;
        for ($i = 0; $i < 10; $i++) {
            $deep = $this->curItem(100 + $i, 'cur:SaberBasico', 'D' . $i, [
                'skos:broader' => [$this->value('', $deep, 'resource:item')],
            ]);
        }
        $this->assertSame('', CurModel::block($deep));
    }
}
