<?php

declare(strict_types=1);

namespace OERManager\Service\Stats;

/**
 * «Etapa y curso» card (TASK-047, spec D9). Pure. The REA's
 * `lrmi:educationalLevel` points at a course (ADR-0009 §5); courses are
 * grouped under their stage in curriculum order, each stage with its number
 * of distinct REA. Every curriculum course is listed, with 0 when it has none.
 * Linked terms that are not a known course go to a last, stageless group.
 */
final class StageCounts
{
    /**
     * @param array<int, array<string, mixed>> $facts
     * @return list<array{stageId:?int, label:string, total:int,
     *     courses:list<array{courseId:int, label:string, count:int}>}>
     */
    public function build(array $facts, CurriculumMap $map, string $otherLabel): array
    {
        $courseCounts = [];
        $stageRea = [];
        foreach ($facts as $reaId => $row) {
            foreach (array_unique((array) ($row['etapa'] ?? [])) as $courseId) {
                $courseId = (int) $courseId;
                $courseCounts[$courseId] = ($courseCounts[$courseId] ?? 0) + 1;
                $stageRea[$map->stageOfCourse($courseId) ?? 0][$reaId] = true;
            }
        }

        $courseIdsByStage = [];
        foreach ($map->orderedCourseIds() as $courseId) {
            $courseIdsByStage[$map->stageOfCourse($courseId) ?? 0][] = $courseId;
        }
        $unknown = array_filter(
            array_keys($courseCounts),
            static fn (int $id): bool => !$map->isCourse($id)
        );
        usort(
            $unknown,
            static fn (int $a, int $b): int => strnatcasecmp($map->label($a), $map->label($b)) ?: $a <=> $b
        );
        foreach ($unknown as $id) {
            $courseIdsByStage[0][] = $id;
        }

        $groups = [];
        foreach ([...$map->orderedStageIds(), 0] as $stageId) {
            if (!isset($courseIdsByStage[$stageId])) {
                continue;
            }
            $courses = [];
            foreach ($courseIdsByStage[$stageId] as $courseId) {
                $courses[] = [
                    'courseId' => $courseId,
                    'label' => $map->label($courseId),
                    'count' => $courseCounts[$courseId] ?? 0,
                ];
            }
            $groups[] = [
                'stageId' => 0 === $stageId ? null : $stageId,
                'label' => 0 === $stageId ? $otherLabel : $map->label($stageId),
                'total' => count($stageRea[$stageId] ?? []),
                'courses' => $courses,
            ];
        }
        return $groups;
    }

    /**
     * @param list<array{label:string, courses:list<array{label:string, count:int}>}> $groups
     * @return list<array{0:string, 1:string, 2:int}> Stage, course, count.
     */
    public function toRows(array $groups): array
    {
        $rows = [];
        foreach ($groups as $group) {
            foreach ($group['courses'] as $course) {
                $rows[] = [$group['label'], $course['label'], $course['count']];
            }
        }
        return $rows;
    }
}
