<?php

declare(strict_types=1);

namespace OERManager\Service\Ai;

use OERManager\Service\Curriculum\CurModel;
use OERManager\Service\Curriculum\CurriculumTerms;
use Omeka\Api\Manager as ApiManager;

/**
 * Resuelve la alineación declarada en un paquete (ElpxDeclaredAlignment)
 * contra el currículo REAL del catálogo (TASK-057, RF-020), con el modelo `cur:`
 * de ADR-0022 (TASK-064 rebanada 6). Solo términos vigentes del conjunto del
 * currículo, y cada consulta la construye `CurModel`:
 *
 * - saberes y criterios por `skos:notation` exacta (el `dcterms:identifier`
 *   literal lleva prefijo de tipo, `crit:SMAT01CE1.1`; el paquete declara el
 *   código limpio);
 * - el curso por su `skos:prefLabel` («4º Primaria», como lo escribe el paquete);
 * - la materia como la `cur:MateriaCurso` de ese curso cuyo `skos:prefLabel` es
 *   «<materia> · <curso>» (el paquete declara la materia sin el sufijo de etapa
 *   que lleva `cur:Materia`).
 *
 * No inventa nada: un valor sin coincidencia o con más de una se informa en
 * `unresolved` y no entra en ninguna dimensión. Sin currículo configurado (o sin
 * la clase en la instancia) el motivo es `no_curriculum`. Los ids devueltos usan
 * las mismas claves que la propuesta IA, para puntuarlas con EvaluationScorer.
 */
final class DeclaredAlignmentResolver
{
    private const LEAVES = [
        'knowledge' => ['dimension' => 'lrmi:teaches', 'kind' => 'knowledge', 'class' => CurModel::SABER],
        'criteria' => ['dimension' => 'lrmi:assesses', 'kind' => 'criterion', 'class' => CurModel::CRITERIO],
    ];

    public function __construct(private ApiManager $api, private CurriculumTerms $terms)
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
            $id = $this->unique(CurModel::CURSO, CurModel::PREF_LABEL, $course, 'course', $out['unresolved']);
            if (null !== $id) {
                $courseIds[$course] = $id;
                $out['lrmi:educationalLevel'][] = $id;
            }
        }

        foreach ($declared['subjects'] ?? [] as $subject) {
            $label = $subject['name'] . ' (' . $subject['course'] . ')';
            $courseId = $courseIds[$subject['course']] ?? null;
            if (null === $courseId) {
                // A course that did not resolve has a reason of its own already.
                $reason = $this->classId(CurModel::MATERIA_CURSO) ? 'no_course' : 'no_curriculum';
                $out['unresolved'][] = ['kind' => 'subject', 'value' => $label, 'reason' => $reason];
                continue;
            }
            $id = $this->unique(
                CurModel::MATERIA_CURSO,
                CurModel::PREF_LABEL,
                CurModel::courseSubjectLabel($subject['name'], $subject['course']),
                'subject',
                $out['unresolved'],
                $courseId,
                $label
            );
            if (null !== $id) {
                $out['schema:about'][] = $id;
            }
        }

        foreach (self::LEAVES as $key => $leaf) {
            foreach ($declared[$key] ?? [] as $code) {
                $id = $this->unique($leaf['class'], CurModel::NOTATION, $code, $leaf['kind'], $out['unresolved']);
                if (null !== $id) {
                    $out[$leaf['dimension']][] = $id;
                }
            }
        }

        foreach (['lrmi:educationalLevel', 'schema:about', 'lrmi:teaches', 'lrmi:assesses'] as $dimension) {
            $out[$dimension] = array_values(array_unique($out[$dimension]));
        }
        return $out;
    }

    /**
     * The one current term of `$class` whose `$property` equals `$value`
     * (and, for a course subject, of course `$courseId`), or null with the
     * reason recorded.
     *
     * @param list<array{kind: string, value: string, reason: string}> $unresolved
     */
    private function unique(
        string $class,
        string $property,
        string $value,
        string $kind,
        array &$unresolved,
        ?int $courseId = null,
        ?string $shown = null
    ): ?int {
        $classId = $this->classId($class);
        if (null === $classId) {
            $unresolved[] = ['kind' => $kind, 'value' => $shown ?? $value, 'reason' => 'no_curriculum'];
            return null;
        }
        $query = CurModel::exactQuery((int) $this->terms->schemeId(), $classId, $property, $value);
        if (null !== $courseId) {
            $query = CurModel::withResource($query, CurModel::COURSE_EDGE, $courseId);
        }
        $ids = array_map(
            static fn (object $item): int => (int) $item->id(),
            $this->api->search('items', $query)->getContent()
        );
        if (1 === count($ids)) {
            return $ids[0];
        }
        $unresolved[] = ['kind' => $kind, 'value' => $shown ?? $value, 'reason' => $ids ? 'ambiguous' : 'missing'];
        return null;
    }

    /** The class id, or null when there is no curriculum or no such class here. */
    private function classId(string $class): ?int
    {
        return null === $this->terms->schemeId() ? null : $this->terms->classId($class);
    }
}
