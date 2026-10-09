<?php

/**
 * Evaluation set of the AI cataloguer (TASK-057, TASK-059, NFR-011).
 *
 * Ground truth = the curricular alignment an eXeLearning package declares in its
 * `lomloe` iDevice (ElpxDeclaredAlignment), resolved against the catalogue's
 * curriculum (DeclaredAlignmentResolver). Each REA is proposed exactly as
 * IndexController::aiProposeAction() does and scored per dimension: course,
 * subject, basic knowledge, criteria. Thematic axes are not scored: packages do
 * not declare them.
 *
 * READ-ONLY: runs `propose`, never `apply`; writes nothing to the catalogue.
 * It CALLS THE CONFIGURED LLM (paid tokens), so it runs on demand, never in CI.
 *
 * Options:
 *   --no-llm        only checks that the ground truth resolves, at no token cost
 *   --runs=N        proposes each REA N times: sampling varies between runs, so one
 *                   run is not a measure (the same REA hit 3/3 once and 0/3 next)
 *   --ids=1,2       evaluates these REA (default: every REA whose package declares)
 *   --exclude=1,2   leaves out REA that crash the process
 *   --resume        keeps the REA already in the report and evaluates the rest
 *   --rescore       recomputes every metric from the stored proposals, no LLM call
 *   --price=model:in:out  USD per million input/output tokens, used ONLY for the
 *                   calls whose provider sent no cost (OpenRouter always sends it)
 *   --label=text    free label of this strategy in the report
 *   --strategy=jev  TASK-062: Jev (typesafe/jev-1.13, OpenRouter) picks knowledge and criteria,
 *                   built in memory for this run; stored settings are not touched
 *   --threshold=x   P(yes) threshold of the jev strategy (default 0.6); with --rescore it
 *                   re-decides knowledge/criteria from the stored probabilities (no call)
 *   --caps=k,c      jev strategy: propose at most k knowledge and c criteria items of those
 *                   at or above the threshold (default 4,3; 0 = every one). Course and the
 *                   criteria course filter still come from every item at or above it
 *   --out=path      report (default /tmp/oer-evaluation-set.json), rewritten after
 *                   every REA so a crash loses nothing measured
 *
 * Metrics, per run and summarised overall and by stage (TASK-059, for comparing
 * strategies on the same 52 REA):
 *   - strict and cycle scores: in Primaria and Infantil a REA is aligned to the
 *     courses of its cycle (owner decisions 2026-10-08), so a cycle twin of a
 *     declared leaf, or a course of its cycle, counts in the cycle reading;
 *   - precision/recall/F1 per dimension, macro (mean per run) and micro;
 *   - where each declared leaf was lost: chosen, shown and not chosen, cut
 *     (cap, block, criteria course filter) or never gathered;
 *   - latency per phase, real cost and tokens per run and per MB of media.
 */

chdir('/var/www/html');
require 'bootstrap.php';

use OERManager\Service\Ai\AiCataloguer;
use OERManager\Service\Ai\CurriculumCycle;
use OERManager\Service\Ai\DeclaredAlignmentResolver;
use OERManager\Service\Ai\EvaluationMetrics;
use OERManager\Service\Ai\EvaluationScorer;
use OERManager\Service\Content\ElpxDeclaredAlignment;
use OERManager\Service\Content\MediaSourceInterface;
use OERManager\Service\Llm\LlmSettings;
use OERManager\Service\Ai\ContextDistiller;
use OERManager\Service\Ai\CurricularClassifier;
use OERManager\Service\Ai\JevLeafSelector;
use OERManager\Service\Ai\PromptBuilder;
use OERManager\Service\Ai\ResponseParser;
use OERManager\Service\Ai\TagClassifier;
use OERManager\Service\Ai\TermResolverInterface;
use OERManager\Service\Content\ContentExtractor;
use OERManager\Service\Content\MediaVisionExtractor;
use OERManager\Service\Llm\HttpTransportInterface;
use OERManager\Service\Llm\LlmClientInterface;
use OERManager\Service\Llm\OpenRouterDecisionClient;

$application = \Omeka\Mvc\Application::init(require 'application/config/application.config.php');
$services = $application->getServiceManager();
$api = $services->get('Omeka\ApiManager');
$settings = $services->get('Omeka\Settings');

$options = getopt('', ['no-llm', 'ids:', 'out:', 'runs:', 'resume', 'exclude:', 'rescore', 'price:', 'label:',
    'strategy:', 'threshold:', 'caps:']);
$strategy = (string) ($options['strategy'] ?? 'llm');
$threshold = isset($options['threshold']) ? LlmSettings::parseDecisionThreshold($options['threshold']) : null;
$capsOption = explode(',', (string) ($options['caps'] ?? ''));
$caps = LlmSettings::decisionCaps($capsOption[0] ?? null, $capsOption[1] ?? null);
$runs = max(1, (int) ($options['runs'] ?? 1));
$exclude = array_flip(array_filter(array_map('intval', explode(',', (string) ($options['exclude'] ?? '')))));
$noLlm = isset($options['no-llm']);
$out = (string) ($options['out'] ?? '/tmp/oer-evaluation-set.json');
$ids = array_values(array_filter(array_map('intval', explode(',', (string) ($options['ids'] ?? '')))));
$prices = [];
foreach ((array) ($options['price'] ?? []) as $spec) {
    // «model:in:out»; the model name itself may contain «:»
    if (1 === preg_match('/^(.+):([\d.]+):([\d.]+)$/', (string) $spec, $m)) {
        $prices[$m[1]] = ['in' => (float) $m[2], 'out' => (float) $m[3]];
    }
}

$reader = new ElpxDeclaredAlignment();
$resolver = new DeclaredAlignmentResolver($api);
$scorer = new EvaluationScorer();
$mediaSource = $services->get(MediaSourceInterface::class);
$store = $services->get('Omeka\File\Store');
$cataloguer = $noLlm ? null : $services->get(AiCataloguer::class);
if (!$noLlm && 'jev' === $strategy) {
    // Same wiring as module.config.php, plus the Jev selector; in memory only.
    $selector = new JevLeafSelector(
        new OpenRouterDecisionClient($services->get(HttpTransportInterface::class), [
            'api_key' => (string) $settings->get(LlmSettings::API_KEY, ''),
            'model' => (string) ($settings->get(LlmSettings::DECISION_MODEL) ?: LlmSettings::DEFAULT_DECISION_MODEL),
            'base_url' => (string) $settings->get(LlmSettings::BASE_URL, ''),
        ]),
        $services->get(PromptBuilder::class),
        $threshold ?? LlmSettings::DEFAULT_DECISION_THRESHOLD,
        200,
        $caps
    );
    $cataloguer = new AiCataloguer(
        $services->get(ContentExtractor::class),
        $services->get(MediaVisionExtractor::class),
        $services->get(ContextDistiller::class),
        new CurricularClassifier(
            $services->get(LlmClientInterface::class),
            $services->get(TermResolverInterface::class),
            $services->get(PromptBuilder::class),
            $services->get(ResponseParser::class),
            LlmSettings::parseMaxTokens($settings->get(LlmSettings::MAX_TOKENS)),
            LlmSettings::parseTemperature($settings->get(LlmSettings::TEMPERATURE)),
            $selector
        ),
        $services->get(TagClassifier::class)
    );
}

const DIMENSIONS = ['lrmi:educationalLevel', 'schema:about', 'lrmi:teaches', 'lrmi:assesses'];
const LEAF_STEPS = ['lrmi:teaches' => 'Saberes básicos', 'lrmi:assesses' => 'Criterios de evaluación'];
const PHASES = ['extraction_ms', 'vision_ms', 'distillation_ms', 'curricular_ms', 'tags_ms'];

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

/** Commit of the module working tree, read from .git without running git. */
function moduleCommit(): string
{
    $git = dirname(__DIR__, 2) . '/.git';
    $head = is_file($git . '/HEAD') ? trim((string) file_get_contents($git . '/HEAD')) : '';
    if (str_starts_with($head, 'ref: ')) {
        $ref = substr($head, 5);
        if (is_file($git . '/' . $ref)) {
            return substr(trim((string) file_get_contents($git . '/' . $ref)), 0, 12) . ' (' . basename($ref) . ')';
        }
        foreach (is_file($git . '/packed-refs') ? file($git . '/packed-refs') : [] as $line) {
            if (str_ends_with(trim($line), ' ' . $ref)) {
                return substr($line, 0, 12) . ' (' . basename($ref) . ')';
            }
        }
        return '? (' . basename($ref) . ')';
    }
    return '' === $head ? '?' : substr($head, 0, 12);
}

/**
 * Every metric of one run, from what the report stores (so --rescore works on
 * old reports too: metrics whose data a run lacks are simply left out).
 */
function scoreRun(array $run, array $truth, EvaluationScorer $scorer, $api): array
{
    $proposed = $run['proposed'] ?? [];
    $leafKey = static fn (int $id): string => CurriculumCycle::leafKey(labelOf($id, $api, 'leaf') ?: 'id:' . $id);
    $courseKey = static fn (int $id): string => CurriculumCycle::courseKey(labelOf($id, $api, 'course')) ?? 'id:' . $id;
    $identity = static fn (int $id): string => (string) $id;

    $result = ['scores' => [], 'cycle_scores' => []];
    foreach (DIMENSIONS as $dimension) {
        $result['scores'][$dimension] = $scorer->score($proposed[$dimension] ?? [], $truth[$dimension]);
        $key = match ($dimension) {
            'lrmi:educationalLevel' => $courseKey,
            'lrmi:teaches', 'lrmi:assesses' => $leafKey,
            default => $identity,
        };
        $result['cycle_scores'][$dimension] = keyedScore($proposed[$dimension] ?? [], $truth[$dimension], $key);
    }
    $truthLeaves = array_merge($truth['lrmi:teaches'], $truth['lrmi:assesses']);
    $proposedLeaves = array_merge($proposed['lrmi:teaches'] ?? [], $proposed['lrmi:assesses'] ?? []);
    $result['declared_leaves_hit'] = count(array_intersect($truthLeaves, $proposedLeaves)) . '/' . count($truthLeaves);
    $cycleHits = $result['cycle_scores']['lrmi:teaches']['tp'] + $result['cycle_scores']['lrmi:assesses']['tp'];
    $result['cycle'] = [
        'leaves_hit' => $cycleHits . '/' . count($truthLeaves),
        'course_hit' => $result['cycle_scores']['lrmi:educationalLevel']['tp'] > 0,
    ];

    if (isset($run['leaf_steps'])) {
        foreach (['strict' => $identity, 'cycle' => $leafKey] as $reading => $key) {
            $total = ['chosen' => 0, 'shown_not_chosen' => 0, 'cut' => 0, 'not_gathered' => 0];
            foreach (LEAF_STEPS as $dimension => $label) {
                $step = $run['leaf_steps'][$dimension] ?? ['gathered' => [], 'shown' => [], 'chosen' => []];
                foreach (EvaluationMetrics::reach($truth[$dimension], $step['gathered'], $step['shown'], $step['chosen'], $key) as $k => $n) {
                    $total[$k] += $n;
                }
            }
            $result['reach'][$reading] = $total;
        }
    }
    return $result;
}

/**
 * tp/fp/fn and P/R/F1 comparing keys instead of ids: a truth item is found when
 * a proposed item shares its key (its cycle twin, or its course's cycle).
 */
function keyedScore(array $proposed, array $truth, callable $key): array
{
    $proposedKeys = array_count_values(array_map($key, array_values(array_unique($proposed))));
    $truthKeys = array_flip(array_map($key, $truth));
    $tp = count(array_filter(array_values(array_unique($truth)), static fn (int $id): bool => isset($proposedKeys[$key($id)])));
    $fp = 0;
    foreach ($proposedKeys as $k => $n) {
        $fp += isset($truthKeys[$k]) ? 0 : $n;
    }
    $fn = count(array_unique($truth)) - $tp;
    return EvaluationMetrics::micro([['tp' => $tp, 'fp' => $fp, 'fn' => $fn]]) + ['exact' => false];
}

/** Leaf ids each step saw, from the classifier trace (TASK-059 instrumentation). */
function leafSteps(array $curricularTrace): ?array
{
    $steps = [];
    foreach ($curricularTrace as $entry) {
        $dimension = array_search($entry['step'] ?? '', LEAF_STEPS, true);
        if (false !== $dimension && isset($entry['candidate_ids'])) {
            $steps[$dimension] = [
                'gathered' => array_map('intval', $entry['gathered_ids'] ?? []),
                'shown' => array_map('intval', $entry['candidate_ids']),
                'chosen' => array_map('intval', $entry['selected_ids'] ?? []),
                'strategy' => (string) ($entry['strategy'] ?? 'llm'),
            ] + (isset($entry['probabilities']) ? ['probabilities' => $entry['probabilities']] : [])
                + (isset($entry['anchor_ids']) ? ['anchors' => array_map('intval', $entry['anchor_ids'])] : []);
        }
    }
    return $steps ?: null;
}

/** Cost of a run: real cost where the provider sent it, --price for the rest. */
function runCost(array $usage, array $prices): array
{
    $known = $usage['cost'];
    $estimated = 0.0;
    $uncovered = 0;
    foreach ($usage['by_model'] as $model => $m) {
        if ($m['calls_without_cost'] > 0 && isset($prices[$model])) {
            // Without per-call tokens, the model's share is prorated by calls.
            $share = $m['calls_without_cost'] / max(1, $m['calls']);
            $estimated += $share * ($m['input_tokens'] * $prices[$model]['in'] + $m['output_tokens'] * $prices[$model]['out']) / 1e6;
        } elseif ($m['calls_without_cost'] > 0) {
            $uncovered += $m['calls_without_cost'];
        }
    }
    return ['real' => $known, 'estimated' => $estimated, 'calls_uncovered' => $uncovered,
        'total' => (null === $known && 0.0 === $estimated) ? null : (float) $known + $estimated];
}

/**
 * Re-decides knowledge and criteria of a Jev run at another threshold from the
 * stored P(yes) of every candidate, and re-derives the courses from the chosen
 * leaves. Approximation, stated in the report: the criteria candidates are the
 * ones of the original run (they depended on the knowledge chosen then), and
 * the subjects are left as proposed.
 */
function rethreshold(array $run, float $threshold, $api): array
{
    $courseOf = static function (int $leafId) use ($api): ?int {
        static $cache = [];
        if (!array_key_exists($leafId, $cache)) {
            try {
                $value = $api->read('items', $leafId)->getContent()->value('lrmi:educationalAlignment');
                $cache[$leafId] = $value && $value->valueResource() ? $value->valueResource()->id() : null;
            } catch (\Exception $e) {
                $cache[$leafId] = null;
            }
        }
        return $cache[$leafId];
    };
    $changed = false;
    foreach (LEAF_STEPS as $dimension => $label) {
        $step = $run['leaf_steps'][$dimension] ?? null;
        if (!is_array($step) || !isset($step['probabilities'])) {
            continue;
        }
        $chosen = [];
        foreach ($step['probabilities'] as $leafId => $p) {
            if (null !== $p && (float) $p >= $threshold) {
                $chosen[(int) $leafId] = (float) $p;
            }
        }
        arsort($chosen);
        $run['proposed'][$dimension] = array_keys($chosen);
        $run['leaf_steps'][$dimension]['chosen'] = array_keys($chosen);
        $changed = true;
    }
    if ($changed) {
        $leaves = array_merge($run['proposed']['lrmi:teaches'] ?? [], $run['proposed']['lrmi:assesses'] ?? []);
        $run['proposed']['lrmi:educationalLevel'] = array_values(array_unique(array_filter(array_map($courseOf, $leaves))));
        $run['rethresholded'] = $threshold;
    }
    return $run;
}

/** Summary over the runs of a set of REA. */
function summariseRows(array $rows, EvaluationScorer $scorer, array $prices): array
{
    $strict = $cycle = array_fill_keys(DIMENSIONS, []);
    $hits = ['strict' => [0, 0], 'cycle' => [0, 0]];
    $reach = ['strict' => [], 'cycle' => []];
    $course = ['exact' => 0, 'in_cycle' => 0];
    $elapsed = [];
    $phases = array_fill_keys(PHASES, []);
    $cost = ['total' => 0.0, 'runs_with_cost' => 0, 'mb' => 0.0, 'tokens_in' => 0, 'tokens_out' => 0,
        'calls' => 0, 'calls_uncovered' => 0, 'by_model' => []];
    $runsOk = $failed = 0;
    foreach ($rows as $row) {
        foreach ($row['runs'] ?? [] as $run) {
            if (isset($run['error'])) {
                $failed++;
                continue;
            }
            $runsOk++;
            foreach (DIMENSIONS as $dimension) {
                $strict[$dimension][] = $run['scores'][$dimension];
                if (isset($run['cycle_scores'][$dimension])) {
                    $cycle[$dimension][] = $run['cycle_scores'][$dimension];
                }
            }
            foreach (['strict' => $run['declared_leaves_hit'], 'cycle' => $run['cycle']['leaves_hit'] ?? '0/0'] as $k => $v) {
                [$h, $t] = array_map('intval', explode('/', $v));
                $hits[$k][0] += $h;
                $hits[$k][1] += $t;
            }
            $course['exact'] += $run['scores']['lrmi:educationalLevel']['exact'] ? 1 : 0;
            $course['in_cycle'] += ($run['cycle']['course_hit'] ?? false) ? 1 : 0;
            foreach ($run['reach'] ?? [] as $reading => $counts) {
                foreach ($counts as $k => $n) {
                    $reach[$reading][$k] = ($reach[$reading][$k] ?? 0) + $n;
                }
            }
            if (isset($run['elapsed'])) {
                $elapsed[] = (float) $run['elapsed'];
            }
            foreach (PHASES as $phase) {
                if (isset($run['timings'][$phase])) {
                    $phases[$phase][] = (int) $run['timings'][$phase];
                }
            }
            if (isset($run['usage'])) {
                $c = runCost($run['usage'], $prices);
                $cost['calls'] += $run['usage']['calls'];
                $cost['tokens_in'] += $run['usage']['input_tokens'];
                $cost['tokens_out'] += $run['usage']['output_tokens'];
                $cost['calls_uncovered'] += $c['calls_uncovered'];
                if (null !== $c['total']) {
                    $cost['total'] += $c['total'];
                    $cost['runs_with_cost']++;
                    $cost['mb'] += (float) ($row['media_mb'] ?? 0);
                }
                foreach ($run['usage']['by_model'] as $model => $m) {
                    $b = &$cost['by_model'][$model];
                    $b ??= ['calls' => 0, 'input_tokens' => 0, 'output_tokens' => 0, 'cost' => 0.0];
                    $b['calls'] += $m['calls'];
                    $b['input_tokens'] += $m['input_tokens'];
                    $b['output_tokens'] += $m['output_tokens'];
                    $b['cost'] += $m['cost'];
                    unset($b);
                }
            }
        }
    }
    $dimensions = [];
    foreach (DIMENSIONS as $dimension) {
        $dimensions[$dimension] = [
            'macro' => $scorer->macroAverage($strict[$dimension]),
            'micro' => EvaluationMetrics::micro($strict[$dimension]),
            'cycle_micro' => EvaluationMetrics::micro($cycle[$dimension]),
        ];
    }
    return [
        'runs' => $runsOk,
        'failed_runs' => $failed,
        'dimensions' => $dimensions,
        'declared_leaves_hit' => $hits['strict'][0] . '/' . $hits['strict'][1],
        'cycle_leaves_hit' => $hits['cycle'][0] . '/' . $hits['cycle'][1],
        'course_exact' => $course['exact'] . '/' . $runsOk,
        'course_in_cycle' => $course['in_cycle'] . '/' . $runsOk,
        'reach' => $reach,
        'latency_s' => EvaluationMetrics::distribution($elapsed),
        'phases_ms' => array_map([EvaluationMetrics::class, 'distribution'], $phases),
        'cost' => $cost + [
            'per_run' => $cost['runs_with_cost'] ? $cost['total'] / $cost['runs_with_cost'] : null,
            'per_mb' => $cost['mb'] > 0 ? $cost['total'] / $cost['mb'] : null,
        ],
    ];
}

function summarise(array $report, EvaluationScorer $scorer, array $prices): array
{
    $byStage = [];
    foreach ($report['items'] as $row) {
        $byStage[(string) ($row['declared']['stage'] ?? '?')][] = $row;
    }
    ksort($byStage);
    return summariseRows($report['items'], $scorer, $prices) + [
        'by_stage' => array_map(static fn (array $rows): array => summariseRows($rows, $scorer, $prices), $byStage),
    ];
}

function writeReport(string $out, array $report, EvaluationScorer $scorer, bool $noLlm, array $prices): array
{
    if (!$noLlm) {
        $report['summary'] = summarise($report, $scorer, $prices);
    }
    file_put_contents($out, json_encode($report, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
    return $report;
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

$report = [
    'generated' => date('c'),
    'llm' => !$noLlm,
    'runs' => $runs,
    'config' => [
        'label' => (string) ($options['label'] ?? ''),
        'commit' => moduleCommit(),
        'provider' => (string) $settings->get(LlmSettings::PROVIDER),
        'base_url_host' => (string) parse_url((string) $settings->get(LlmSettings::BASE_URL), PHP_URL_HOST),
        'classification_model' => (string) $settings->get(LlmSettings::MODEL),
        'extraction_model' => (string) $settings->get(LlmSettings::EXTRACTION_MODEL),
        'temperature' => $settings->get(LlmSettings::TEMPERATURE),
        'vision_enabled' => (bool) $settings->get(LlmSettings::VISION_ENABLED),
        'prices' => $prices,
        'strategy' => $strategy,
        'decision_model' => 'jev' === $strategy
            ? (string) ($settings->get(LlmSettings::DECISION_MODEL) ?: LlmSettings::DEFAULT_DECISION_MODEL) : '',
        'threshold' => 'jev' === $strategy ? ($threshold ?? LlmSettings::DEFAULT_DECISION_THRESHOLD) : null,
        'caps' => 'jev' === $strategy ? $caps : null,
    ],
    'items' => [],
    'summary' => [],
];
if ((isset($options['resume']) || isset($options['rescore'])) && is_file($out)) {
    $previous = json_decode((string) file_get_contents($out), true);
    $report['items'] = is_array($previous['items'] ?? null) ? $previous['items'] : [];
    $report['runs'] = (int) ($previous['runs'] ?? $runs);
    $report['config'] = ($previous['config'] ?? []) + ['prices' => $prices];
    $report['config']['prices'] = $prices ?: ($previous['config']['prices'] ?? []);
    printf("Resuming: %d REA already in %s\n", count($report['items']), $out);
}
$prices = $report['config']['prices'] ?? $prices;

if (isset($options['rescore'])) {
    foreach ($report['items'] as $id => $row) {
        foreach ($row['runs'] ?? [] as $i => $run) {
            if (isset($run['error'])) {
                continue;
            }
            if (null !== $threshold) {
                $run = rethreshold($run, $threshold, $api);
            }
            $report['items'][$id]['runs'][$i] = array_merge($run, scoreRun($run, $row['truth'], $scorer, $api));
        }
    }
    if (null !== $threshold) {
        $report['config']['threshold'] = $threshold;
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
    $files = $mediaSource->filesFor($id);
    $row = [
        'title' => $item->displayTitle(''),
        'package' => $declared['package'],
        'media_mb' => round(array_sum(array_map(static fn (array $f): int => (int) ($f['size'] ?? 0), $files)) / 1048576, 3),
        'declared' => array_intersect_key($declared, array_flip(['stage', 'courses', 'subjects', 'knowledge', 'criteria'])),
        'truth' => $truth,
    ];
    printf("#%d %s — truth: %d course, %d subject, %d knowledge, %d criteria, %d unresolved, %.1f MB\n", $id,
        $row['title'], count($truth['lrmi:educationalLevel']), count($truth['schema:about']),
        count($truth['lrmi:teaches']), count($truth['lrmi:assesses']), count($truth['unresolved']), $row['media_mb']);

    // Sampling is not deterministic (temperature > 0): one run per REA is not a
    // measure. Each run is scored on its own; failed runs (provider timeout…)
    // are counted apart and never scored as zero.
    for ($run = 1; null !== $cataloguer && $run <= $runs; $run++) {
        $t0 = microtime(true);
        try {
            $proposal = $cataloguer->propose(metadataText($item), $files, $mediaSource->imagesFor($id));
        } catch (\Throwable $e) {
            $row['runs'][] = ['error' => get_class($e) . ': ' . $e->getMessage()];
            printf("   run %d failed: %s\n", $run, $e->getMessage());
            continue;
        }
        $debug = $proposal['debug'] ?? [];
        $result = [
            'elapsed' => round(microtime(true) - $t0, 1),
            'proposed' => array_intersect_key($proposal['alignment'] ?? [], array_flip(DIMENSIONS)),
            'timings' => $debug['timings'] ?? [],
            'usage' => EvaluationMetrics::usage($debug),
            'leaf_steps' => leafSteps($debug['curricular'] ?? []),
        ];
        $result += scoreRun($result, $truth, $scorer, $api);
        $row['runs'][] = $result;
        $cost = runCost($result['usage'], $prices)['total'];
        printf("   run %d in %ss — leaves %s strict, %s in cycle; course F1 %.2f; %s\n", $run, $result['elapsed'],
            $result['declared_leaves_hit'], $result['cycle']['leaves_hit'],
            $result['scores']['lrmi:educationalLevel']['f1'], null === $cost ? 'cost ?' : sprintf('$%.5f', $cost));
    }
    $report['items'][$id] = $row;
    $report = writeReport($out, $report, $scorer, $noLlm, $prices); // after every REA: a crash loses nothing
}

$report = writeReport($out, $report, $scorer, $noLlm, $prices);
printf("\n%d REA in the report. Report: %s\n", count($report['items']), $out);
if ($noLlm) {
    exit($report['items'] ? 0 : 1);
}

$print = static function (string $name, array $s): void {
    $d = $s['dimensions'];
    printf("\n== %s — %d runs, %d failed\n", $name, $s['runs'], $s['failed_runs']);
    printf("   leaves hit: %s strict, %s in cycle | course: %s exact, %s in cycle\n",
        $s['declared_leaves_hit'], $s['cycle_leaves_hit'], $s['course_exact'], $s['course_in_cycle']);
    foreach (DIMENSIONS as $dimension) {
        printf("   %-22s macro P %.2f R %.2f F1 %.2f | micro P %.2f R %.2f F1 %.2f | cycle micro F1 %.2f\n",
            $dimension, $d[$dimension]['macro']['precision'], $d[$dimension]['macro']['recall'],
            $d[$dimension]['macro']['f1'], $d[$dimension]['micro']['precision'], $d[$dimension]['micro']['recall'],
            $d[$dimension]['micro']['f1'], $d[$dimension]['cycle_micro']['f1']);
    }
    foreach ($s['reach'] as $reading => $r) {
        printf("   declared leaves (%s): chosen %d, shown not chosen %d, cut %d, never gathered %d\n", $reading,
            $r['chosen'] ?? 0, $r['shown_not_chosen'] ?? 0, $r['cut'] ?? 0, $r['not_gathered'] ?? 0);
    }
    printf("   latency: mean %.1fs, median %.1fs, p90 %.1fs\n", $s['latency_s']['mean'], $s['latency_s']['median'],
        $s['latency_s']['p90']);
    $c = $s['cost'];
    printf("   cost: total $%.4f, per run %s, per MB %s; tokens %d in / %d out; %d calls (%d without cost)\n",
        $c['total'], null === $c['per_run'] ? '?' : sprintf('$%.5f', $c['per_run']),
        null === $c['per_mb'] ? '?' : sprintf('$%.5f', $c['per_mb']), $c['tokens_in'], $c['tokens_out'],
        $c['calls'], $c['calls_uncovered']);
};
$print('ALL', $report['summary']);
foreach ($report['summary']['by_stage'] as $stage => $s) {
    $print($stage, $s);
}

exit($report['items'] ? 0 : 1);
