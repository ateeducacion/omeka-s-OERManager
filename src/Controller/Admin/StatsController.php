<?php

declare(strict_types=1);

namespace OERManager\Controller\Admin;

use Laminas\Mvc\Controller\AbstractActionController;
use Laminas\View\Model\ViewModel;
use OERManager\Service\IntegrityChecker;
use OERManager\Service\Stats\CatalogSnapshot;
use OERManager\Service\Stats\CompletenessAggregator;
use OERManager\Service\Stats\CoverageMatrix;
use OERManager\Service\Stats\CsvExport;
use OERManager\Service\Stats\CurriculumMap;
use OERManager\Service\Stats\CurriculumOutline;
use OERManager\Service\Stats\DimensionCounter;
use OERManager\Service\Stats\DimensionCrosser;
use OERManager\Service\Stats\DimensionFacts;
use OERManager\Service\Stats\StageCounts;
use OERManager\Service\Stats\SubjectCounts;
use OERManager\Service\Stats\YearFilter;

/**
 * Estadísticas del catálogo como cuadro de mando (RF-007, RF-019, ADR-0004,
 * TASK-006, TASK-047). Página de solo lectura, independiente de los filtros
 * de la vista maestra (TASK-006 spec §2.3). Since TASK-047 every block and
 * every CSV is computed from the same per-REA facts, narrowed by the same
 * creation-year filter, so screen and export cannot diverge.
 */
class StatsController extends AbstractActionController
{
    /** Las 5 dimensiones de ADR-0004, en el orden en que se ofrecen en el cruce. */
    private const DIMENSIONS = ['etapa', 'materia', 'eje', 'proyecto', 'licencia'];

    /** Dimensions shown as a plain count list (etapa and materia have their own grouped cards). */
    private const FLAT_DIMENSIONS = ['eje', 'proyecto', 'licencia'];

    /** Etiquetas humanas por dimensión, mismas cadenas que usa la vista (index.phtml). */
    private const DIMENSION_LABELS = [
        'etapa' => 'Curso', // @translate
        'materia' => 'Materia', // @translate
        'eje' => 'Eje temático', // @translate
        'proyecto' => 'Proyecto', // @translate
        'licencia' => 'Licencia', // @translate
    ];

    private const OTHER_STAGE = 'Sin etapa'; // @translate
    private const OTHER_COURSE = 'Sin curso'; // @translate

    private CatalogSnapshot $catalogSnapshot;
    private DimensionFacts $dimensionFacts;
    private DimensionCounter $dimensionCounter;
    private DimensionCrosser $dimensionCrosser;
    private CompletenessAggregator $completenessAggregator;
    private CsvExport $csvExport;
    private IntegrityChecker $integrityChecker;
    private CurriculumOutline $curriculumOutline;
    private YearFilter $yearFilter;
    private StageCounts $stageCounts;
    private SubjectCounts $subjectCounts;
    private CoverageMatrix $coverageMatrix;

    public function __construct(
        CatalogSnapshot $catalogSnapshot,
        DimensionFacts $dimensionFacts,
        DimensionCounter $dimensionCounter,
        DimensionCrosser $dimensionCrosser,
        CompletenessAggregator $completenessAggregator,
        CsvExport $csvExport,
        IntegrityChecker $integrityChecker,
        CurriculumOutline $curriculumOutline
    ) {
        $this->catalogSnapshot = $catalogSnapshot;
        $this->dimensionFacts = $dimensionFacts;
        $this->dimensionCounter = $dimensionCounter;
        $this->dimensionCrosser = $dimensionCrosser;
        $this->completenessAggregator = $completenessAggregator;
        $this->csvExport = $csvExport;
        $this->integrityChecker = $integrityChecker;
        $this->curriculumOutline = $curriculumOutline;
        // Pure and stateless: no wiring needed.
        $this->yearFilter = new YearFilter();
        $this->stageCounts = new StageCounts();
        $this->subjectCounts = new SubjectCounts();
        $this->coverageMatrix = new CoverageMatrix();
    }

    public function indexAction()
    {
        $year = YearFilter::parse($this->params()->fromQuery('year'));
        $dataset = $this->collect(true);
        $facts = $this->yearFilter->apply($dataset['facts'], $year);
        $map = $this->mapFor($dataset['facts']);

        $counts = [];
        foreach (self::FLAT_DIMENSIONS as $dimension) {
            $counts[$dimension] = $this->flatCounts($facts, $dimension, $map);
        }

        $cross = [];
        foreach ($this->dimensionPairs() as [$dimA, $dimB]) {
            $cross["$dimA/$dimB"] = $this->crossModel($facts, $dimA, $dimB, $map);
            $cross["$dimB/$dimA"] = $this->crossModel($facts, $dimB, $dimA, $map);
        }

        $completeness = $this->completenessAggregator->aggregate($this->statusesOf($facts));

        $years = [];
        foreach ($this->yearFilter->years($dataset['facts']) as $value => $count) {
            $years[] = ['year' => $value, 'count' => $count];
        }

        $view = new ViewModel();
        $view->setTemplate('oer-manager/admin/stats/index');
        $view->setVariable('dimensions', self::DIMENSIONS);
        $view->setVariable('completeness', $completeness);
        $view->setVariable('truncated', $dataset['truncated']);
        $view->setVariable('year', $year);
        $view->setVariable('years', $years);
        $view->setVariable('total', count($facts));
        $view->setVariable('hasUniverse', $map->hasUniverse());
        $view->setVariable('statsData', [
            'year' => $year,
            'coverage' => $this->coverageMatrix->build($facts, $map, self::OTHER_STAGE),
            'stageCounts' => $this->stageCounts->build($facts, $map, self::OTHER_STAGE),
            'subjectCounts' => $this->subjectCounts->build($facts, $map, self::OTHER_COURSE),
            'counts' => $counts,
            'cross' => $cross,
            'completeness' => $completeness,
        ]);
        return $view;
    }

    public function exportAction()
    {
        $year = YearFilter::parse($this->params()->fromQuery('year'));
        $suffix = null !== $year ? '-' . $year : '';
        $type = (string) $this->params()->fromQuery('type', '');

        if ('completeness' === $type) {
            $dataset = $this->collect(true);
            $facts = $this->yearFilter->apply($dataset['facts'], $year);
            $result = $this->completenessAggregator->aggregate($this->statusesOf($facts));
            $rows = [
                ['ok', $result['ok']],
                ['warning', $result['warning']],
                ['error', $result['error']],
            ];
            return $this->csv(['estado', 'conteo'], $rows, $dataset['truncated'], "oer-completitud$suffix.csv");
        }

        $dimension = (string) $this->params()->fromQuery('dimension', '');
        $dimension1 = (string) $this->params()->fromQuery('dimension1', '');
        $dimension2 = (string) $this->params()->fromQuery('dimension2', '');
        $isCross = '' !== $dimension1 && '' !== $dimension2;
        if ('coverage' !== $type && !$isCross && !in_array($dimension, self::DIMENSIONS, true)) {
            return $this->unknownDimensionResponse();
        }
        if (
            $isCross && (!in_array($dimension1, self::DIMENSIONS, true)
            || !in_array($dimension2, self::DIMENSIONS, true)
            || $dimension1 === $dimension2)
        ) {
            return $this->unknownDimensionResponse();
        }

        $dataset = $this->collect(false);
        $facts = $this->yearFilter->apply($dataset['facts'], $year);
        $map = $this->mapFor($dataset['facts']);
        $truncated = $dataset['truncated'];
        $stage = $this->idParam('stage');

        if ('coverage' === $type) {
            $matrix = $this->coverageMatrix->filterStage(
                $this->coverageMatrix->build($facts, $map, self::OTHER_STAGE),
                $stage
            );
            return $this->csv(
                ['Etapa', 'Curso', 'Materia', 'conteo'],
                $this->coverageMatrix->toRows($matrix),
                $truncated,
                "oer-cobertura$suffix.csv"
            );
        }

        if ($isCross) {
            $model = $this->crossModel($facts, $dimension1, $dimension2, $map);
            $rows = [];
            foreach ($model['rows'] as $i => $labelA) {
                foreach ($model['columns'] as $j => $labelB) {
                    if ($model['cells'][$i][$j] > 0) {
                        $rows[] = [$labelA, $labelB, $model['cells'][$i][$j]];
                    }
                }
            }
            return $this->csv(
                [self::DIMENSION_LABELS[$dimension1], self::DIMENSION_LABELS[$dimension2], 'conteo'],
                $rows,
                $truncated,
                "oer-cruce-{$dimension1}-{$dimension2}$suffix.csv"
            );
        }

        if ('etapa' === $dimension) {
            $rows = $this->stageCounts->toRows($this->stageCounts->build($facts, $map, self::OTHER_STAGE));
            return $this->csv(['Etapa', 'Curso', 'conteo'], $rows, $truncated, "oer-etapa$suffix.csv");
        }

        if ('materia' === $dimension) {
            $groups = $this->subjectCounts->filter(
                $this->subjectCounts->build($facts, $map, self::OTHER_COURSE),
                $stage,
                $this->idParam('course')
            );
            return $this->csv(
                ['Etapa', 'Curso', 'Materia', 'conteo'],
                $this->subjectCounts->toRows($groups),
                $truncated,
                "oer-materia$suffix.csv"
            );
        }

        $rows = [];
        foreach ($this->flatCounts($facts, $dimension, $map) as $row) {
            $rows[] = [$row['label'], $row['count']];
        }
        return $this->csv(
            [self::DIMENSION_LABELS[$dimension], 'conteo'],
            $rows,
            $truncated,
            "oer-{$dimension}$suffix.csv"
        );
    }

    /**
     * Facts of every REA, read page by page (spec D13). The integrity status
     * is computed only when a block needs it (spec D14).
     *
     * @return array{facts: array<int, array<string, mixed>>, truncated: bool}
     */
    private function collect(bool $withStatus): array
    {
        $facts = [];
        $result = $this->catalogSnapshot->walk(function (array $items) use (&$facts, $withStatus): void {
            $page = $this->dimensionFacts->extract($items);
            if ($withStatus) {
                foreach ($items as $item) {
                    $page[(int) $item->id()]['status'] = $this->integrityChecker->check($item, false)->getStatus();
                }
            }
            $facts += $page;
        });
        return ['facts' => $facts, 'truncated' => $result['truncated']];
    }

    /**
     * Built from the unfiltered facts, so labels do not depend on the year.
     *
     * @param array<int, array<string, mixed>> $facts
     */
    private function mapFor(array $facts): CurriculumMap
    {
        $ids = static function (string $dimension) use ($facts): array {
            $out = [];
            foreach ($facts as $row) {
                foreach ((array) ($row[$dimension] ?? []) as $id) {
                    $out[(int) $id] = true;
                }
            }
            return array_keys($out);
        };
        return $this->curriculumOutline->load(
            $ids('etapa'),
            $ids('materia'),
            [...$ids('eje'), ...$ids('proyecto')]
        );
    }

    /**
     * @param array<int, array<string, mixed>> $facts
     * @return list<string>
     */
    private function statusesOf(array $facts): array
    {
        return array_values(array_map(static fn (array $row): string => (string) ($row['status'] ?? ''), $facts));
    }

    /**
     * @param array<int, array<string, mixed>> $facts
     * @return list<array{label:string,count:int}>
     */
    private function flatCounts(array $facts, string $dimension, CurriculumMap $map): array
    {
        $rows = [];
        foreach ($this->dimensionCounter->count($facts, $dimension) as $value => $count) {
            $label = $this->labelFor($dimension, $value, $map);
            $rows[$label] = ($rows[$label] ?? 0) + $count;
        }
        $out = [];
        foreach ($rows as $label => $count) {
            $out[] = ['label' => (string) $label, 'count' => $count];
        }
        usort($out, static fn (array $a, array $b): int => $b['count'] <=> $a['count']
            ?: strnatcasecmp($a['label'], $b['label']));
        return $out;
    }

    /**
     * Free 2-dimension cross as ordered labels plus a dense cell grid, so the
     * order survives JSON (spec D12): courses in curriculum order, subjects
     * qualified with their course, the rest in natural label order.
     *
     * @param array<int, array<string, mixed>> $facts
     * @return array{rows:list<string>, columns:list<string>, cells:list<list<int>>}
     */
    private function crossModel(array $facts, string $dimA, string $dimB, CurriculumMap $map): array
    {
        $table = $this->dimensionCrosser->cross($facts, $dimA, $dimB);
        $keysB = [];
        foreach ($table as $bCounts) {
            foreach (array_keys($bCounts) as $b) {
                $keysB[$b] = true;
            }
        }
        $rows = $this->orderedLabels($dimA, array_keys($table), $map);
        $columns = $this->orderedLabels($dimB, array_keys($keysB), $map);
        $rowIndex = array_flip($rows);
        $columnIndex = array_flip($columns);
        $cells = array_fill(0, count($rows), array_fill(0, count($columns), 0));
        foreach ($table as $a => $bCounts) {
            foreach ($bCounts as $b => $count) {
                $cells[$rowIndex[$this->labelFor($dimA, $a, $map)]][$columnIndex[$this->labelFor($dimB, $b, $map)]]
                    += $count;
            }
        }
        return ['rows' => array_map('strval', $rows), 'columns' => array_map('strval', $columns), 'cells' => $cells];
    }

    /**
     * @param list<int|string> $values
     * @return list<string> Distinct labels in display order.
     */
    private function orderedLabels(string $dimension, array $values, CurriculumMap $map): array
    {
        if ('etapa' === $dimension) {
            $rank = array_flip($map->orderedCourseIds());
            usort($values, function ($a, $b) use ($rank, $map): int {
                $ra = $rank[(int) $a] ?? PHP_INT_MAX;
                $rb = $rank[(int) $b] ?? PHP_INT_MAX;
                return $ra <=> $rb ?: strnatcasecmp($map->label((int) $a), $map->label((int) $b));
            });
            $labels = array_map(fn ($value): string => $this->labelFor($dimension, $value, $map), $values);
        } else {
            $labels = array_map(fn ($value): string => $this->labelFor($dimension, $value, $map), $values);
            usort($labels, 'strnatcasecmp');
        }
        return array_values(array_unique($labels));
    }

    private function labelFor(string $dimension, int|string $value, CurriculumMap $map): string
    {
        return match ($dimension) {
            'licencia' => (string) $value,
            'materia' => $map->qualifiedSubjectLabel((int) $value),
            default => $map->label((int) $value),
        };
    }

    private function idParam(string $name): ?int
    {
        $raw = trim((string) $this->params()->fromQuery($name, ''));
        return preg_match('/^\d{1,10}$/', $raw) ? (int) $raw : null;
    }

    /**
     * @param list<string> $headers
     * @param list<list<int|string>> $rows
     */
    private function csv(array $headers, array $rows, bool $truncated, string $filename): \Laminas\Http\Response
    {
        if ($truncated) {
            // ADR-0013: nunca mentir en silencio sobre un total.
            $rows[] = [
                '# NOTA',
                'Resultado acotado a los primeros ' . CatalogSnapshot::STATS_CAP . ' REA del catálogo',
            ];
        }
        $response = $this->getResponse();
        $response->setContent($this->csvExport->toCsv($headers, $rows));
        $responseHeaders = $response->getHeaders();
        $responseHeaders->addHeaderLine('Content-Type', 'text/csv; charset=utf-8');
        $responseHeaders->addHeaderLine('Content-Disposition', 'attachment; filename="' . $filename . '"');
        return $response;
    }

    private function unknownDimensionResponse(): \Laminas\View\Model\JsonModel
    {
        $this->getResponse()->setStatusCode(404);
        return new \Laminas\View\Model\JsonModel(['error' => 'unknown_dimension']);
    }

    /** @return list<array{0:string,1:string}> Los 10 pares únicos entre las 5 dimensiones. */
    private function dimensionPairs(): array
    {
        $pairs = [];
        foreach (self::DIMENSIONS as $i => $dimA) {
            foreach (self::DIMENSIONS as $j => $dimB) {
                if ($j > $i) {
                    $pairs[] = [$dimA, $dimB];
                }
            }
        }
        return $pairs;
    }
}
