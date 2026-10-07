<?php

declare(strict_types=1);

namespace OERManager\Test\Service\Ai;

use OERManager\Service\Ai\DeclaredAlignmentResolver;
use Omeka\Api\Manager as ApiManager;
use Omeka\Api\Response;
use PHPUnit\Framework\TestCase;

/**
 * TASK-057 / RF-020: the declared alignment is resolved against the REAL
 * curriculum — knowledge/criteria by exact `dcterms:identifier`, the course by
 * exact title, the subject by name within that course. Nothing is invented: a
 * code with no match, or with more than one, is reported.
 */
final class DeclaredAlignmentResolverTest extends TestCase
{
    public function testResolvesEveryDimensionAgainstTheCatalogue(): void
    {
        $resolver = new DeclaredAlignmentResolver($this->api([
            'dcterms:title=4º Primaria' => [10],
            'dcterms:title=Matemáticas&lrmi:educationalLevel=10' => [20],
            'dcterms:identifier=PMAT04SBIV.1.1' => [30],
            'dcterms:identifier=PMAT04CE4.1' => [40],
        ]));

        $resolved = $resolver->resolve($this->declared());

        self::assertSame([10], $resolved['lrmi:educationalLevel']);
        self::assertSame([20], $resolved['schema:about']);
        self::assertSame([30], $resolved['lrmi:teaches']);
        self::assertSame([40], $resolved['lrmi:assesses']);
        self::assertSame([], $resolved['unresolved']);
    }

    public function testTheSubjectIsFoundThroughAnyCourseEdge(): void
    {
        $resolver = new DeclaredAlignmentResolver($this->api([
            'dcterms:title=4º Primaria' => [10],
            'dcterms:title=Matemáticas&lrmi:educationalAlignment=10' => [21],
        ]));

        self::assertSame([21], $resolver->resolve($this->declared())['schema:about']);
    }

    public function testMissingAndAmbiguousCodesAreReportedNotGuessed(): void
    {
        $resolver = new DeclaredAlignmentResolver($this->api([
            'dcterms:title=4º Primaria' => [10, 11],
            'dcterms:identifier=PMAT04SBIV.1.1' => [],
            'dcterms:identifier=PMAT04CE4.1' => [40, 41],
        ]));

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

    /**
     * Fake API keyed by the property filters of each search: «term=text», joined
     * with «&» when the search combines filters. Unknown searches find nothing.
     *
     * @param array<string,int[]> $hits
     */
    private function api(array $hits): ApiManager
    {
        $api = $this->createMock(ApiManager::class);
        $api->method('search')->willReturnCallback(function (string $resource, array $query) use ($hits): Response {
            self::assertSame('items', $resource);
            $key = implode('&', array_map(
                static fn (array $p): string => $p['property'] . '=' . $p['text'],
                $query['property']
            ));
            $ids = $hits[$key] ?? [];
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
