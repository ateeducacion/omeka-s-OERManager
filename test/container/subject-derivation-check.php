<?php
// CLI only: these harnesses bootstrap Omeka and this one writes to the catalogue.
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

/**
 * TASK-076: `lrmi:educationalAlignment` follows `schema:about` on every real
 * save path, and the resync job is idempotent.
 *
 * ⚠️ WRITES TO THE CATALOGUE: disposable REA (deleted at the end), and the
 * job's first run back-fills every REA. Development or disposable catalogue
 * only (AGENTS.md). Requires the literal flag --write.
 *
 * Use, inside the container:
 *   php /var/www/html/volume/modules/OERManager/test/container/subject-derivation-check.php <userEmail> --write
 *
 * Exit 1 if a check fails.
 */

require '/var/www/html/bootstrap.php';

use OERManager\Job\ResyncDerivedSubjects;
use OERManager\Service\Curriculum\CurModel;
use OERManager\Service\Curriculum\DerivedSubjectWriter;
use OERManager\Service\RecatalogService;

$application = Omeka\Mvc\Application::init(require '/var/www/html/application/config/application.config.php');
$services = $application->getServiceManager();
require __DIR__ . '/cur-fixture.php';

$email = (string) ($argv[1] ?? '');
if ('' === $email || !in_array('--write', $argv, true)) {
    fwrite(STDERR, "usage: subject-derivation-check.php <userEmail> --write\n");
    exit(2);
}
curAuthenticate($services, $email);

$api = $services->get('Omeka\ApiManager');
$recatalog = $services->get(RecatalogService::class);
$writer = $services->get(DerivedSubjectWriter::class);
$fixture = curFixture($services);
$marker = curMarker('subject-derivation-check');
$created = [];
$failed = 0;
$check = static function (bool $ok, string $label) use (&$failed): void {
    echo '  ' . ($ok ? 'OK  ' : 'FAIL') . ' ' . $label . "\n";
    $failed += $ok ? 0 : 1;
};
/** @return list<int> target ids of the REA's derived values, as stored (fresh read) */
$derived = static function (int $id) use ($services, $api, $email): array {
    curClear($services, $email);
    $ids = [];
    foreach ($api->read('items', $id)->getContent()->value(DerivedSubjectWriter::TERM, ['all' => true]) as $value) {
        $ids[] = $value->valueResource() ? (int) $value->valueResource()->id() : 0;
    }
    return $ids;
};
/** The cur:materia id of a MateriaCurso item, or 0. */
$subjectOf = static function (int $materiaCurso) use ($api): int {
    $value = $api->read('items', $materiaCurso)->getContent()->value(CurModel::EDGE_MATERIA);
    return $value && $value->valueResource() ? (int) $value->valueResource()->id() : 0;
};
$titleOf = static fn (int $id): string => (string) $api->read('items', $id)->getContent()->displayTitle();
$classId = static fn (string $term): int
    => (int) $api->search('resource_classes', ['term' => $term])->getContent()[0]->id();
/** A current MateriaCurso whose subject is not $subject, or 0. */
$otherMateriaCurso = static function (int $subject) use ($api, $fixture, $subjectOf, $classId): int {
    $query = CurModel::termsQuery($fixture['scheme'], $classId(CurModel::MATERIA_CURSO), '', 1);
    foreach ($api->search('items', ['per_page' => 50] + $query)->getContent() as $item) {
        if (!in_array($subjectOf((int) $item->id()), [0, $subject], true)) {
            return (int) $item->id();
        }
    }
    return 0;
};

try {
    $maths = $subjectOf($fixture['SMAT01']);
    $check($maths > 0 && $maths === $subjectOf($fixture['SMAT02']), 'SMAT01 and SMAT02 share one cur:Materia');
    $other = $otherMateriaCurso($maths);
    $otherSubject = $other > 0 ? $subjectOf($other) : 0;
    $check($otherSubject > 0, 'a MateriaCurso of another subject exists');

    // 1. Re-cataloguer: two years of Mathematics give one derived value.
    $rea = curCreateRea($services, $marker, ['schema:about' => [$fixture['SMAT01'], $fixture['SMAT02']]], $email, $created);
    $check([$maths] === $derived($rea), 're-cataloguer: two years of one subject give one value');

    // 2. Undo of that re-cataloguing: schema:about empties and the value goes.
    $recatalog->undo($rea, $email);
    $check([] === $derived($rea), 'undo: no schema:about, no derived value');

    // 3. REST API create with schema:about and a hand-written educationalAlignment.
    $data = ['o:resource_class' => ['o:id' => $classId('lrmi:LearningResource')], 'o:is_public' => false]
        + curLiteral($api, 'dcterms:title', $marker)
        + curLink($api, 'schema:about', $other)
        + curLink($api, DerivedSubjectWriter::TERM, $maths);
    $viaApi = (int) $api->create('items', $data)->getContent()->id();
    $created[] = $viaApi;
    $check([$otherSubject] === $derived($viaApi), 'API create: a hand-written value is replaced by the derived one');

    // 4. Batch append of a Mathematics year: both subjects, in title order.
    $api->batchUpdate('items', [$viaApi], curLink($api, 'schema:about', $fixture['SMAT01']), [
        'continueOnError' => false,
        'collectionAction' => 'append',
    ]);
    $expected = [$maths, $otherSubject];
    usort($expected, static fn (int $a, int $b): int => [$titleOf($a), $a] <=> [$titleOf($b), $b]);
    $check($expected === $derived($viaApi), 'batch append: both subjects, in title order');

    // 5. Batch clear of schema:about: no derived value.
    $aboutId = (int) $api->search('properties', ['term' => 'schema:about'])->getContent()[0]->id();
    $api->batchUpdate('items', [$viaApi], ['clear_property_values' => [$aboutId]], ['continueOnError' => false]);
    $check([] === $derived($viaApi), 'batch clear_property_values: no derived value');

    // 6. A partial update that appends schema:about keeps the rest.
    $api->update('items', $viaApi, curLink($api, 'schema:about', $fixture['SMAT02']), [], [
        'isPartial' => true,
        'collectionAction' => 'append',
    ]);
    $check([$maths] === $derived($viaApi), 'partial update: the derived value follows');
    $check($marker === $titleOf($viaApi), 'partial update keeps the title');

    // 7. A REA that fails its template (the REA template requires a field it
    //    lacks) with a stale derived value: the API refuses even an empty
    //    partial update, the job's direct write repairs it (final review).
    $templateId = (int) $services->get('Omeka\Settings')->get('oermanager_rea_template_id');
    $stale = curCreateRea($services, $marker, ['schema:about' => [$fixture['SMAT01']]], $email, $created);
    curClear($services, $email);
    $entityManager = $services->get('Omeka\EntityManager');
    $item = $entityManager->find(\Omeka\Entity\Item::class, $stale);
    $item->setResourceTemplate($entityManager->find(\Omeka\Entity\ResourceTemplate::class, $templateId));
    foreach (iterator_to_array($item->getValues()) as $value) {
        $property = $value->getProperty();
        if (DerivedSubjectWriter::TERM === $property->getVocabulary()->getPrefix() . ':' . $property->getLocalName()) {
            $item->getValues()->removeElement($value);
        }
    }
    $entityManager->flush();
    $check($templateId > 0 && [] === $derived($stale), 'stale REA with the REA template and no derived value');
    $refused = false;
    try {
        $api->update('items', $stale, [], [], ['isPartial' => true]);
    } catch (\Omeka\Api\Exception\ValidationException $e) {
        $refused = true;
    }
    $check($refused, 'the API refuses an update of a REA lacking a required field');
    curClear($services, $email);
    $wrote = ResyncDerivedSubjects::write($services->get('Omeka\EntityManager'), $writer, $stale);
    $check($wrote && [$maths] === $derived($stale), 'the job write repairs it anyway');

    // 8. Job: run twice; the second run changes nothing; o:modified of an up-to-date REA does not move.
    curClear($services, $email);
    $before = $api->read('items', $viaApi)->getContent()->modified();
    $runJob = static function () use ($services, $api, $writer, $classId): array {
        $reaClass = $classId('lrmi:LearningResource');
        return ResyncDerivedSubjects::run(
            static fn (): array => array_map('intval', array_values($api->search('items', [
                'resource_class_id' => $reaClass,
                'return_scalar' => 'id',
            ])->getContent())),
            static fn (int $id): ?object => $services->get('Omeka\EntityManager')->find(\Omeka\Entity\Item::class, $id),
            $writer,
            static fn (int $id): bool => ResyncDerivedSubjects::write($services->get('Omeka\EntityManager'), $writer, $id),
            static fn (): bool => false,
            static function (int $id, \Throwable $e): void {
                printf("  job error on #%d: %s\n", $id, $e->getMessage());
            }
        );
    };
    $first = $runJob();
    printf("  job, first run: %d examined, %d changed, %d failed\n", $first['examined'], $first['changed'], $first['failed']);
    $check(0 === $first['failed'], 'job, first run: no failure');
    curClear($services, $email);
    $second = $runJob();
    printf("  job, second run: %d examined, %d changed, %d failed\n", $second['examined'], $second['changed'], $second['failed']);
    $check(0 === $second['changed'] && 0 === $second['failed'], 'job, second run: 0 changed');
    curClear($services, $email);
    $check($before == $api->read('items', $viaApi)->getContent()->modified(), 'job leaves o:modified of an up-to-date REA alone');
} finally {
    $left = curDeleteAll($services, $created, $marker);
    $check(0 === $left, 'disposable REA deleted');
}

echo $failed ? "FAILED: $failed\n" : "ALL OK\n";
exit($failed ? 1 : 0);
