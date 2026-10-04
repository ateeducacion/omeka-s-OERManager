<?php

declare(strict_types=1);

namespace OERManager\Service\Stats;

use OERManager\Service\Governance\SubjectTint;

/**
 * Curricular coverage heatmap (TASK-047, spec D2/D3). Pure.
 *
 * Rows are subject NAMES (normalised like SubjectTint, so the 11 «Educación
 * física» items make one row); columns are courses in curriculum order. A
 * cell is the number of distinct REA linked (`schema:about`) to the subject
 * item of that course — the course comes from the subject's own edge
 * (ADR-0009), not from the REA's `lrmi:educationalLevel`. A cell is null when
 * no subject of that name exists in that course, and 0 when it exists and no
 * REA covers it: the second is the investment signal.
 */
final class CoverageMatrix
{
    /**
     * @param array<int, array<string, mixed>> $facts
     * @return array{
     *     stages: list<array{id:?int, label:string, span:int}>,
     *     columns: list<array{id:int, label:string, stageId:?int}>,
     *     rows: list<array{label:string, cells:list<?int>}>,
     *     max: int,
     *     unplaced: int
     * }
     */
    public function build(array $facts, CurriculumMap $map, string $otherLabel = ''): array
    {
        // name key => course id => set of REA ids (an existing cell starts empty).
        $cells = [];
        $labels = [];
        foreach ($map->subjects() as $subjectId => $subject) {
            $course = $subject['course'];
            if (null === $course) {
                continue;
            }
            $key = SubjectTint::normalise($subject['label']);
            $labels[$key] ??= $subject['label'];
            $cells[$key][$course] ??= [];
        }

        $unplaced = 0;
        foreach ($facts as $reaId => $row) {
            foreach (array_unique((array) ($row['materia'] ?? [])) as $subjectId) {
                $course = $map->courseOfSubject((int) $subjectId);
                if (null === $course) {
                    $unplaced++;
                    continue;
                }
                $label = $map->label((int) $subjectId);
                $key = SubjectTint::normalise($label);
                $labels[$key] ??= $label;
                $cells[$key][$course][$reaId] = true;
            }
        }

        $used = [];
        foreach ($cells as $byCourse) {
            foreach (array_keys($byCourse) as $course) {
                $used[$course] = true;
            }
        }
        $columnIds = array_values(array_filter(
            $map->orderedCourseIds(),
            static fn (int $id): bool => isset($used[$id])
        ));

        ksort($cells, SORT_STRING);
        $rows = [];
        $max = 0;
        foreach ($cells as $key => $byCourse) {
            $row = [];
            foreach ($columnIds as $course) {
                $count = isset($byCourse[$course]) ? count($byCourse[$course]) : null;
                $max = max($max, (int) $count);
                $row[] = $count;
            }
            $rows[] = ['label' => $labels[$key], 'cells' => $row];
        }

        $columns = [];
        foreach ($columnIds as $course) {
            $columns[] = ['id' => $course, 'label' => $map->label($course), 'stageId' => $map->stageOfCourse($course)];
        }

        return [
            'stages' => $this->stageSpans($columns, $map, $otherLabel),
            'columns' => $columns,
            'rows' => $rows,
            'max' => $max,
            'unplaced' => $unplaced,
        ];
    }

    /**
     * Keep the columns of one stage and drop the rows that become all n/a. Same
     * rule as `visibleCoverage()` in asset/js/stats/heatmap.js, so the CSV
     * matches the screen.
     *
     * @param array<string, mixed> $matrix The shape returned by build().
     * @return array<string, mixed> The same shape, narrowed to one stage.
     */
    public function filterStage(array $matrix, ?int $stageId): array
    {
        if (null === $stageId) {
            return $matrix;
        }
        $keep = [];
        foreach ($matrix['columns'] as $index => $column) {
            if ($column['stageId'] === $stageId) {
                $keep[] = $index;
            }
        }
        $rows = [];
        $max = 0;
        foreach ($matrix['rows'] as $row) {
            $cells = array_map(static fn (int $index): ?int => $row['cells'][$index], $keep);
            if ([] === array_filter($cells, static fn (?int $cell): bool => null !== $cell)) {
                continue;
            }
            $max = max($max, ...array_map('intval', $cells));
            $rows[] = ['label' => $row['label'], 'cells' => $cells];
        }
        $matrix['columns'] = array_map(static fn (int $index): array => $matrix['columns'][$index], $keep);
        $matrix['stages'] = array_values(array_filter(
            $matrix['stages'],
            static fn (array $stage): bool => $stage['id'] === $stageId
        ));
        $matrix['rows'] = $rows;
        $matrix['max'] = $max;
        return $matrix;
    }

    /**
     * Existing cells only (gaps as 0, n/a skipped), column by column within a row.
     *
     * @param array<string, mixed> $matrix The shape returned by build().
     * @return list<array{0:string, 1:string, 2:string, 3:int}> Stage, course, subject, count.
     */
    public function toRows(array $matrix): array
    {
        $stageLabels = [];
        foreach ($matrix['stages'] as $stage) {
            $stageLabels[$stage['id'] ?? 0] = $stage['label'];
        }
        $rows = [];
        foreach ($matrix['rows'] as $row) {
            foreach ($matrix['columns'] as $index => $column) {
                $count = $row['cells'][$index];
                if (null !== $count) {
                    $rows[] = [$stageLabels[$column['stageId'] ?? 0] ?? '', $column['label'], $row['label'], $count];
                }
            }
        }
        return $rows;
    }

    /**
     * @param list<array{id:int, label:string, stageId:?int}> $columns
     * @return list<array{id:?int, label:string, span:int}>
     */
    private function stageSpans(array $columns, CurriculumMap $map, string $otherLabel): array
    {
        $spans = [];
        foreach ($columns as $column) {
            $last = array_key_last($spans);
            if (null !== $last && $spans[$last]['id'] === $column['stageId']) {
                $spans[$last]['span']++;
                continue;
            }
            $spans[] = [
                'id' => $column['stageId'],
                'label' => null !== $column['stageId'] ? $map->label($column['stageId']) : $otherLabel,
                'span' => 1,
            ];
        }
        return $spans;
    }
}
