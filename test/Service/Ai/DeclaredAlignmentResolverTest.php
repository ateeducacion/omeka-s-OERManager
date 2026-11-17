<?php

declare(strict_types=1);

namespace OERManager\Test\Service\Ai;

use OERManager\Service\Ai\DeclaredAlignmentResolver;
use OERManager\Service\Curriculum\CurModel;
use OERManager\Service\Curriculum\CurriculumTerms;
use Omeka\Api\Manager as ApiManager;
use Omeka\Api\Response;
use PHPUnit\Framework\TestCase;

/**
 * TASK-057 / RF-020 on the `cur:` model (TASK-064 slice 6, ADR-0022): the
 * declared alignment is resolved against the REAL curriculum — current terms of
 * the curriculum item set only. Knowledge and criteria by `skos:notation` (the
 * literal `dcterms:identifier` carries a prefix, `crit:SMAT01CE1.1`), the course
 * by its `skos:prefLabel`, the subject as the `cur:MateriaCurso` labelled
 * «<subject> · <course>» of that course. Nothing is invented: a code with no
 * match, or with more than one, is reported. Every query is a CurModel builder.
 */
final class DeclaredAlignmentResolverTest extends TestCase
{
    private const SCHEME = 46259;
    private const CLASS_IDS = [
        CurModel::CURSO => 4764,
        CurModel::MATERIA_CURSO => 4766,
        CurModel::CRITERIO => 4769,
        CurModel::SABER => 4771,
    ];

    /** @var list<array<string,mixed>> */
    private array $queries = [];

    public function testResolvesEveryDimensionAgainstTheCurriculum(): void
    {
        $resolver = new DeclaredAlignmentResolver($this->api([
            [$this->course('4º Primaria'), [46276]],
            [$this->subject('Matemáticas · 4º Primaria', 46276), [46572]],
            [$this->leaf(CurModel::SABER, 'PMAT04SBIV.1.1'), [30]],
            [$this->leaf(CurModel::CRITERIO, 'PMAT04CE4.1'), [40]],
        ]), $this->terms());

        $resolved = $resolver->resolve($this->declared());

        self::assertSame([46276], $resolved['lrmi:educationalLevel']);
        self::assertSame([46572], $resolved['schema:about']);
        self::assertSame([30], $resolved['lrmi:teaches']);
        self::assertSame([40], $resolved['lrmi:assesses']);
        self::assertSame([], $resolved['unresolved']);
        self::assertSame([
            $this->course('4º Primaria'),
            $this->subject('Matemáticas · 4º Primaria', 46276),
            $this->leaf(CurModel::SABER, 'PMAT04SBIV.1.1'),
            $this->leaf(CurModel::CRITERIO, 'PMAT04CE4.1'),
        ], $this->queries);
    }

    public function testEveryQueryKeepsToCurrentTermsOfTheCurriculumSet(): void
    {
        $resolver = new DeclaredAlignmentResolver($this->api([]), $this->terms());
        $resolver->resolve($this->declared());

        self::assertNotEmpty($this->queries);
        foreach ($this->queries as $query) {
            self::assertSame(self::SCHEME, $query['item_set_id']);
            self::assertContains(['property' => 'skos:changeNote', 'type' => 'nex'], $query['property']);
            $terms = array_column($query['property'], 'property');
            self::assertNotContains('dcterms:title', $terms);
            self::assertNotContains('dcterms:identifier', $terms);
        }
    }

    public function testDuplicatesAreMergedAndSeveralCoursesKeepTheirSubjects(): void
    {
        $resolver = new DeclaredAlignmentResolver($this->api([
            [$this->course('3º Primaria'), [46275]],
            [$this->course('4º Primaria'), [46276]],
            [$this->subject('Matemáticas · 3º Primaria', 46275), [46571]],
            [$this->subject('Matemáticas · 4º Primaria', 46276), [46572]],
            [$this->leaf(CurModel::SABER, 'PMAT03SBI.1'), [31]],
            [$this->leaf(CurModel::SABER, 'PMAT04SBI.1'), [31]],
        ]), $this->terms());

        $resolved = $resolver->resolve([
            'courses' => ['3º Primaria', '4º Primaria'],
            'subjects' => [
                ['course' => '3º Primaria', 'name' => 'Matemáticas'],
                ['course' => '4º Primaria', 'name' => 'Matemáticas'],
            ],
            'knowledge' => ['PMAT03SBI.1', 'PMAT04SBI.1'],
        ]);

        self::assertSame([46275, 46276], $resolved['lrmi:educationalLevel']);
        self::assertSame([46571, 46572], $resolved['schema:about']);
        self::assertSame([31], $resolved['lrmi:teaches']);
    }

    public function testMissingAndAmbiguousCodesAreReportedNotGuessed(): void
    {
        $resolver = new DeclaredAlignmentResolver($this->api([
            [$this->course('4º Primaria'), [10, 11]],
            [$this->leaf(CurModel::CRITERIO, 'PMAT04CE4.1'), [40, 41]],
        ]), $this->terms());

        $resolved = $resolver->resolve($this->declared());

        self::assertSame([], $resolved['lrmi:educationalLevel']);
        self::assertSame([], $resolved['schema:about']);
        self::assertSame([], $resolved['lrmi:teaches']);
        self::assertSame([], $resolved['lrmi:assesses']);
        self::assertSame([
            ['kind' => 'course', 'value' => '4º Primaria', 'reason' => 'ambiguous'],
            ['kind' => 'subject', 'value' => 'Matemáticas (4º Primaria)', 'reason' => 'no_course'],
            ['kind' => 'knowledge', 'value' => 'PMAT04SBIV.1.1', 'reason' => 'missing'],
            ['kind' => 'criterion', 'value' => 'PMAT04CE4.1', 'reason' => 'ambiguous'],
        ], $resolved['unresolved']);
    }

    public function testASubjectOfAnotherCourseIsMissing(): void
    {
        $resolver = new DeclaredAlignmentResolver($this->api([
            [$this->course('4º Primaria'), [46276]],
        ]), $this->terms());

        $resolved = $resolver->resolve(['courses' => ['4º Primaria'], 'subjects' => [
            ['course' => '4º Primaria', 'name' => 'Matemáticas'],
        ]]);

        self::assertSame(
            [['kind' => 'subject', 'value' => 'Matemáticas (4º Primaria)', 'reason' => 'missing']],
            $resolved['unresolved']
        );
    }

    public function testWithoutCurriculumNothingIsSearchedAndEverythingIsReported(): void
    {
        $terms = $this->createMock(CurriculumTerms::class);
        $terms->method('schemeId')->willReturn(null);
        $terms->method('classId')->willReturn(7);
        $api = $this->createMock(ApiManager::class);
        $api->expects(self::never())->method('search');

        $resolved = (new DeclaredAlignmentResolver($api, $terms))->resolve($this->declared());

        self::assertSame(
            ['no_curriculum'],
            array_values(array_unique(array_column($resolved['unresolved'], 'reason')))
        );
        self::assertSame(
            ['course', 'subject', 'knowledge', 'criterion'],
            array_column($resolved['unresolved'], 'kind')
        );
    }

    public function testAClassMissingFromThisInstanceReportsItsTermsOnly(): void
    {
        $terms = $this->createMock(CurriculumTerms::class);
        $terms->method('schemeId')->willReturn(self::SCHEME);
        $terms->method('classId')->willReturnCallback(
            static fn (string $term) => CurModel::SABER === $term ? null : (self::CLASS_IDS[$term] ?? null)
        );
        $resolver = new DeclaredAlignmentResolver($this->api([
            [$this->leaf(CurModel::CRITERIO, 'PMAT04CE4.1'), [40]],
        ]), $terms);

        $resolved = $resolver->resolve(['knowledge' => ['PMAT04SBIV.1.1'], 'criteria' => ['PMAT04CE4.1']]);

        self::assertSame([40], $resolved['lrmi:assesses']);
        self::assertSame(
            [['kind' => 'knowledge', 'value' => 'PMAT04SBIV.1.1', 'reason' => 'no_curriculum']],
            $resolved['unresolved']
        );
    }

    /** @return array<string,mixed> */
    private function declared(): array
    {
        return [
            'stage' => 'Educación Primaria',
            'courses' => ['4º Primaria'],
            'subjects' => [['course' => '4º Primaria', 'name' => 'Matemáticas']],
            'knowledge' => ['PMAT04SBIV.1.1'],
            'criteria' => ['PMAT04CE4.1'],
        ];
    }

    /** @return array<string,mixed> */
    private function course(string $label): array
    {
        return CurModel::exactQuery(self::SCHEME, self::CLASS_IDS[CurModel::CURSO], CurModel::PREF_LABEL, $label);
    }

    /** @return array<string,mixed> */
    private function subject(string $label, int $courseId): array
    {
        return CurModel::withResource(
            CurModel::exactQuery(self::SCHEME, self::CLASS_IDS[CurModel::MATERIA_CURSO], CurModel::PREF_LABEL, $label),
            CurModel::COURSE_EDGE,
            $courseId
        );
    }

    /** @return array<string,mixed> */
    private function leaf(string $class, string $code): array
    {
        return CurModel::exactQuery(self::SCHEME, self::CLASS_IDS[$class], CurModel::NOTATION, $code);
    }

    private function terms(): CurriculumTerms
    {
        $terms = $this->createMock(CurriculumTerms::class);
        $terms->method('schemeId')->willReturn(self::SCHEME);
        $terms->method('classId')->willReturnCallback(static fn (string $term) => self::CLASS_IDS[$term] ?? null);
        return $terms;
    }

    /**
     * Fake API answering the exact queries given; any other query finds nothing.
     *
     * @param list<array{0: array<string,mixed>, 1: int[]}> $hits
     */
    private function api(array $hits): ApiManager
    {
        $api = $this->createMock(ApiManager::class);
        $api->method('search')->willReturnCallback(function (string $resource, array $query) use ($hits): Response {
            self::assertSame('items', $resource);
            $this->queries[] = $query;
            $ids = [];
            foreach ($hits as [$expected, $found]) {
                if ($expected === $query) {
                    $ids = $found;
                }
            }
            $content = array_map(fn (int $id): object => $this->withId($id), $ids);
            return new Response($content, count($ids));
        });
        return $api;
    }

    private function withId(int $id): object
    {
        return new class ($id) {
            public function __construct(private int $id)
            {
            }

            public function id(): int
            {
                return $this->id;
            }
        };
    }
}
