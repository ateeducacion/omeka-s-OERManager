<?php

/**
 * TASK-064 slice 6: the AI classifier's candidates and the declared-alignment
 * resolver on the real `cur:` curriculum.
 *
 * READ ONLY and FREE: no LLM, no provider call, no catalogue write. It checks
 *  - the real TermResolverInterface (CurriculumTermResolver): stages in
 *    pedagogical order; families = `cur:Materia` of a stage with id and courses;
 *    knowledge and criteria of «Matemáticas (ESO)» in 1º ESO with their
 *    `skos:definition`, block (BloqueSaberes / BloqueCompetencial), course,
 *    course subject, and the notation as the code shown to the model;
 *  - CurricularClassifier end to end on those candidates with a scripted LLM
 *    (an in-memory LlmClientInterface that picks the numbered line containing a
 *    target text; nothing leaves the container), so the phases, the derived
 *    course/subject and the prompt lines are the real ones;
 *  - DeclaredAlignmentResolver against real codes: a criterion, a knowledge
 *    item, a course and a course subject, plus a prefixed identifier and an
 *    unknown code that must stay unresolved.
 *
 * Usage (inside the container):
 *   php classifier-cur-check.php <userEmail>
 */

chdir('/var/www/html');
require 'bootstrap.php';
require __DIR__ . '/cur-fixture.php';

use Doctrine\DBAL\Logging\DebugStack;
use OERManager\Service\Ai\CurricularClassifier;
use OERManager\Service\Ai\DeclaredAlignmentResolver;
use OERManager\Service\Ai\PromptBuilder;
use OERManager\Service\Ai\ResponseParser;
use OERManager\Service\Ai\TermResolverInterface;
use OERManager\Service\Content\ItemContext;
use OERManager\Service\Curriculum\CurModel;
use OERManager\Service\Curriculum\CurriculumTerms;
use OERManager\Service\Llm\ChatResult;
use OERManager\Service\Llm\LlmClientInterface;

$application = \Omeka\Mvc\Application::init(require 'application/config/application.config.php');
$services = $application->getServiceManager();
$api = $services->get('Omeka\ApiManager');

$email = (string) ($argv[1] ?? '');
if ('' === $email) {
    fwrite(STDERR, "usage: php classifier-cur-check.php <userEmail>\n");
    exit(2);
}
$user = curAuthenticate($services, $email);
printf("authenticated as %s (%s); mode: read only, no LLM\n\n", $user->getEmail(), $user->getRole());

$failures = 0;
$checks = 0;
function check(string $label, bool $ok, string $detail = ''): void
{
    global $failures, $checks;
    $checks++;
    $failures += $ok ? 0 : 1;
    printf("  [%s] %s%s\n", $ok ? ' OK ' : 'FAIL', $label, '' === $detail ? '' : " — $detail");
}

$terms = $services->get(CurriculumTerms::class);
$resolver = $services->get(TermResolverInterface::class);
$schemeId = (int) $terms->schemeId();
$fx = curFixture($services);
$criterionId = curByNotation($api, $schemeId, (int) $terms->classId(CurModel::CRITERIO), 'SMAT01CE1.1');
$knowledgeId = curByNotation($api, $schemeId, (int) $terms->classId(CurModel::SABER), 'SMAT01SBI.1');

echo "== Candidates (CurriculumTermResolver)\n";
$stages = $resolver->listCandidates('etapa');
$stageNotations = [];
$esoId = 0;
foreach ($stages as $row) {
    $stageNotations[] = $row['notation'];
    if ('ESO' === $row['notation']) {
        $esoId = (int) $row['id'];
    }
}
check('stages in pedagogical order', ['INF', 'PRI', 'ESO', 'BAC'] === $stageNotations, implode(', ', $stageNotations));

$t0 = microtime(true);
$families = $resolver->listSubjectFamilies($esoId);
$familyMs = (microtime(true) - $t0) * 1000;
$math = null;
foreach ($families as $family) {
    if ('Matemáticas (ESO)' === $family['name']) {
        $math = $family;
    }
}
$suffixed = count(array_filter($families, static fn (array $f): bool => str_ends_with($f['name'], ' (ESO)')));
check('families are the cur:Materia of ESO', count($families) > 0 && $suffixed === count($families),
    sprintf('%d families, %d with the «(ESO)» suffix, %.0f ms', count($families), $suffixed, $familyMs));
$mathClass = $math ? CurModel::classTerm($api->read('items', $math['id'])->getContent()) : '';
check('«Matemáticas (ESO)» has its id and its courses', null !== $math && CurModel::MATERIA === $mathClass
    && in_array($fx['S01'], array_column($math['courses'] ?? [], 'id'), true),
    $math ? sprintf('id %d, %s, courses: %s', $math['id'], $mathClass,
        implode(', ', array_column($math['courses'], 'title'))) : 'missing');

$sql = new DebugStack();
$services->get('Omeka\EntityManager')->getConnection()->getConfiguration()->setSQLLogger($sql);
$t0 = microtime(true);
$teaches = $resolver->listLeaves('lrmi:teaches', $esoId, 'Matemáticas (ESO)', [$fx['S01']]);
$assesses = $resolver->listLeaves('lrmi:assesses', $esoId, 'Matemáticas (ESO)', [$fx['S01']]);
$leafMs = (microtime(true) - $t0) * 1000;
$services->get('Omeka\EntityManager')->getConnection()->getConfiguration()->setSQLLogger(null);

$full = static fn (array $rows): bool => [] === array_filter($rows, static fn (array $r): bool =>
    '' === $r['description'] || '' === $r['block'] || '' === $r['notation'] || $r['title'] !== $r['notation']
    || $fx['S01'] !== $r['courseId'] || $fx['SMAT01'] !== $r['subjectId']);
check('knowledge of Matemáticas · 1º ESO', 82 === count($teaches) && $full($teaches),
    sprintf('%d items, each with definition, block, notation as code, course and course subject', count($teaches)));
check('criteria of Matemáticas · 1º ESO', 18 === count($assesses) && $full($assesses), count($assesses) . ' items');
$knowledgeRow = array_values(array_filter($teaches, static fn (array $r): bool => $r['id'] === $knowledgeId))[0] ?? [];
$criterionRow = array_values(array_filter($assesses, static fn (array $r): bool => $r['id'] === $criterionId))[0] ?? [];
check('a knowledge block is its cur:BloqueSaberes', 'SMAT01SBI · I. Sentido numérico' === ($knowledgeRow['block'] ?? ''),
    (string) ($knowledgeRow['block'] ?? ''));
check('a criterion block is its cur:BloqueCompetencial',
    str_starts_with((string) ($criterionRow['block'] ?? ''), 'Matemáticas · 1º ESO · bloque competencial'),
    (string) ($criterionRow['block'] ?? ''));
$distinct = count(array_unique(array_merge(array_column($teaches, 'id'), array_column($assesses, 'id'))));
check('leaves are current and unique', 100 === $distinct, sprintf('%d ids; %.0f ms, %d SQL for both lists',
    $distinct, $leafMs, count($sql->queries)));

echo "\n== Classifier with a scripted LLM (no provider)\n";
$script = new class implements LlmClientInterface {
    /** @var list<string> text of the numbered line to pick, per call */
    public array $targets = [];
    public array $prompts = [];

    public function chat(array $messages, array $options = []): ChatResult
    {
        $user = (string) ($messages[0]['content'] ?? '');
        $this->prompts[] = $user;
        $target = array_shift($this->targets) ?? '';
        $picked = [];
        foreach (explode("\n", $user) as $line) {
            if (1 === preg_match('/^(\d+)\. (.*)$/u', $line, $m) && '' !== $target && str_contains($m[2], $target)) {
                $picked[] = (int) $m[1];
            }
        }
        $withWhy = str_contains((string) ($options['system'] ?? ''), '"why"');
        $selected = $withWhy ? array_map(static fn (int $i): array => ['i' => $i, 'why' => 'guion'], $picked) : $picked;
        return new ChatResult((string) json_encode(['selected' => $selected]));
    }

    public function supportsImages(): bool
    {
        return false;
    }

    public function supportsPdf(): bool
    {
        return false;
    }
};
// One target per call: stage, subject, course, knowledge block (82 > 30 knowledge
// items, so the block step runs), knowledge, criterion.
$script->targets = ['Educación Secundaria Obligatoria', 'Matemáticas (ESO) (', '1º ESO',
    'SMAT01SBI · I. Sentido numérico', '(SMAT01SBI.1)', '(SMAT01CE1.1)'];
$classifier = new CurricularClassifier($script, $resolver, new PromptBuilder(), new ResponseParser());
$result = $classifier->classify(new ItemContext('Recurso de conteo para 1º ESO.', ''));
check('the scripted run proposes the target leaves', [$knowledgeId] === ($result['lrmi:teaches'] ?? [])
    && [$criterionId] === ($result['lrmi:assesses'] ?? []), json_encode($result));
check('course and course subject derive from the leaves', [$fx['S01']] === ($result['lrmi:educationalLevel'] ?? [])
    && [$fx['SMAT01']] === ($result['schema:about'] ?? []));
$steps = array_column(array_filter($classifier->getTrace(), static fn ($t): bool => isset($t['candidates'])), 'step');
check('phases unchanged: stage, subject, course, blocks, knowledge, criteria',
    ['Etapa educativa', 'Materia (asignatura)', 'Curso', 'Bloques temáticos de Matemáticas (ESO)', 'Saberes básicos',
        'Criterios de evaluación'] === $steps, implode(' → ', $steps));
$knowledgePrompt = $script->prompts[4] ?? '';
check('a leaf line is «[course · block] definition (notation)»',
    1 === preg_match('/^\d+\. \[1º ESO · SMAT01SBI · I\. Sentido numérico\] .+ \(SMAT01SBI\.1\)$/mu', $knowledgePrompt)
    && !str_contains($knowledgePrompt, '(SMAT01SBI.1 · '));

echo "\n== Declared alignment (DeclaredAlignmentResolver)\n";
$declared = new DeclaredAlignmentResolver($api, $terms);
$sql = new DebugStack();
$services->get('Omeka\EntityManager')->getConnection()->getConfiguration()->setSQLLogger($sql);
$t0 = microtime(true);
$truth = $declared->resolve([
    'courses' => ['1º ESO'],
    'subjects' => [['course' => '1º ESO', 'name' => 'Matemáticas']],
    'knowledge' => ['SMAT01SBI.1', 'NOEXISTE01SB1'],
    'criteria' => ['SMAT01CE1.1', 'crit:SMAT01CE1.1'],
]);
$ms = (microtime(true) - $t0) * 1000;
$services->get('Omeka\EntityManager')->getConnection()->getConfiguration()->setSQLLogger(null);
check('course by skos:prefLabel', [$fx['S01']] === $truth['lrmi:educationalLevel']);
check('course subject «Matemáticas · 1º ESO» in that course', [$fx['SMAT01']] === $truth['schema:about']);
check('knowledge and criterion by skos:notation', [$knowledgeId] === $truth['lrmi:teaches']
    && [$criterionId] === $truth['lrmi:assesses']);
check('unknown code and prefixed identifier stay unresolved', [
    ['kind' => 'knowledge', 'value' => 'NOEXISTE01SB1', 'reason' => 'missing'],
    ['kind' => 'criterion', 'value' => 'crit:SMAT01CE1.1', 'reason' => 'missing'],
] === $truth['unresolved'], json_encode($truth['unresolved'], JSON_UNESCAPED_UNICODE));
printf("     cost: %.0f ms, %d SQL for 6 lookups\n", $ms, count($sql->queries));

printf("\n%d checks, %d failures. Nothing was written.\n", $checks, $failures);
exit($failures ? 1 : 0);
