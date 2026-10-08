<?php

/**
 * Evaluation set of the AI cataloguer (TASK-057, NFR-011).
 *
 * Ground truth = the curricular alignment an eXeLearning package declares in its
 * `lomloe` iDevice (ElpxDeclaredAlignment), resolved against the catalogue's
 * curriculum (DeclaredAlignmentResolver). Each REA is proposed exactly as
 * IndexController::aiProposeAction() does and scored per dimension with
 * EvaluationScorer: course, subject, basic knowledge, criteria. Thematic axes are
 * not scored: packages do not declare them.
 *
 * READ-ONLY: runs `propose`, never `apply`; writes nothing to the catalogue.
 * It CALLS THE CONFIGURED LLM (paid tokens), so it runs on demand, never in CI.
 * `--no-llm` only checks that the ground truth resolves, at no token cost.
 * `--runs=N` proposes each REA N times: sampling varies between runs, so one run
 * is not a measure (2026-10-06: the same REA hit 3/3 and then 0/3).
 * The report is rewritten after every REA, so a crash loses nothing measured;
 * `--resume` keeps the REA already in the report and evaluates the rest, and
 * `--exclude=1,2` leaves out REA that crash the process (2026-10-08: a PDF in
 * a package exhausted PHP's memory at REA 32 of 52, an uncatchable fatal error).
 * `--rescore` recomputes every metric from the proposals stored in the report,
 * without calling the LLM.
 *
 * Cycle metrics (owner decision 2026-10-08): in Primaria a REA is aligned to the
 * two courses of its cycle and knowledge/criteria are worked within the cycle.
 * Besides the strict scores, each run reports the declared leaves hit within the
 * cycle (a cycle twin counts, CurriculumCycle::leafKey) and whether a proposed
 * course is in the declared course's cycle; the summary breaks both down by stage.
 *
 * Usage (inside the container):
 *   php modules/OERManager/test/container/evaluation-set.php [--no-llm] [--runs=3] [--ids=1,2]
 *       [--exclude=1,2] [--resume] [--rescore] [--out=/tmp/eval.json]
 *
 * Without --ids it discovers every lrmi:LearningResource with a .elpx/.zip medium
 * whose package declares an alignment. Exits 1 if no REA could be evaluated.
 */

chdir('/var/www/html');
require 'bootstrap.php';

use OERManager\Service\Ai\AiCataloguer;
use OERManager\Service\Ai\CurriculumCycle;
use OERManager\Service\Ai\DeclaredAlignmentResolver;
use OERManager\Service\Ai\EvaluationScorer;
use OERManager\Service\Content\ElpxDeclaredAlignment;
use OERManager\Service\Content\MediaSourceInterface;

$application = \Omeka\Mvc\Application::init(require 'application/config/application.config.php');
$services = $application->getServiceManager();
$api = $services->get('Omeka\ApiManager');

$options = getopt('', ['no-llm', 'ids:', 'out:', 'runs:', 'resume', 'exclude:', 'rescore']);
$runs = max(1, (int) ($options['runs'] ?? 1));
$exclude = array_flip(array_filter(array_map('intval', explode(',', (string) ($options['exclude'] ?? '')))));
$noLlm = isset($options['no-llm']);
$out = (string) ($options['out'] ?? '/tmp/oer-evaluation-set.json');
$ids = array_values(array_filter(array_map('intval', explode(',', (string) ($options['ids'] ?? '')))));

$reader = new ElpxDeclaredAlignment();
$resolver = new DeclaredAlignmentResolver($api);
$scorer = new EvaluationScorer();
$mediaSource = $services->get(MediaSourceInterface::class);
$store = $services->get('Omeka\File\Store');
$cataloguer = $noLlm ? null : $services->get(AiCataloguer::class);

const DIMENSIONS = ['lrmi:educationalLevel', 'schema:about', 'lrmi:teaches', 'lrmi:assesses'];

/** Same text as IndexController::itemMetadataText() (see propose-harness.php). */
function metadataText($item): string
{
    $parts = [];
    $title = trim((string) $item->displayTitle(''));
    if ('' !== $title) {
        $parts[] = $title;
    }
    foreach ($item->values() as $info) {
        foreach ($info['values'] as $value) {
            if ('literal' === $value->type() && '' !== trim((string) $value->value())) {
                $parts[] = trim((string) $value->value());
            }
        }
    }
    return implode("\n", array_values(array_unique($parts)));
}

/**
 * First package of the item that declares an alignment, or null. Read straight
 * from the stored original, NOT through MediaSourceInterface: the ground truth
 * must not depend on whether the extractor under evaluation accepts `.elpx`.
 */
function declaredFor($item, $store, ElpxDeclaredAlignment $reader): ?array
{
    foreach ($item->media() as $media) {
        $ext = strtolower((string) $media->extension());
        $filename = (string) $media->filename();
        if ('' === $filename || !in_array($ext, ['elpx', 'zip'], true) || !method_exists($store, 'getLocalPath')) {
            continue;
        }
        $declared = $reader->fromFile((string) $store->getLocalPath('original/' . $filename));
        if (null !== $declared) {
            return $declared + ['package' => (string) ($media->source() ?: $filename)];
        }
    }
    return null;
}

if (!$ids) {
    $classes = $api->search('resource_classes', ['term' => 'lrmi:LearningResource'])->getContent();
    $classId = $classes ? $classes[0]->id() : 0;
    for ($page = 1;; $page++) {
        $batch = $api->search('items', ['resource_class_id' => $classId, 'page' => $page, 'per_page' => 100])
            ->getContent();
        if (!$batch) {
            break;
        }
        foreach ($batch as $item) {
            foreach ($item->media() as $media) {
                if (in_array(strtolower((string) $media->extension()), ['elpx', 'zip'], true)) {
                    $ids[] = $item->id();
                    break;
                }
            }
        }
    }
}

/** dcterms:identifier (leaves) or title (courses) of a curriculum item, cached. */
function labelOf(int $id, $api, string $what): string
{
    static $cache = [];
    if (!isset($cache[$what][$id])) {
        try {
            $item = $api->read('items', $id)->getContent();
            $cache[$what][$id] = 'course' === $what
                ? trim((string) $item->displayTitle(''))
                : trim((string) ($item->value('dcterms:identifier') ?? ''));
        } catch (\Exception $e) {
            $cache[$what][$id] = '';
        }
    }
    return $cache[$what][$id];
}

/** Strict and cycle scores of one proposal against the truth. */
function scoreRun(array $proposed, array $truth, EvaluationScorer $scorer, $api): array
{
    $result = ['scores' => []];
    foreach (DIMENSIONS as $dimension) {
        $result['scores'][$dimension] = $scorer->score($proposed[$dimension] ?? [], $truth[$dimension]);
    }
    $truthLeaves = array_merge($truth['lrmi:teaches'], $truth['lrmi:assesses']);
    $proposedLeaves = array_merge($proposed['lrmi:teaches'] ?? [], $proposed['lrmi:assesses'] ?? []);
    $hits = count(array_intersect($truthLeaves, $proposedLeaves));
    $result['declared_leaves_hit'] = $hits . '/' . count($truthLeaves);

    $leafKey = static fn (int $id): string => CurriculumCycle::leafKey(labelOf($id, $api, 'leaf') ?: 'id:' . $id);
    $proposedKeys = array_flip(array_map($leafKey, $proposedLeaves));
    $cycleHits = count(array_filter($truthLeaves, static fn (int $id): bool => isset($proposedKeys[$leafKey($id)])));
    $courseKey = static fn (int $id): string => CurriculumCycle::courseKey(labelOf($id, $api, 'course')) ?? 'id:' . $id;
    $proposedCourses = array_flip(array_map($courseKey, $proposed['lrmi:educationalLevel'] ?? []));
    $result['cycle'] = [
        'leaves_hit' => $cycleHits . '/' . count($truthLeaves),
        'course_hit' => [] !== array_filter(
            $truth['lrmi:educationalLevel'],
            static fn (int $id): bool => isset($proposedCourses[$courseKey($id)])
        ),
    ];
    return $result;
}

$report = ['generated' => date('c'), 'llm' => !$noLlm, 'runs' => $runs, 'items' => [], 'summary' => []];
if ((isset($options['resume']) || isset($options['rescore'])) && is_file($out)) {
    $previous = json_decode((string) file_get_contents($out), true);
    $report['items'] = is_array($previous['items'] ?? null) ? $previous['items'] : [];
    $report['runs'] = (int) ($previous['runs'] ?? $runs);
    printf("Resuming: %d REA already in %s\n", count($report['items']), $out);
}

/** Summary over every REA of the report, including those of a resumed run, also by stage. */
function summarise(array $report, EvaluationScorer $scorer): array
{
    $perDimension = array_fill_keys(DIMENSIONS, []);
    $failed = 0;
    $add = static function (array &$bucket, array $run): void {
        foreach (['strict' => $run['declared_leaves_hit'], 'cycle' => $run['cycle']['leaves_hit'] ?? '0/0'] as $k => $v) {
            [$h, $t] = array_map('intval', explode('/', $v));
            $bucket[$k][0] = ($bucket[$k][0] ?? 0) + $h;
            $bucket[$k][1] = ($bucket[$k][1] ?? 0) + $t;
        }
        $bucket['runs'] = ($bucket['runs'] ?? 0) + 1;
        $bucket['course_exact'] = ($bucket['course_exact'] ?? 0) + ($run['scores']['lrmi:educationalLevel']['exact'] ? 1 : 0);
        $bucket['course_in_cycle'] = ($bucket['course_in_cycle'] ?? 0) + (($run['cycle']['course_hit'] ?? false) ? 1 : 0);
    };
    $all = [];
    $byStage = [];
    foreach ($report['items'] as $row) {
        $stage = (string) ($row['declared']['stage'] ?? '?');
        foreach ($row['runs'] ?? [] as $run) {
            if (isset($run['error'])) {
                $failed++;
                continue;
            }
            foreach (DIMENSIONS as $dimension) {
                $perDimension[$dimension][] = $run['scores'][$dimension];
            }
            $add($all, $run);
            $byStage[$stage] ??= [];
            $add($byStage[$stage], $run);
        }
    }
    $format = static fn (array $b): array => [
        'runs' => $b['runs'] ?? 0,
        'declared_leaves_hit' => ($b['strict'][0] ?? 0) . '/' . ($b['strict'][1] ?? 0),
        'cycle_leaves_hit' => ($b['cycle'][0] ?? 0) . '/' . ($b['cycle'][1] ?? 0),
        'course_exact' => ($b['course_exact'] ?? 0) . '/' . ($b['runs'] ?? 0),
        'course_in_cycle' => ($b['course_in_cycle'] ?? 0) . '/' . ($b['runs'] ?? 0),
    ];
    $summary = [];
    foreach (DIMENSIONS as $dimension) {
        $summary[$dimension] = $scorer->macroAverage($perDimension[$dimension]);
    }
    return $summary + $format($all) + [
        'failed_runs' => $failed,
        'by_stage' => array_map($format, $byStage),
    ];
}

function writeReport(string $out, array $report, EvaluationScorer $scorer, bool $noLlm): array
{
    if (!$noLlm) {
        $report['summary'] = summarise($report, $scorer);
    }
    file_put_contents($out, json_encode($report, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
    return $report;
}

if (isset($options['rescore'])) {
    foreach ($report['items'] as $id => $row) {
        foreach ($row['runs'] ?? [] as $i => $run) {
            if (!isset($run['error'])) {
                $report['items'][$id]['runs'][$i] = array_merge($run, scoreRun($run['proposed'], $row['truth'], $scorer, $api));
            }
        }
    }
    $ids = []; // rescore only: no new proposal
    $runs = (int) ($report['runs'] ?? $runs);
}

foreach (array_values(array_unique($ids)) as $id) {
    if (isset($exclude[$id]) || isset($report['items'][$id])) {
        continue;
    }
    try {
        $item = $api->read('items', $id)->getContent();
    } catch (\Exception $e) {
        continue;
    }
    $declared = declaredFor($item, $store, $reader);
    if (null === $declared) {
        continue; // no declared alignment: not part of the set
    }
    $truth = $resolver->resolve($declared);
    $row = [
        'title' => $item->displayTitle(''),
        'package' => $declared['package'],
        'declared' => array_intersect_key($declared, array_flip(['stage', 'courses', 'subjects', 'knowledge', 'criteria'])),
        'truth' => $truth,
    ];
    printf("#%d %s — truth: %d course, %d subject, %d knowledge, %d criteria, %d unresolved\n", $id, $row['title'],
        count($truth['lrmi:educationalLevel']), count($truth['schema:about']), count($truth['lrmi:teaches']),
        count($truth['lrmi:assesses']), count($truth['unresolved']));

    // Sampling is not deterministic (temperature > 0): one run per REA is not a
    // measure. Each run is scored on its own; failed runs (provider timeout…)
    // are counted apart and never scored as zero.
    for ($run = 1; null !== $cataloguer && $run <= $runs; $run++) {
        $t0 = microtime(true);
        try {
            $proposal = $cataloguer->propose(metadataText($item), $mediaSource->filesFor($id), $mediaSource->imagesFor($id));
        } catch (\Throwable $e) {
            $row['runs'][] = ['error' => get_class($e) . ': ' . $e->getMessage()];
            printf("   run %d failed: %s\n", $run, $e->getMessage());
            continue;
        }
        $proposed = $proposal['alignment'] ?? [];
        $result = [
            'elapsed' => round(microtime(true) - $t0, 1),
            'proposed' => array_intersect_key($proposed, array_flip(DIMENSIONS)),
        ] + scoreRun($proposed, $truth, $scorer, $api);
        $hits = (int) explode('/', $result['declared_leaves_hit'])[0];
        $leaves = array_merge($truth['lrmi:teaches'], $truth['lrmi:assesses']);
        $row['runs'][] = $result;
        printf("   run %d in %ss — declared leaves hit %d/%d, course F1 %.2f\n", $run, $result['elapsed'], $hits,
            count($leaves), $result['scores']['lrmi:educationalLevel']['f1']);
    }
    $report['items'][$id] = $row;
    $report = writeReport($out, $report, $scorer, $noLlm); // after every REA: a crash loses nothing
}

$report = writeReport($out, $report, $scorer, $noLlm);
$summary = $report['summary'];
printf("\n%d REA in the report%s. Report: %s\n", count($report['items']), $noLlm ? '' : sprintf(
    '; %d run(s) each; declared leaves hit %s; failed runs %d',
    $runs,
    $summary['declared_leaves_hit'],
    $summary['failed_runs']
), $out);
if (!$noLlm) {
    printf("   within the cycle: declared leaves hit %s; course in the declared cycle %s (exact %s)\n",
        $summary['cycle_leaves_hit'], $summary['course_in_cycle'], $summary['course_exact']);
    foreach ($summary['by_stage'] as $stage => $b) {
        printf("   %-20s runs %3d | leaves %s strict, %s in cycle | course %s exact, %s in cycle\n", $stage,
            $b['runs'], $b['declared_leaves_hit'], $b['cycle_leaves_hit'], $b['course_exact'], $b['course_in_cycle']);
    }
}
foreach (DIMENSIONS as $dimension) {
    if (isset($summary[$dimension])) {
        printf("   %-24s P %.2f  R %.2f  F1 %.2f\n", $dimension, $summary[$dimension]['precision'],
            $summary[$dimension]['recall'], $summary[$dimension]['f1']);
    }
}

exit($report['items'] ? 0 : 1);
