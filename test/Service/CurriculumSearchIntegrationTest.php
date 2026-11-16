<?php

declare(strict_types=1);

namespace OERManager\Test\Service;

use OERManager\Service\Curriculum\CurModel;
use OERManager\Service\Curriculum\CurriculumTerms;
use OERManager\Service\CurriculumSearch;
use OERManager\Test\Support\RepresentationFactory;
use Omeka\Api\Manager;
use Omeka\Settings\Settings;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * TASK-064 slice 3: the re-cataloguer cascade on the `cur:` model (ADR-0022).
 * Every query is compared with the CurModel builder that defines it.
 */
final class CurriculumSearchIntegrationTest extends TestCase
{
    use RepresentationFactory;

    private const SCHEME = 46259;
    private const CLASS_IDS = [
        CurModel::ETAPA => 4763,
        CurModel::CURSO => 4764,
        CurModel::MATERIA => 4765,
        CurModel::MATERIA_CURSO => 4766,
        CurModel::CRITERIO => 4769,
        CurModel::SABER => 4771,
    ];

    private $api;
    private $terms;
    private Settings $settings;
    private CurriculumSearch $search;
    private array $queries = [];
    /** @var callable(array):array */
    private $answer;

    protected function setUp(): void
    {
        $this->api = $this->createMock(Manager::class);
        $this->terms = $this->createMock(CurriculumTerms::class);
        $this->terms->method('schemeId')->willReturn(self::SCHEME);
        $this->terms->method('classId')->willReturnCallback(static fn (string $term) => self::CLASS_IDS[$term] ?? null);
        $this->settings = new Settings();
        $this->answer = static fn (array $query): array => [];
        $this->api->method('search')->willReturnCallback(function ($resource, $query) {
            $this->assertSame('items', $resource);
            $this->queries[] = $query;
            return $this->response(($this->answer)($query));
        });
        $this->search = new CurriculumSearch($this->api, $this->settings, $this->terms);
    }

    private function base(string $class, string $text = '', int $limit = 25): array
    {
        return CurModel::termsQuery(self::SCHEME, self::CLASS_IDS[$class], $text, $limit);
    }

    private function link($target): array
    {
        return [$this->value('', $target, 'resource:item')];
    }

    private function stage(int $id, string $notation, string $label)
    {
        return $this->curItem($id, CurModel::ETAPA, $label, [
            'skos:prefLabel' => [$this->value($label)],
            'skos:notation' => [$this->value($notation)],
        ]);
    }

    public function testWithoutSchemeOrClassNothingIsSearched(): void
    {
        $terms = $this->createMock(CurriculumTerms::class);
        $terms->method('schemeId')->willReturn(null);
        $terms->method('classId')->willReturn(7);
        $search = new CurriculumSearch($this->api, $this->settings, $terms);
        $this->assertSame([], $search->searchEtapas(''));
        $this->assertSame([], $search->searchDimension('lrmi:educationalLevel', ''));
        $this->assertSame([], $search->searchSubjectFamilies(1));
        $this->assertSame([], $search->searchLeaves('lrmi:teaches', 1, 'Math'));

        $noClass = $this->createMock(CurriculumTerms::class);
        $noClass->method('schemeId')->willReturn(self::SCHEME);
        $noClass->method('classId')->willReturn(null);
        $this->assertSame([], (new CurriculumSearch($this->api, $this->settings, $noClass))->searchEtapas(''));

        $this->assertSame([], $this->search->searchDimension('unknown', ''));
        $this->assertSame([], $this->search->searchDimension('dcterms:relation', ''));
        $this->assertSame([], $this->search->searchSubjectFamilies(0));
        $this->assertSame([], $this->search->searchLeaves('unknown', 1, 'Math'));
        $this->assertSame([], $this->search->searchLeaves('lrmi:teaches', 0, 'Math'));
        $this->assertSame([], $this->search->searchLeaves('lrmi:teaches', 1, ' '));
        $this->assertSame([], $this->search->searchAxes(''));
        $this->assertSame([], $this->queries);
    }

    public function testStagesAreCurrentTermsInPedagogicalOrder(): void
    {
        $this->answer = fn (): array => [
            $this->stage(1, 'BAC', 'Bachillerato'),
            $this->stage(2, 'ESO', 'Educación Secundaria Obligatoria'),
            $this->stage(3, 'INF', 'Educación Infantil'),
            $this->stage(4, 'PRI', 'Educación Primaria'),
        ];
        $result = $this->search->searchEtapas(' Educ ', 3);
        $this->assertSame([$this->base(CurModel::ETAPA, 'Educ', 3)], $this->queries);
        $this->assertSame([3, 4, 2, 1], array_column($result, 'id'));
        $this->assertSame('INF', $result[0]['notation']);
        $this->assertSame('Educación Infantil', $result[0]['title']);
        $this->assertSame(0, $result[0]['parentId']);
    }

    public function testCoursesOfSeveralStagesAreMergedAndOrderedByStageThenNotation(): void
    {
        $eso = $this->stage(2, 'ESO', 'ESO');
        $pri = $this->stage(4, 'PRI', 'Primaria');
        $course = fn (int $id, string $notation, $stage) => $this->curItem($id, CurModel::CURSO, $notation, [
            'skos:notation' => [$this->value($notation)],
            'cur:etapa' => $this->link($stage),
        ]);
        $s2 = $course(12, 'S02', $eso);
        $s1 = $course(11, 'S01', $eso);
        $p6 = $course(26, 'P06', $pri);
        $p1 = $course(21, 'P01', $pri);
        $this->answer = static fn (array $query): array => '2' === end($query['property'])['text']
            ? [$s2, $s1] : [$p6, $p1, $s1];

        $result = $this->search->searchDimension('lrmi:educationalLevel', '', ['etapa' => ['2', 4, '2']], 25);

        $this->assertSame([
            CurModel::withResource($this->base(CurModel::CURSO), 'cur:etapa', 2),
            CurModel::withResource($this->base(CurModel::CURSO), 'cur:etapa', 4),
        ], $this->queries);
        $this->assertSame([21, 26, 11, 12], array_column($result, 'id'));
        $this->assertSame(4, $result[0]['parentId']);
        $this->assertSame('Primaria', $result[0]['parentTitle']);
        $this->assertArrayNotHasKey('stageNotation', $result[0]);
    }

    public static function scopes(): array
    {
        return [
            'subject by course' => ['schema:about', CurModel::MATERIA_CURSO, ['level' => 5], 'cur:curso', 5],
            'subject by stage' => ['schema:about', CurModel::MATERIA_CURSO, ['etapa' => 2], 'cur:etapa', 2],
            'knowledge by subject' => ['lrmi:teaches', CurModel::SABER, ['about' => 9, 'level' => 5],
                'cur:materiaCurso', 9],
            'criteria by course' => ['lrmi:assesses', CurModel::CRITERIO, ['level' => 5, 'etapa' => 2],
                'cur:curso', 5],
            'criteria by stage' => ['lrmi:assesses', CurModel::CRITERIO, ['etapa' => 2], 'cur:etapa', 2],
        ];
    }

    #[DataProvider('scopes')]
    public function testEachDimensionIsScopedByItsClosestChosenAncestor(
        string $dimension,
        string $class,
        array $context,
        string $edge,
        int $ancestor
    ): void {
        $this->search->searchDimension($dimension, 'geo', $context, 10);
        $this->assertSame([CurModel::withResource($this->base($class, 'geo', 10), $edge, $ancestor)], $this->queries);
    }

    public function testLeafRowsCarryDefinitionBlockAndCourseSubject(): void
    {
        $subject = $this->curItem(9, CurModel::MATERIA_CURSO, 'Matemáticas · 1º ESO');
        $block = $this->curItem(40, CurModel::BLOQUE_SABERES, 'SMAT01SBI · I. Sentido numérico');
        $leaf = $this->curItem(41, CurModel::SABER, 'SMAT01SBI.1 · Conteo…', [
            'skos:notation' => [$this->value('SMAT01SBI.1')],
            'skos:definition' => [$this->value('Conteo y estimación.')],
            'skos:broader' => $this->link($block),
            'cur:materiaCurso' => $this->link($subject),
        ]);
        $this->answer = static fn (): array => [$leaf];
        $row = $this->search->searchDimension('lrmi:teaches', '', ['about' => 9])[0];
        $this->assertSame([
            'id' => 41,
            'title' => 'SMAT01SBI.1 · Conteo…',
            'notation' => 'SMAT01SBI.1',
            'description' => 'Conteo y estimación.',
            'block' => 'SMAT01SBI · I. Sentido numérico',
            'parentId' => 9,
            'parentTitle' => 'Matemáticas · 1º ESO',
        ], $row);
    }

    public function testUnscopedSearchIsOneBoundedQueryAndMergedResultsRespectTheLimit(): void
    {
        $this->answer = fn (): array => [
            $this->curItem(3, CurModel::MATERIA_CURSO, 'Matemáticas · 2º ESO'),
            $this->curItem(2, CurModel::MATERIA_CURSO, 'Matemáticas · 10º X'),
            $this->curItem(1, CurModel::MATERIA_CURSO, 'Matemáticas · 1º ESO'),
        ];
        $this->assertSame(
            [1, 3, 2],
            array_column($this->search->searchDimension('schema:about', 'mat'), 'id')
        );
        $this->assertSame([$this->base(CurModel::MATERIA_CURSO, 'mat')], $this->queries);
        $this->assertSame(
            [1],
            array_column($this->search->searchDimension('schema:about', '', ['level' => [5, 6]], 1), 'id')
        );
    }

    public function testAxesKeepTheirConfiguredTermSet(): void
    {
        $this->settings->set(CurriculumSearch::AXIS_SETTING, 9);
        $this->answer = fn (): array => [$this->item(5, 'Axis', ['dcterms:description' => [
            $this->value('', null, 'uri'),
            $this->value('Axis text'),
        ]]), $this->item(6, 'Bare')];
        $result = $this->search->searchAxes(' Cultura ', 3);
        $this->assertSame([
            'property' => [
                ['property' => 'schema:inDefinedTermSet', 'type' => 'res', 'text' => '9'],
                ['property' => 'dcterms:title', 'type' => 'in', 'text' => 'Cultura'],
            ],
            'sort_by' => 'title',
            'sort_order' => 'asc',
            'page' => 1,
            'per_page' => 3,
        ], $this->queries[0]);
        $this->assertSame(5, $result[0]['id']);
        $this->assertSame('Axis', $result[0]['title']);
        $this->assertSame('Axis text', $result[0]['description']);
        $this->assertSame('', $result[1]['description']);
    }

    /** ADR-0022 §5: subject families are the cur:Materia of the stage, with their courses. */
    public function testFamiliesAreTheMateriasOfTheStageWithTheirCourses(): void
    {
        $third = $this->curItem(13, CurModel::CURSO, '3º ESO');
        $fourth = $this->curItem(14, CurModel::CURSO, '4º ESO');
        $math = $this->curItem(70, CurModel::MATERIA, 'Matemáticas (ESO)', [
            'skos:prefLabel' => [$this->value('Matemáticas (ESO)')],
            'cur:curso' => [...$this->link($third), ...$this->link($fourth)],
        ]);
        $this->answer = fn (): array => [$math, $this->curItem(71, CurModel::MATERIA, ' ')];

        $this->assertSame(
            [['name' => 'Matemáticas (ESO)', 'id' => 70, 'courses' => [
                ['id' => 13, 'title' => '3º ESO'],
                ['id' => 14, 'title' => '4º ESO'],
            ]]],
            $this->search->searchSubjectFamilies(2, 300)
        );
        $this->assertSame(
            [CurModel::withResource($this->base(CurModel::MATERIA, '', 300), 'cur:etapa', 2)],
            $this->queries
        );
    }

    /** The classifier's leaves: stage + Materia name + optional courses, one query per course. */
    public function testLeavesForTheClassifierAreBoundToStageMateriaAndCourses(): void
    {
        $course = $this->curItem(13, CurModel::CURSO, '3º ESO');
        $subject = $this->curItem(90, CurModel::MATERIA_CURSO, 'Matemáticas · 3º ESO');
        $block = $this->curItem(30, CurModel::BLOQUE_COMPETENCIAL, 'Bloque C1');
        $criterion = $this->curItem(8, CurModel::CRITERIO, 'SMAT03CE1.1 · Interpretar…', [
            'skos:definition' => [$this->value('Interpretar problemas.')],
            'cur:bloqueCompetencial' => $this->link($block),
            'cur:curso' => $this->link($course),
            'cur:materiaCurso' => $this->link($subject),
        ]);
        $other = $this->curItem(9, CurModel::CRITERIO, 'SMAT04CE1.1');
        $this->answer = static fn (array $query): array => '13' === end($query['property'])['text']
            ? [$criterion, $other] : [$criterion];

        $result = $this->search->searchLeaves('lrmi:assesses', 2, ' Matemáticas (ESO) ', 300, [13, '14', 13]);

        $scoped = CurModel::withLabel(
            CurModel::withResource($this->base(CurModel::CRITERIO, '', 300), 'cur:etapa', 2),
            'cur:materia',
            'Matemáticas (ESO)'
        );
        $this->assertSame([
            CurModel::withResource($scoped, 'cur:curso', 13),
            CurModel::withResource($scoped, 'cur:curso', 14),
        ], $this->queries);
        $this->assertSame([8, 9], array_column($result, 'id'));
        $this->assertSame([
            'id' => 8,
            'title' => 'SMAT03CE1.1 · Interpretar…',
            'notation' => '',
            'description' => 'Interpretar problemas.',
            'block' => 'Bloque C1',
            'courseId' => 13,
            'courseTitle' => '3º ESO',
            'subjectId' => 90,
        ], $result[0]);
        $this->assertSame(0, $result[1]['subjectId']);

        $this->queries = [];
        $this->search->searchLeaves('lrmi:teaches', 2, 'Matemáticas (ESO)');
        $this->assertSame([CurModel::withLabel(
            CurModel::withResource($this->base(CurModel::SABER), 'cur:etapa', 2),
            'cur:materia',
            'Matemáticas (ESO)'
        )], $this->queries);
    }
}
