<?php

declare(strict_types=1);

namespace OERManager\Service\Stats;

/**
 * The two upper levels of the curriculum graph that the statistics need
 * (TASK-047): stages, courses with their stage, subjects with their course
 * (ADR-0009: a subject item declares its own course), plus the labels of any
 * other linked term (axes, projects). Pure value object; built in the
 * container by CurriculumOutline.
 */
final class CurriculumMap
{
    /** @var array<int, array{label:string, position:?int}> */
    private array $stages;
    /** @var array<int, array{label:string, stage:?int, position?:?int}> */
    private array $courses;
    /** @var array<int, array{label:string, course:?int}> */
    private array $subjects;
    /** @var array<int, string> */
    private array $labels;
    private bool $hasUniverse;

    /**
     * @param array<int, array{label:string, position:?int}> $stages
     * @param array<int, array{label:string, stage:?int, position?:?int}> $courses
     * @param array<int, array{label:string, course:?int}> $subjects
     * @param array<int, string> $labels Labels of other linked terms.
     * @param bool $hasUniverse False when the subject list is only what REA use
     *                          (type setting missing): gaps cannot be told apart.
     */
    public function __construct(
        array $stages,
        array $courses,
        array $subjects,
        array $labels,
        bool $hasUniverse = true
    ) {
        $this->stages = $stages;
        $this->courses = $courses;
        $this->subjects = $subjects;
        $this->labels = $labels;
        $this->hasUniverse = $hasUniverse;
    }

    /** @return list<int> */
    public function orderedStageIds(): array
    {
        return CurriculumOrder::sortStages($this->stages);
    }

    /**
     * Every course, by stage order then level; courses without a known stage last.
     *
     * @return list<int>
     */
    public function orderedCourseIds(): array
    {
        $byStage = [];
        foreach ($this->courses as $id => $course) {
            $stage = $course['stage'] ?? null;
            $key = null !== $stage && isset($this->stages[$stage]) ? $stage : 0;
            $byStage[$key][$id] = $course;
        }
        $ordered = [];
        foreach ([...$this->orderedStageIds(), 0] as $stage) {
            if (isset($byStage[$stage])) {
                array_push($ordered, ...CurriculumOrder::sortCourses($byStage[$stage]));
            }
        }
        return $ordered;
    }

    public function isCourse(int $id): bool
    {
        return isset($this->courses[$id]);
    }

    public function stageOfCourse(int $courseId): ?int
    {
        $stage = $this->courses[$courseId]['stage'] ?? null;
        return null !== $stage && isset($this->stages[$stage]) ? $stage : null;
    }

    public function courseOfSubject(int $subjectId): ?int
    {
        return $this->subjects[$subjectId]['course'] ?? null;
    }

    /** @return array<int, array{label:string, course:?int}> */
    public function subjects(): array
    {
        return $this->subjects;
    }

    public function hasUniverse(): bool
    {
        return $this->hasUniverse;
    }

    public function label(int|string $id): string
    {
        if (is_int($id)) {
            foreach ([$this->subjects, $this->courses, $this->stages] as $nodes) {
                if (isset($nodes[$id])) {
                    return $nodes[$id]['label'];
                }
            }
            if (isset($this->labels[$id])) {
                return $this->labels[$id];
            }
        }
        return (string) $id;
    }

    /** «Educación física · 1º ESO»: a subject name is ambiguous without its course. */
    public function qualifiedSubjectLabel(int $subjectId): string
    {
        $course = $this->courseOfSubject($subjectId);
        $label = $this->label($subjectId);
        return null !== $course ? $label . ' · ' . $this->label($course) : $label;
    }
}
