<?php

declare(strict_types=1);

namespace OERManager\Service\Stats;

/**
 * «Materia» card (TASK-047, spec D10). Pure. A subject item belongs to one
 * course (ADR-0009), and the same name exists in many courses («Educación
 * física» is 11 items), so subjects are grouped stage → course and never
 * listed without their course. Within a course, by count then name. Subjects
 * whose course is unknown go to a last group.
 */
final class SubjectCounts
{
    /**
     * @param array<int, array<string, mixed>> $facts
     * @return list<array{stageId:?int, label:string,
     *     courses:list<array{courseId:?int, label:string,
     *     subjects:list<array{label:string, count:int}>}>}>
     */
    public function build(array $facts, CurriculumMap $map, string $otherLabel): array
    {
        $subjectCounts = [];
        foreach ($facts as $row) {
            foreach (array_unique((array) ($row['materia'] ?? [])) as $subjectId) {
                $subjectCounts[(int) $subjectId] = ($subjectCounts[(int) $subjectId] ?? 0) + 1;
            }
        }

        $byCourse = [];
        foreach ($subjectCounts as $subjectId => $count) {
            $byCourse[$map->courseOfSubject($subjectId) ?? 0][] = [
                'label' => $map->label($subjectId),
                'count' => $count,
            ];
        }

        $courseOrder = $map->orderedCourseIds();
        foreach (array_keys($byCourse) as $courseId) {
            if (0 !== $courseId && !in_array($courseId, $courseOrder, true)) {
                $courseOrder[] = $courseId;
            }
        }

        $groups = [];
        foreach ([...$map->orderedStageIds(), 0] as $stageId) {
            $courses = [];
            foreach ($courseOrder as $courseId) {
                if (!isset($byCourse[$courseId]) || ($map->stageOfCourse($courseId) ?? 0) !== $stageId) {
                    continue;
                }
                $courses[] = [
                    'courseId' => $courseId,
                    'label' => $map->label($courseId),
                    'subjects' => $this->sortSubjects($byCourse[$courseId]),
                ];
            }
            if (0 === $stageId && isset($byCourse[0])) {
                $courses[] = [
                    'courseId' => null,
                    'label' => $otherLabel,
                    'subjects' => $this->sortSubjects($byCourse[0]),
                ];
            }
            if ([] !== $courses) {
                $groups[] = [
                    'stageId' => 0 === $stageId ? null : $stageId,
                    'label' => 0 === $stageId ? $otherLabel : $map->label($stageId),
                    'courses' => $courses,
                ];
            }
        }
        return $groups;
    }

    /**
     * Same filter as the stage/course selects of the page (statsMain.js), so the
     * CSV matches what is on screen. Null means «no filter».
     *
     * @param list<array{stageId:?int, courses:list<array{courseId:?int}>}> $groups
     * @return list<array{stageId:?int, courses:list<array{courseId:?int}>}>
     */
    public function filter(array $groups, ?int $stageId, ?int $courseId): array
    {
        $out = [];
        foreach ($groups as $group) {
            if (null !== $stageId && $group['stageId'] !== $stageId) {
                continue;
            }
            if (null !== $courseId) {
                $group['courses'] = array_values(array_filter(
                    $group['courses'],
                    static fn (array $course): bool => $course['courseId'] === $courseId
                ));
            }
            if ([] !== $group['courses']) {
                $out[] = $group;
            }
        }
        return $out;
    }

    /**
     * @param list<array<string, mixed>> $groups The shape returned by build().
     * @return list<array{0:string, 1:string, 2:string, 3:int}> Stage, course, subject, count.
     */
    public function toRows(array $groups): array
    {
        $rows = [];
        foreach ($groups as $group) {
            foreach ($group['courses'] as $course) {
                foreach ($course['subjects'] as $subject) {
                    $rows[] = [$group['label'], $course['label'], $subject['label'], $subject['count']];
                }
            }
        }
        return $rows;
    }

    /**
     * @param list<array{label:string, count:int}> $subjects
     * @return list<array{label:string, count:int}>
     */
    private function sortSubjects(array $subjects): array
    {
        usort(
            $subjects,
            static fn (array $a, array $b): int => $b['count'] <=> $a['count']
                ?: strnatcasecmp($a['label'], $b['label'])
        );
        return $subjects;
    }
}
