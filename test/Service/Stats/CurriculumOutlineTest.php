<?php

declare(strict_types=1);

namespace OERManager\Test\Service\Stats;

use OERManager\Service\Curriculum\CurModel;
use OERManager\Service\Curriculum\CurriculumTerms;
use OERManager\Service\Stats\CurriculumOutline;
use OERManager\Test\Support\RepresentationFactory;
use Omeka\Api\Manager;
use PHPUnit\Framework\TestCase;

/**
 * TASK-064 slice 5: the statistics outline reads the `cur:` model — every
 * current cur:Curso and cur:MateriaCurso of the curriculum item set, stages by
 * `cur:etapa`, subjects by `cur:curso` — with the CurModel query builders.
 */
final class CurriculumOutlineTest extends TestCase
{
    use RepresentationFactory;

    private const SCHEME = 46259;
    private const CURSO_CLASS = 4764;
    private const MC_CLASS = 4766;

    private array $searches = [];

    private function node(int $id, string $class, string $label, string $notation = '', array $edges = [])
    {
        $values = ['skos:prefLabel' => [$this->value($label)]];
        if ('' !== $notation) {
            $values['skos:notation'] = [$this->value($notation)];
        }
        foreach ($edges as $edge => $target) {
            $values[$edge] = [$this->value('', $target, 'resource:item')];
        }
        return $this->curItem($id, $class, $label, $values);
    }

    private function terms(?int $scheme = self::SCHEME): CurriculumTerms
    {
        $terms = $this->createMock(CurriculumTerms::class);
        $terms->method('schemeId')->willReturn($scheme);
        $terms->method('classId')->willReturnCallback(static fn (string $term): ?int => match ($term) {
            CurModel::CURSO => self::CURSO_CLASS,
            CurModel::MATERIA_CURSO => self::MC_CLASS,
            default => null,
        });
        return $terms;
    }

    /** @param array<string,list<mixed>> $byClass */
    private function api(array $byClass, array $byId = []): Manager
    {
        $api = $this->createMock(Manager::class);
        $api->method('search')->willReturnCallback(function ($resource, $query) use ($byClass, $byId) {
            $this->searches[] = $query;
            if (isset($query['id'])) {
                return $this->response(array_values(array_intersect_key($byId, array_flip($query['id']))));
            }
            return $this->response($byClass[$query['resource_class_id'] ?? 0] ?? []);
        });
        return $api;
    }

    public function testOutlineIsTheCurrentCoursesAndCourseSubjectsInPedagogicalOrder(): void
    {
        $bac = $this->node(1, CurModel::ETAPA, 'Bachillerato', 'BAC');
        $eso = $this->node(2, CurModel::ETAPA, 'Educación Secundaria Obligatoria', 'ESO');
        $pri = $this->node(4, CurModel::ETAPA, 'Educación Primaria', 'PRI');
        $b1 = $this->node(10, CurModel::CURSO, '1º Bachillerato', 'B01', ['cur:etapa' => $bac]);
        $s2 = $this->node(11, CurModel::CURSO, '2º ESO', 'S02', ['cur:etapa' => $eso]);
        $s1 = $this->node(12, CurModel::CURSO, '1º ESO', 'S01', ['cur:etapa' => $eso]);
        $p1 = $this->node(13, CurModel::CURSO, '1º Primaria', 'P01', ['cur:etapa' => $pri]);
        $math = $this->node(20, CurModel::MATERIA_CURSO, 'Matemáticas · 1º ESO', 'SMAT01', ['cur:curso' => $s1]);
        $legacy = $this->item(30, 'Old course');

        $outline = new CurriculumOutline(
            $this->api([self::CURSO_CLASS => [$b1, $s2, $s1, $p1], self::MC_CLASS => [$math]], [30 => $legacy]),
            $this->terms()
        );
        $map = $outline->load([30, 12], [20], [99]);

        $this->assertSame([4, 2, 1], $map->orderedStageIds());
        $this->assertSame([13, 12, 11, 10, 30], $map->orderedCourseIds());
        $this->assertSame(12, $map->courseOfSubject(20));
        $this->assertSame('Matemáticas · 1º ESO', $map->qualifiedSubjectLabel(20));
        $this->assertSame('Educación Secundaria Obligatoria', $map->label(2));
        $this->assertSame('Old course', $map->label(30));
        $this->assertNull($map->stageOfCourse(30));
        $this->assertTrue($map->hasUniverse());
        $this->assertSame([
            CurModel::termsQuery(self::SCHEME, self::CURSO_CLASS, '', CurriculumOutline::LEVEL_CAP),
            CurModel::termsQuery(self::SCHEME, self::MC_CLASS, '', CurriculumOutline::LEVEL_CAP),
            ['id' => [30, 99]],
        ], $this->searches);
    }

    public function testLinkedOtherTermsOnlyNeedALabel(): void
    {
        $axis = $this->item(50, 'Eje verde');
        $map = (new CurriculumOutline($this->api([], [50 => $axis]), $this->terms()))->load([], [], [50]);
        $this->assertSame('Eje verde', $map->label(50));
        $this->assertFalse($map->hasUniverse());
    }

    public function testWithoutCurriculumOnlyTheLinkedTermsAreRead(): void
    {
        $retired = $this->node(60, CurModel::MATERIA_CURSO, 'Vieja · 1º ESO', '', []);
        $outline = new CurriculumOutline($this->api([], [60 => $retired]), $this->terms(null));
        $map = $outline->load([], [60], []);
        $this->assertSame([['id' => [60]]], $this->searches);
        $this->assertSame('Vieja · 1º ESO', $map->qualifiedSubjectLabel(60));
        $this->assertNull($map->courseOfSubject(60));
        $this->assertFalse($map->hasUniverse());
    }
}
