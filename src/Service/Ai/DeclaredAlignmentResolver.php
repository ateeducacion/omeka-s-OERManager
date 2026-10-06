<?php

declare(strict_types=1);

namespace OERManager\Service\Ai;

use OERManager\Service\Governance\CurricularPairs;
use Omeka\Api\Manager as ApiManager;

/**
 * Resuelve la alineación declarada en un paquete (ElpxDeclaredAlignment)
 * contra el currículo REAL del catálogo (TASK-057, RF-020):
 *
 * - saberes y criterios por `dcterms:identifier` exacto;
 * - el curso por título exacto;
 * - la materia por nombre dentro de ese curso (Asignatura → curso, ADR-0009).
 *
 * No inventa nada: un valor sin coincidencia o con más de una se informa en
 * `unresolved` y no entra en ninguna dimensión. Los ids devueltos usan las
 * mismas claves que la propuesta IA, para puntuarlas con EvaluationScorer.
 */
final class DeclaredAlignmentResolver
{
    public function __construct(private ApiManager $api)
    {
    }

    /**
     * @param array{courses?: list<string>, subjects?: list<array{course: string, name: string}>,
     *     knowledge?: list<string>, criteria?: list<string>} $declared
     * @return array{
     *     'lrmi:educationalLevel': list<int>,
     *     'schema:about': list<int>,
     *     'lrmi:teaches': list<int>,
     *     'lrmi:assesses': list<int>,
     *     unresolved: list<array{kind: string, value: string, reason: string}>
     * }
     */
    public function resolve(array $declared): array
    {
        $out = [
            'lrmi:educationalLevel' => [],
            'schema:about' => [],
            'lrmi:teaches' => [],
            'lrmi:assesses' => [],
            'unresolved' => [],
        ];

        $courseIds = [];
        foreach ($declared['courses'] ?? [] as $course) {
            $id = $this->unique([['dcterms:title', $course]], 'course', $course, $out['unresolved']);
            if (null !== $id) {
                $courseIds[$course] = $id;
                $out['lrmi:educationalLevel'][] = $id;
            }
        }

        foreach ($declared['subjects'] ?? [] as $subject) {
            $label = $subject['name'] . ' (' . $subject['course'] . ')';
            $courseId = $courseIds[$subject['course']] ?? null;
            if (null === $courseId) {
                $out['unresolved'][] = ['kind' => 'subject', 'value' => $label, 'reason' => 'no_course'];
                continue;
            }
            $id = $this->subjectInCourse($subject['name'], $courseId, $label, $out['unresolved']);
            if (null !== $id) {
                $out['schema:about'][] = $id;
            }
        }

        foreach (['knowledge' => 'lrmi:teaches', 'criteria' => 'lrmi:assesses'] as $key => $dimension) {
            $kind = 'knowledge' === $key ? 'knowledge' : 'criterion';
            foreach ($declared[$key] ?? [] as $code) {
                $id = $this->unique([['dcterms:identifier', $code]], $kind, $code, $out['unresolved']);
                if (null !== $id) {
                    $out[$dimension][] = $id;
                }
            }
        }

        foreach (['lrmi:educationalLevel', 'schema:about', 'lrmi:teaches', 'lrmi:assesses'] as $dimension) {
            $out[$dimension] = array_values(array_unique($out[$dimension]));
        }
        return $out;
    }

    /**
     * La Asignatura declara su curso por una de varias aristas
     * (CurricularPairs::COURSE_TERMS); vale la primera que dé un único item.
     *
     * @param list<array{kind: string, value: string, reason: string}> $unresolved
     */
    private function subjectInCourse(string $name, int $courseId, string $label, array &$unresolved): ?int
    {
        $ambiguous = false;
        foreach (CurricularPairs::COURSE_TERMS as $edge) {
            $ids = $this->ids([['dcterms:title', $name, 'eq'], [$edge, (string) $courseId, 'res']]);
            if (1 === count($ids)) {
                return $ids[0];
            }
            $ambiguous = $ambiguous || count($ids) > 1;
        }
        $unresolved[] = ['kind' => 'subject', 'value' => $label, 'reason' => $ambiguous ? 'ambiguous' : 'missing'];
        return null;
    }

    /**
     * @param list<array{0: string, 1: string}> $filters
     * @param list<array{kind: string, value: string, reason: string}> $unresolved
     */
    private function unique(array $filters, string $kind, string $value, array &$unresolved): ?int
    {
        $ids = $this->ids(array_map(static fn (array $f): array => [$f[0], $f[1], 'eq'], $filters));
        if (1 === count($ids)) {
            return $ids[0];
        }
        $unresolved[] = ['kind' => $kind, 'value' => $value, 'reason' => $ids ? 'ambiguous' : 'missing'];
        return null;
    }

    /**
     * Dos filas bastan para distinguir «única» de «ambigua».
     *
     * @param list<array{0: string, 1: string, 2: string}> $filters
     * @return list<int>
     */
    private function ids(array $filters): array
    {
        $property = [];
        foreach ($filters as $i => [$term, $text, $type]) {
            $property[] = ['property' => $term, 'type' => $type, 'text' => $text] + ($i > 0 ? ['joiner' => 'and'] : []);
        }
        $items = $this->api->search('items', ['property' => $property, 'limit' => 2])->getContent();

        return array_map(static fn (object $item): int => (int) $item->id(), $items);
    }
}
