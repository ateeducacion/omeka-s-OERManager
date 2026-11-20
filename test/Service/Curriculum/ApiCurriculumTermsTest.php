<?php

declare(strict_types=1);

namespace OERManager\Test\Service\Curriculum;

use OERManager\Service\Curriculum\ApiCurriculumTerms;
use OERManager\Service\Curriculum\CurModel;
use OERManager\Service\Curriculum\CurriculumTerms;
use OERManager\Test\Support\RepresentationFactory;
use Omeka\Api\Manager;
use Omeka\Settings\Settings;
use PHPUnit\Framework\TestCase;

final class ApiCurriculumTermsTest extends TestCase
{
    use RepresentationFactory;

    private const BASE = 'https://example.org/curriculo/';

    private $api;
    private Settings $settings;
    private array $sets;
    private array $searches = [];
    private array $reads = [];

    protected function setUp(): void
    {
        $this->api = $this->createMock(Manager::class);
        $this->settings = new Settings();
        $this->sets = [
            46259 => $this->set(46259, self::BASE . 'id/curriculo'),
            46260 => $this->set(46260, self::BASE . 'id/curriculo/retirados'),
            7 => $this->set(7, 'https://example.org/other'),
        ];
        $this->api->method('read')->willReturnCallback(function ($resource, $id) {
            $this->assertSame('item_sets', $resource);
            $this->reads[] = $id;
            if (!isset($this->sets[$id])) {
                throw new \RuntimeException('missing');
            }
            return $this->response($this->sets[$id]);
        });
        $this->api->method('search')->willReturnCallback(function ($resource, $query) {
            $this->searches[] = [$resource, $query];
            if ('resource_classes' === $resource) {
                return $this->response('cur:Curso' === $query['term'] ? [$this->item(4764)] : []);
            }
            if ($query === CurModel::schemeQuery()) {
                return $this->response([$this->sets[46260], $this->sets[46259]]);
            }
            if ($query === CurModel::retiredSetQuery(self::BASE . 'id/curriculo')) {
                return $this->response([$this->sets[46260]]);
            }
            return $this->response([]);
        });
    }

    private function set(int $id, string $uri)
    {
        return $this->item($id, 'Set ' . $id, ['dcterms:identifier' => [$this->value('', null, 'uri', $uri)]]);
    }

    private function terms(): CurriculumTerms
    {
        return new ApiCurriculumTerms($this->api, $this->settings);
    }

    public function testConfiguredSchemeIsValidatedAndCachedForTheRequest(): void
    {
        $this->settings->set(CurriculumTerms::SCHEME_SETTING, '46259');
        $terms = $this->terms();
        $this->assertSame(46259, $terms->schemeId());
        $this->assertSame(46259, $terms->schemeId());
        $this->assertSame([46259], $this->reads);
        $this->assertSame([], $this->searches);
    }

    public function testInvalidOrMissingConfigurationFallsBackToDetection(): void
    {
        $this->settings->set(CurriculumTerms::SCHEME_SETTING, 7);
        $this->assertSame(46259, $this->terms()->schemeId());
        $this->settings->set(CurriculumTerms::SCHEME_SETTING, 999);
        $this->assertSame(46259, $this->terms()->schemeId());
        $this->settings->set(CurriculumTerms::SCHEME_SETTING, null);
        $this->assertSame(46259, $this->terms()->schemeId());
    }

    public function testIsSchemeRequiresTheCurriculumIdentifier(): void
    {
        $terms = $this->terms();
        $this->assertTrue($terms->isScheme(46259));
        $this->assertFalse($terms->isScheme(46260));
        $this->assertFalse($terms->isScheme(7));
        $this->assertFalse($terms->isScheme(999));
        $this->assertFalse($terms->isScheme(0));
    }

    public function testDetectionRefusesAnAmbiguousOrMissingScheme(): void
    {
        $this->sets[8] = $this->set(8, 'https://other.org/id/curriculo');
        $api = $this->createMock(Manager::class);
        $api->method('search')->willReturnOnConsecutiveCalls(
            $this->response([$this->sets[46259], $this->sets[8]]),
            $this->response([]),
            $this->response([$this->sets[46259], $this->sets[46259]])
        );
        $this->assertNull((new ApiCurriculumTerms($api, $this->settings))->detectSchemeId());
        $this->assertNull((new ApiCurriculumTerms($api, $this->settings))->detectSchemeId());
        $this->assertSame(46259, (new ApiCurriculumTerms($api, $this->settings))->detectSchemeId());
    }

    public function testRetiredSetIsResolvedByTheSchemeUri(): void
    {
        $this->settings->set(CurriculumTerms::SCHEME_SETTING, 46259);
        $terms = $this->terms();
        $this->assertSame(46260, $terms->retiredSetId());
        $this->assertSame(46260, $terms->retiredSetId());
        $this->assertCount(1, $this->searches);
    }

    public function testNoSchemeMeansNoRetiredSet(): void
    {
        $api = $this->createMock(Manager::class);
        $api->method('search')->willReturn($this->response([]));
        $this->assertNull((new ApiCurriculumTerms($api, $this->settings))->retiredSetId());
        $this->assertSame([], (new ApiCurriculumTerms($api, $this->settings))->retiredIds());
    }

    public function testRetiredIdsAreOneScalarQueryPerRequest(): void
    {
        $this->settings->set(CurriculumTerms::SCHEME_SETTING, 46259);
        $options = [];
        $api = $this->createMock(Manager::class);
        $api->method('read')->willReturnCallback(fn ($resource, $id) => $this->response($this->sets[$id]));
        $api->method('search')->willReturnCallback(function ($resource, $query, $opts = []) use (&$options) {
            if ('item_sets' === $resource) {
                return $this->response([$this->sets[46260]]);
            }
            $options[] = [$resource, $query, $opts];
            return $this->response([12 => 12, '15' => '15']);
        });
        $terms = new ApiCurriculumTerms($api, $this->settings);
        $this->assertSame([12, 15], $terms->retiredIds());
        $this->assertSame([12, 15], $terms->retiredIds());
        $this->assertSame(
            [['items', CurModel::retiredMembersQuery(46260), ['returnScalar' => 'id']]],
            $options
        );
    }

    /**
     * A small course subject: two knowledge blocks with nested knowledge (one
     * at depth 2) and one competence block with two criteria.
     *
     * @return array{0:ApiCurriculumTerms,1:array<int,array>}
     */
    private function treeFixture(array $extraPages = []): array
    {
        $this->settings->set(CurriculumTerms::SCHEME_SETTING, 46259);
        $classIds = [
            'cur:BloqueSaberes' => 4770,
            'cur:SaberBasico' => 4771,
            'cur:BloqueCompetencial' => 4767,
            'cur:CriterioEvaluacion' => 4768,
        ];
        $course = $this->curItem(502, 'cur:Curso', '1º ESO', [], [46259]);
        $subject = $this->curItem(500, 'cur:MateriaCurso', 'Matemáticas · 1º ESO', [
            'skos:prefLabel' => [$this->value('Matemáticas · 1º ESO')],
            'cur:curso' => [$this->value('', $course, 'resource:item')],
        ], [46259]);
        $otherSet = $this->curItem(501, 'cur:MateriaCurso', 'Fuera', [], [7]);
        $lit = fn (string $text) => [$this->value($text)];
        $node = function (int $id, string $class, string $notation, $parent = null, string $edge = '') use ($lit) {
            $values = [
                'skos:notation' => $lit($notation),
                'skos:prefLabel' => $lit($notation . ' · texto'),
                'skos:definition' => $lit('Definición ' . $notation),
            ];
            if ($parent) {
                $values[$edge] = [$this->value('', $parent, 'resource:item')];
            }
            return $this->curItem($id, $class, $notation, $values, [46259]);
        };
        $b2 = $node(11, 'cur:BloqueSaberes', 'SMAT01SBII');
        $b1 = $node(10, 'cur:BloqueSaberes', 'SMAT01SBI');
        $s1 = $node(20, 'cur:SaberBasico', 'SMAT01SBI.1', $b1, 'skos:broader');
        $s11 = $node(21, 'cur:SaberBasico', 'SMAT01SBI.1.1', $s1, 'skos:broader');
        $s2 = $node(22, 'cur:SaberBasico', 'SMAT01SBII.1', $b2, 'skos:broader');
        $cb = $node(30, 'cur:BloqueCompetencial', 'SMAT01C1');
        $c2 = $node(41, 'cur:CriterioEvaluacion', 'SMAT01CE1.10', $cb, 'cur:bloqueCompetencial');
        $c1 = $node(40, 'cur:CriterioEvaluacion', 'SMAT01CE1.2', $cb, 'cur:bloqueCompetencial');
        $byClass = [
            4770 => [$b2, $b1],
            4771 => [$s11, $s2, $s1],
            4767 => [$cb],
            4768 => [$c2, $c1],
        ];
        $reads = [500 => $subject, 501 => $otherSet, 502 => $course];
        $api = $this->createMock(Manager::class);
        $api->method('read')->willReturnCallback(function ($resource, $id) use ($reads) {
            if ('item_sets' === $resource) {
                return $this->response($this->sets[$id]);
            }
            if (!isset($reads[$id])) {
                throw new \RuntimeException('missing');
            }
            return $this->response($reads[$id]);
        });
        $api->method('search')->willReturnCallback(function ($resource, $query) use ($classIds, $byClass, $extraPages) {
            $this->searches[] = [$resource, $query];
            if ('resource_classes' === $resource) {
                return $this->response(isset($classIds[$query['term']]) ? [$this->item($classIds[$query['term']])] : []);
            }
            $classId = $query['resource_class_id'];
            $page = $query['page'];
            if (isset($extraPages[$classId][$page])) {
                return $this->response($extraPages[$classId][$page]);
            }
            return $this->response(1 === $page ? ($byClass[$classId] ?? []) : []);
        });
        return [new ApiCurriculumTerms($api, $this->settings), $classIds];
    }

    public function testCourseSubjectTreeNestsKnowledgeAndCriteriaUnderTheirBlocks(): void
    {
        [$terms, $classIds] = $this->treeFixture();
        $tree = $terms->courseSubjectTree(500);

        $this->assertSame(['id' => 500, 'title' => 'Matemáticas · 1º ESO'], $tree['subject']);
        $teaches = $tree['lrmi:teaches'];
        $this->assertSame([10, 11], array_column($teaches, 'id'));
        $this->assertFalse($teaches[0]['selectable']);
        $this->assertSame('SMAT01SBI · texto', $teaches[0]['title']);
        $this->assertSame([20], array_column($teaches[0]['children'], 'id'));
        $this->assertTrue($teaches[0]['children'][0]['selectable']);
        $this->assertSame(10, $teaches[0]['children'][0]['parentId']);
        $this->assertSame('Definición SMAT01SBI.1', $teaches[0]['children'][0]['definition']);
        $this->assertSame([21], array_column($teaches[0]['children'][0]['children'], 'id'));
        $this->assertSame([22], array_column($teaches[1]['children'], 'id'));

        $assesses = $tree['lrmi:assesses'];
        $this->assertSame([30], array_column($assesses, 'id'));
        // Natural notation order: CE1.2 before CE1.10.
        $this->assertSame([40, 41], array_column($assesses[0]['children'], 'id'));

        $items = array_values(array_filter($this->searches, static fn ($s) => 'items' === $s[0]));
        $this->assertSame([
            CurModel::courseSubjectQuery(46259, $classIds['cur:BloqueSaberes'], 500, ApiCurriculumTerms::TREE_PAGE_SIZE),
            CurModel::courseSubjectQuery(46259, $classIds['cur:SaberBasico'], 500, ApiCurriculumTerms::TREE_PAGE_SIZE),
            CurModel::courseSubjectQuery(46259, $classIds['cur:BloqueCompetencial'], 500, ApiCurriculumTerms::TREE_PAGE_SIZE),
            CurModel::courseSubjectQuery(46259, $classIds['cur:CriterioEvaluacion'], 500, ApiCurriculumTerms::TREE_PAGE_SIZE),
        ], array_column($items, 1));
    }

    public function testCourseSubjectTreeReadsFurtherPagesOnlyWhenOneIsFull(): void
    {
        $full = [];
        for ($i = 0; $i < ApiCurriculumTerms::TREE_PAGE_SIZE; $i++) {
            $full[] = $this->curItem(1000 + $i, 'cur:BloqueSaberes', 'B' . $i, [], [46259]);
        }
        $last = [$this->curItem(5000, 'cur:BloqueSaberes', 'Z', [], [46259])];
        [$terms] = $this->treeFixture([4770 => [1 => $full, 2 => $last]]);
        $tree = $terms->courseSubjectTree(500);
        // The fixture's knowledge hangs from blocks that are not in these
        // pages: its two top items become roots (with their child), never dropped.
        $this->assertCount(ApiCurriculumTerms::TREE_PAGE_SIZE + 1 + 2, $tree['lrmi:teaches']);
        $pages = array_map(
            static fn ($s) => $s[1]['page'],
            array_values(array_filter($this->searches, static fn ($s) => 'items' === $s[0] && 4770 === $s[1]['resource_class_id']))
        );
        // Page 2 has one row (< page size), so there is no page 3.
        $this->assertSame([1, 2], $pages);
    }

    /** TASK-065 part B: the tree editor places each subject under its course. */
    public function testCourseSubjectTreeNamesTheCourseOfTheSubject(): void
    {
        [$terms] = $this->treeFixture();
        $tree = $terms->courseSubjectTree(500);

        $this->assertSame(['id' => 502, 'title' => '1º ESO'], $tree['course']);
    }

    public function testCourseSubjectTreeStopsAtThePageCap(): void
    {
        $this->settings->set(CurriculumTerms::SCHEME_SETTING, 46259);
        $subject = $this->curItem(500, 'cur:MateriaCurso', 'M', [], [46259]);
        $full = [];
        for ($i = 0; $i < ApiCurriculumTerms::TREE_PAGE_SIZE; $i++) {
            $full[] = $this->curItem(1000 + $i, 'cur:BloqueSaberes', 'B' . $i, [], [46259]);
        }
        $pages = [];
        $api = $this->createMock(Manager::class);
        $api->method('read')->willReturnCallback(
            fn ($resource, $id) => $this->response('item_sets' === $resource ? $this->sets[$id] : $subject)
        );
        $api->method('search')->willReturnCallback(function ($resource, $query) use ($full, &$pages) {
            if ('resource_classes' === $resource) {
                return $this->response('cur:BloqueSaberes' === $query['term'] ? [$this->item(4770)] : []);
            }
            $pages[] = $query['page'];
            return $this->response($full);
        });
        (new ApiCurriculumTerms($api, $this->settings))->courseSubjectTree(500);
        $this->assertSame(range(1, ApiCurriculumTerms::TREE_MAX_PAGES), $pages);
    }

    public function testCourseSubjectTreeRefusesAnythingButACourseSubjectOfTheCurriculum(): void
    {
        [$terms] = $this->treeFixture();
        $this->assertNull($terms->courseSubjectTree(501), 'course subject outside the curriculum set');
        $this->assertNull($terms->courseSubjectTree(502), 'another class');
        $this->assertNull($terms->courseSubjectTree(999), 'missing item');
        $this->assertNull($terms->courseSubjectTree(0));
        $this->assertNull($terms->courseSubjectTree(-3));
        $this->assertSame([], array_filter($this->searches, static fn ($s) => 'items' === $s[0]));
    }

    public function testCourseSubjectTreeNeedsACurriculum(): void
    {
        $api = $this->createMock(Manager::class);
        $api->method('search')->willReturn($this->response([]));
        $api->expects($this->never())->method('read');
        $this->assertNull((new ApiCurriculumTerms($api, $this->settings))->courseSubjectTree(500));
    }

    public function testRetiredTermsReadOnlyTheRetiredOnes(): void
    {
        $this->settings->set(CurriculumTerms::SCHEME_SETTING, 46259);
        $successor = $this->item(61, 'SMAT01SBI.2 · nuevo');
        $retired = $this->curItem(60, 'cur:SaberBasico', 'SMAT01SBI.9', [
            'skos:prefLabel' => [$this->value('SMAT01SBI.9 · viejo')],
            'dcterms:isReplacedBy' => [$this->value('', $successor, 'resource:item')],
        ]);
        $orphan = $this->curItem(62, 'cur:SaberBasico', 'Sin sustituto');
        $reads = [];
        $api = $this->createMock(Manager::class);
        $api->method('read')->willReturnCallback(function ($resource, $id) use ($retired, $orphan, &$reads) {
            if ('item_sets' === $resource) {
                return $this->response($this->sets[$id]);
            }
            $reads[] = $id;
            $found = [60 => $retired, 62 => $orphan][$id] ?? null;
            if (null === $found) {
                throw new \RuntimeException('missing');
            }
            return $this->response($found);
        });
        $api->method('search')->willReturnCallback(function ($resource) {
            return 'item_sets' === $resource
                ? $this->response([$this->sets[46260]])
                : $this->response([60, 62, 63]);
        });
        $terms = new ApiCurriculumTerms($api, $this->settings);
        $this->assertSame([
            ['id' => 60, 'title' => 'SMAT01SBI.9 · viejo', 'replacedBy' => ['SMAT01SBI.2 · nuevo']],
            ['id' => 62, 'title' => 'Sin sustituto', 'replacedBy' => []],
        ], $terms->retiredTerms([5, '60', 62, 63, 60, 0]));
        // 5 is current: never read. 63 is retired but unreadable: skipped.
        $this->assertSame([60, 62, 63], $reads);
        $this->assertSame([], $terms->retiredTerms([]));
    }

    public function testClassIdsAreResolvedByTermOnce(): void
    {
        $terms = $this->terms();
        $this->assertSame(4764, $terms->classId('cur:Curso'));
        $this->assertSame(4764, $terms->classId('cur:Curso'));
        $this->assertNull($terms->classId('cur:Nada'));
        $this->assertNull($terms->classId('cur:Nada'));
        $this->assertSame(
            [['resource_classes', CurModel::classQuery('cur:Curso')], ['resource_classes', CurModel::classQuery('cur:Nada')]],
            $this->searches
        );
    }
}
