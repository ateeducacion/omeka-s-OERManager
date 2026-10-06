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
 *
 * Usage (inside the container):
 *   php modules/OERManager/test/container/evaluation-set.php [--no-llm] [--runs=3] [--ids=1,2] [--out=/tmp/eval.json]
 *
 * Without --ids it discovers every lrmi:LearningResource with a .elpx/.zip medium
 * whose package declares an alignment. Exits 1 if no REA could be evaluated.
 */

chdir('/var/www/html');
require 'bootstrap.php';

use OERManager\Service\Ai\AiCataloguer;
use OERManager\Service\Ai\DeclaredAlignmentResolver;
use OERManager\Service\Ai\EvaluationScorer;
use OERManager\Service\Content\ElpxDeclaredAlignment;
use OERManager\Service\Content\MediaSourceInterface;

$application = \Omeka\Mvc\Application::init(require 'application/config/application.config.php');
$services = $application->getServiceManager();
$api = $services->get('Omeka\ApiManager');

$options = getopt('', ['no-llm', 'ids:', 'out:', 'runs:']);
$runs = max(1, (int) ($options['runs'] ?? 1));
$errors = 0;
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

$report = ['generated' => date('c'), 'llm' => !$noLlm, 'runs' => $runs, 'items' => [], 'summary' => []];
$perDimension = array_fill_keys(DIMENSIONS, []);
$leafHits = 0;
$leafTotal = 0;

foreach (array_values(array_unique($ids)) as $id) {
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
            $errors++;
            $row['runs'][] = ['error' => get_class($e) . ': ' . $e->getMessage()];
            printf("   run %d failed: %s\n", $run, $e->getMessage());
            continue;
        }
        $proposed = $proposal['alignment'] ?? [];
        $result = [
            'elapsed' => round(microtime(true) - $t0, 1),
            'proposed' => array_intersect_key($proposed, array_flip(DIMENSIONS)),
        ];
        foreach (DIMENSIONS as $dimension) {
            $score = $scorer->score($proposed[$dimension] ?? [], $truth[$dimension]);
            $perDimension[$dimension][] = $score;
            $result['scores'][$dimension] = $score;
        }
        $leaves = array_merge($truth['lrmi:teaches'], $truth['lrmi:assesses']);
        $hits = count(array_intersect($leaves, array_merge($proposed['lrmi:teaches'] ?? [], $proposed['lrmi:assesses'] ?? [])));
        $result['declared_leaves_hit'] = $hits . '/' . count($leaves);
        $leafHits += $hits;
        $leafTotal += count($leaves);
        $row['runs'][] = $result;
        printf("   run %d in %ss — declared leaves hit %d/%d, course F1 %.2f\n", $run, $result['elapsed'], $hits,
            count($leaves), $result['scores']['lrmi:educationalLevel']['f1']);
    }
    $report['items'][$id] = $row;
}

if (!$noLlm) {
    foreach (DIMENSIONS as $dimension) {
        $report['summary'][$dimension] = $scorer->macroAverage($perDimension[$dimension]);
    }
    $report['summary']['declared_leaves_hit'] = $leafHits . '/' . $leafTotal;
    $report['summary']['failed_runs'] = $errors;
}
file_put_contents($out, json_encode($report, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));

printf("\n%d REA in the set%s. Report: %s\n", count($report['items']),
    $noLlm ? '' : sprintf('; %d run(s) each; declared leaves hit %d/%d; failed runs %d', $runs, $leafHits, $leafTotal, $errors), $out);
foreach ($report['summary'] as $dimension => $summary) {
    if (is_array($summary)) {
        printf("   %-24s P %.2f  R %.2f  F1 %.2f\n", $dimension, $summary['precision'] ?? 0, $summary['recall'] ?? 0,
            $summary['f1'] ?? 0);
    }
}

exit($report['items'] ? 0 : 1);
