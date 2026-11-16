<?php

declare(strict_types=1);

namespace OERManager\Service\Stats;

use OERManager\Service\Curriculum\CurModel;
use OERManager\Service\Curriculum\CurriculumTerms;
use Omeka\Api\Manager as ApiManager;
use Omeka\Api\Representation\AbstractEntityRepresentation;
use Omeka\Api\Representation\AbstractResourceEntityRepresentation;

/**
 * Builds the CurriculumMap of the statistics (TASK-047, spec §5.1) from the
 * `cur:` model (ADR-0022, TASK-064 slice 5). Core adapter: touches
 * representations; host-tested with API doubles and verified in the container
 * (`test/container/stats-check.php`).
 *
 * Fixed number of searches, whatever the REA count: every current cur:Curso
 * and cur:MateriaCurso of the curriculum item set (CurModel::termsQuery), then
 * the linked terms outside them by id (retired or legacy terms, axes,
 * projects; the batch TASK-006 already did). Stages are read through the
 * courses' `cur:etapa`, subjects reach their course by `cur:curso`
 * (CurModel::COURSE_EDGE). Only the two upper levels of the curriculum
 * (18 + 232 items in 1.1.2) are read, never knowledge or criteria (NFR-004).
 * Read-only: curriculum items are inputs.
 */
final class CurriculumOutline
{
    /** Upper bound of each curriculum-level search (232 course subjects in 1.1.2). */
    public const LEVEL_CAP = 5000;

    /** @var array<int, array{label:string, notation:string}> */
    private array $stages = [];
    /** @var array<int, array{label:string, stage:?int, notation:string}> */
    private array $courses = [];
    /** @var array<int, array{label:string, course:?int}> */
    private array $subjects = [];

    public function __construct(private ApiManager $api, private CurriculumTerms $terms)
    {
    }

    /**
     * @param int[] $courseIds Ids the REA use as course (`lrmi:educationalLevel`).
     * @param int[] $subjectIds Ids the REA use as subject (`schema:about`).
     * @param int[] $otherIds Other linked ids (axes, projects) that only need a label.
     */
    public function load(array $courseIds, array $subjectIds, array $otherIds): CurriculumMap
    {
        $this->stages = [];
        $this->courses = [];
        $this->subjects = [];

        foreach ($this->currentTerms(CurModel::CURSO) as $course) {
            $this->addCourse($course);
        }
        $universe = $this->currentTerms(CurModel::MATERIA_CURSO);
        foreach ($universe as $subject) {
            $this->addSubject($subject);
        }

        // Linked terms outside the current curriculum (retired, legacy, or
        // every term when no curriculum resolves): one batch, as in TASK-006.
        $labels = [];
        $wanted = array_values(array_unique(array_map('intval', [...$courseIds, ...$subjectIds, ...$otherIds])));
        $missing = array_values(array_filter(
            $wanted,
            fn (int $id): bool => !isset($this->courses[$id]) && !isset($this->subjects[$id])
        ));
        if ([] !== $missing) {
            $courseSet = array_flip(array_map('intval', $courseIds));
            $subjectSet = array_flip(array_map('intval', $subjectIds));
            foreach ((array) $this->api->search('items', ['id' => $missing])->getContent() as $term) {
                $id = (int) $term->id();
                if (isset($subjectSet[$id])) {
                    $this->addSubject($term);
                } elseif (isset($courseSet[$id])) {
                    $this->addCourse($term);
                } else {
                    $labels[$id] = (string) $term->displayTitle();
                }
            }
        }

        return new CurriculumMap($this->stages, $this->courses, $this->subjects, $labels, [] !== $universe);
    }

    /** @return AbstractResourceEntityRepresentation[] */
    private function currentTerms(string $class): array
    {
        $schemeId = $this->terms->schemeId();
        $classId = $this->terms->classId($class);
        if (null === $schemeId || null === $classId) {
            return [];
        }
        return (array) $this->api->search(
            'items',
            CurModel::termsQuery($schemeId, $classId, '', self::LEVEL_CAP)
        )->getContent();
    }

    private function addCourse(AbstractEntityRepresentation $course): int
    {
        $id = (int) $course->id();
        if (!isset($this->courses[$id])) {
            $stage = $this->target($course, CurModel::EDGE_ETAPA);
            if (null !== $stage && !isset($this->stages[(int) $stage->id()])) {
                $this->stages[(int) $stage->id()] = [
                    'label' => CurModel::label($stage),
                    'notation' => CurModel::notation($stage),
                ];
            }
            $this->courses[$id] = [
                'label' => $this->label($course),
                'stage' => null !== $stage ? (int) $stage->id() : null,
                'notation' => $course instanceof AbstractResourceEntityRepresentation
                    ? CurModel::notation($course)
                    : '',
            ];
        }
        return $id;
    }

    /**
     * The subject is stored without its course suffix («Matemáticas»), so
     * CurriculumMap::qualifiedSubjectLabel() rebuilds «Matemáticas · 1º ESO»
     * and the coverage matrix groups the same subject across courses.
     */
    private function addSubject(AbstractEntityRepresentation $subject): void
    {
        $course = $this->target($subject, CurModel::COURSE_EDGE);
        $courseId = null !== $course ? $this->addCourse($course) : null;
        $this->subjects[(int) $subject->id()] = [
            'label' => CurModel::courseSubjectName(
                $this->label($subject),
                null !== $courseId ? $this->courses[$courseId]['label'] : ''
            ),
            'course' => $courseId,
        ];
    }

    private function target(AbstractEntityRepresentation $node, string $edge): ?AbstractResourceEntityRepresentation
    {
        return $node instanceof AbstractResourceEntityRepresentation ? CurModel::target($node, $edge) : null;
    }

    private function label(AbstractEntityRepresentation $node): string
    {
        return $node instanceof AbstractResourceEntityRepresentation
            ? CurModel::label($node)
            : (string) $node->displayTitle();
    }
}
