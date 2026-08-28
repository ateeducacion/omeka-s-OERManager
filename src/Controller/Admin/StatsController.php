<?php

declare(strict_types=1);

namespace OERManager\Controller\Admin;

use Laminas\Mvc\Controller\AbstractActionController;
use Laminas\View\Model\ViewModel;
use OERManager\Service\ComputedFilter;
use OERManager\Service\IntegrityChecker;
use OERManager\Service\Stats\CatalogSnapshot;
use OERManager\Service\Stats\CompletenessAggregator;
use OERManager\Service\Stats\CsvExport;
use OERManager\Service\Stats\DimensionCounter;
use OERManager\Service\Stats\DimensionCrosser;
use OERManager\Service\Stats\DimensionFacts;
use Omeka\Api\Manager as ApiManager;

/**
 * Estadísticas visuales del catálogo (RF-007, ADR-0004, TASK-006). Página de
 * solo lectura, independiente de los filtros de la vista maestra (spec §2.3):
 * siempre agrega sobre el catálogo completo, hasta ComputedFilter::HARD_CAP.
 */
class StatsController extends AbstractActionController
{
    /** Las 5 dimensiones de ADR-0004, en el orden en que se pintan. */
    private const DIMENSIONS = ['etapa', 'materia', 'eje', 'proyecto', 'licencia'];

    /** Etiquetas humanas por dimensión, mismas cadenas que usa la vista (index.phtml). */
    private const DIMENSION_LABELS = [
        'etapa' => 'Etapa', // @translate
        'materia' => 'Materia', // @translate
        'eje' => 'Eje temático', // @translate
        'proyecto' => 'Proyecto', // @translate
        'licencia' => 'Licencia', // @translate
    ];

    private CatalogSnapshot $catalogSnapshot;
    private DimensionFacts $dimensionFacts;
    private DimensionCounter $dimensionCounter;
    private DimensionCrosser $dimensionCrosser;
    private CompletenessAggregator $completenessAggregator;
    private CsvExport $csvExport;
    private IntegrityChecker $integrityChecker;
    private ApiManager $api;

    public function __construct(
        CatalogSnapshot $catalogSnapshot,
        DimensionFacts $dimensionFacts,
        DimensionCounter $dimensionCounter,
        DimensionCrosser $dimensionCrosser,
        CompletenessAggregator $completenessAggregator,
        CsvExport $csvExport,
        IntegrityChecker $integrityChecker,
        ApiManager $api
    ) {
        $this->catalogSnapshot = $catalogSnapshot;
        $this->dimensionFacts = $dimensionFacts;
        $this->dimensionCounter = $dimensionCounter;
        $this->dimensionCrosser = $dimensionCrosser;
        $this->completenessAggregator = $completenessAggregator;
        $this->csvExport = $csvExport;
        $this->integrityChecker = $integrityChecker;
        $this->api = $api;
    }

    public function indexAction()
    {
        $snapshot = $this->catalogSnapshot->fetch();
        $facts = $this->dimensionFacts->extract($snapshot['items']);
        $titles = $this->resolveTitles($facts);

        $counts = [];
        foreach (self::DIMENSIONS as $dimension) {
            $counts[$dimension] = $this->relabelCounts(
                $this->dimensionCounter->count($facts, $dimension),
                $dimension,
                $titles
            );
        }

        $cross = [];
        foreach ($this->dimensionPairs() as [$dimA, $dimB]) {
            $table = $this->dimensionCrosser->cross($facts, $dimA, $dimB);
            $labeled = $this->relabelCrossTable($table, $dimA, $dimB, $titles);
            $cross["$dimA/$dimB"] = $labeled;
            $cross["$dimB/$dimA"] = $this->transpose($labeled);
        }

        $completeness = $this->completenessAggregator->aggregate($this->statusesFor($snapshot['items']));

        $view = new ViewModel();
        $view->setTemplate('oer-manager/admin/stats/index');
        $view->setVariable('dimensions', self::DIMENSIONS);
        $view->setVariable('completeness', $completeness);
        $view->setVariable('truncated', $snapshot['truncated']);
        $view->setVariable('statsData', ['counts' => $counts, 'cross' => $cross, 'completeness' => $completeness]);
        return $view;
    }

    public function exportAction()
    {
        $type = (string) $this->params()->fromQuery('type', '');
        $snapshot = $this->catalogSnapshot->fetch();

        if ('completeness' === $type) {
            $result = $this->completenessAggregator->aggregate($this->statusesFor($snapshot['items']));
            $rows = [
                ['ok', $result['ok']],
                ['warning', $result['warning']],
                ['error', $result['error']],
            ];
            if ($snapshot['truncated']) {
                $rows[] = $this->truncationNoteRow();
            }
            $csv = $this->csvExport->toCsv(['estado', 'conteo'], $rows);
            return $this->csvResponse($csv, 'oer-completitud.csv');
        }

        // El resto de ramas (cruce y simple) sí necesitan los hechos por dimensión.
        $facts = $this->dimensionFacts->extract($snapshot['items']);

        $dimension1 = (string) $this->params()->fromQuery('dimension1', '');
        $dimension2 = (string) $this->params()->fromQuery('dimension2', '');

        if ('' !== $dimension1 && '' !== $dimension2) {
            if (
                !in_array($dimension1, self::DIMENSIONS, true)
                || !in_array($dimension2, self::DIMENSIONS, true)
                || $dimension1 === $dimension2
            ) {
                return $this->unknownDimensionResponse();
            }
            $titles = $this->resolveTitles($facts);
            $table = $this->relabelCrossTable(
                $this->dimensionCrosser->cross($facts, $dimension1, $dimension2),
                $dimension1,
                $dimension2,
                $titles
            );
            $rows = [];
            foreach ($table as $labelA => $bCounts) {
                foreach ($bCounts as $labelB => $count) {
                    $rows[] = [$labelA, $labelB, $count];
                }
            }
            if ($snapshot['truncated']) {
                $rows[] = $this->truncationNoteRow();
            }
            $csv = $this->csvExport->toCsv(
                [self::DIMENSION_LABELS[$dimension1], self::DIMENSION_LABELS[$dimension2], 'conteo'],
                $rows
            );
            return $this->csvResponse($csv, "oer-cruce-{$dimension1}-{$dimension2}.csv");
        }

        $dimension = (string) $this->params()->fromQuery('dimension', '');
        if (!in_array($dimension, self::DIMENSIONS, true)) {
            return $this->unknownDimensionResponse();
        }
        $titles = $this->resolveTitles($facts);
        $counts = $this->relabelCounts($this->dimensionCounter->count($facts, $dimension), $dimension, $titles);
        $rows = [];
        foreach ($counts as $row) {
            $rows[] = [$row['label'], $row['count']];
        }
        if ($snapshot['truncated']) {
            $rows[] = $this->truncationNoteRow();
        }
        $csv = $this->csvExport->toCsv([self::DIMENSION_LABELS[$dimension], 'conteo'], $rows);
        return $this->csvResponse($csv, "oer-{$dimension}.csv");
    }

    /**
     * @param \Omeka\Api\Representation\ItemRepresentation[] $items
     * @return list<string> El status (ok/warning/error) de cada item (RF-006).
     */
    private function statusesFor(array $items): array
    {
        $statuses = [];
        foreach ($items as $item) {
            $statuses[] = $this->integrityChecker->check($item, false)->getStatus();
        }
        return $statuses;
    }

    /**
     * Fila extra que avisa de la acotación del catálogo (ADR-0013: nunca
     * mentir en silencio sobre un total) cuando el CSV exportado se calculó
     * sobre un catálogo truncado por ComputedFilter::HARD_CAP.
     *
     * @return list<string>
     */
    private function truncationNoteRow(): array
    {
        return ['# NOTA', 'Resultado acotado a los primeros ' . ComputedFilter::HARD_CAP . ' REA del catálogo'];
    }

    private function csvResponse(string $csv, string $filename): \Laminas\Http\Response
    {
        $response = $this->getResponse();
        $response->setContent($csv);
        $headers = $response->getHeaders();
        $headers->addHeaderLine('Content-Type', 'text/csv; charset=utf-8');
        $headers->addHeaderLine('Content-Disposition', 'attachment; filename="' . $filename . '"');
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

    /**
     * Ids de término distintos de los hechos, resueltos a título en UNA sola
     * llamada batch (spec §4) — nunca N lecturas.
     *
     * @param array<int, array<string, int[]|string|null>> $facts
     * @return array<int, string>
     */
    private function resolveTitles(array $facts): array
    {
        $ids = $this->dimensionFacts->distinctResourceIds($facts);
        if ([] === $ids) {
            return [];
        }
        $response = $this->api->search('items', ['id' => $ids]);
        $titles = [];
        foreach ($response->getContent() as $item) {
            $titles[(int) $item->id()] = (string) $item->displayTitle();
        }
        return $titles;
    }

    /**
     * @param array<int|string, int> $counts
     * @param array<int, string> $titles
     * @return list<array{label:string,count:int}>
     */
    private function relabelCounts(array $counts, string $dimension, array $titles): array
    {
        $rows = [];
        foreach ($counts as $value => $count) {
            $rows[] = ['label' => $this->labelFor($dimension, $value, $titles), 'count' => $count];
        }
        usort($rows, static fn (array $a, array $b): int => $b['count'] <=> $a['count']);
        return $rows;
    }

    /**
     * @param array<int|string, array<int|string, int>> $table
     * @param array<int, string> $titles
     * @return array<string, array<string, int>>
     */
    private function relabelCrossTable(array $table, string $dimA, string $dimB, array $titles): array
    {
        $out = [];
        foreach ($table as $a => $bCounts) {
            $labelA = $this->labelFor($dimA, $a, $titles);
            foreach ($bCounts as $b => $count) {
                $labelB = $this->labelFor($dimB, $b, $titles);
                $out[$labelA][$labelB] = ($out[$labelA][$labelB] ?? 0) + $count;
            }
        }
        return $out;
    }

    /** @param array<int, string> $titles */
    private function labelFor(string $dimension, int|string $value, array $titles): string
    {
        return 'licencia' === $dimension ? (string) $value : ($titles[$value] ?? (string) $value);
    }

    /**
     * @param array<string, array<string, int>> $table
     * @return array<string, array<string, int>>
     */
    private function transpose(array $table): array
    {
        $out = [];
        foreach ($table as $a => $bCounts) {
            foreach ($bCounts as $b => $count) {
                $out[$b][$a] = $count;
            }
        }
        return $out;
    }
}
