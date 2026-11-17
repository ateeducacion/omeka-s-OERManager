<?php

/**
 * Shared helpers of the TASK-064 container harnesses: resolve the real `cur:`
 * curriculum (read only) and create, then delete, disposable test items.
 *
 * Writes nothing by itself. A harness that calls curCreateRea() or
 * curCreateRetiredTerm() must call curDeleteAll() in a `finally` block. The
 * disposable items are private and titled with a marker
 * «[OERManager TASK-064 · prueba desechable] …»; the curriculum items, the
 * existing REA and the ADR-0009 graph are never written. Pattern of
 * cur-cascade-check.php.
 */

use OERManager\Service\Curriculum\CurModel;
use OERManager\Service\Curriculum\CurriculumTerms;

/** Authenticates the harness as the user with $email, or exits 2. */
function curAuthenticate($services, string $email): \Omeka\Entity\User
{
    $user = '' === $email ? null : $services->get('Omeka\EntityManager')
        ->getRepository(\Omeka\Entity\User::class)->findOneBy(['email' => $email]);
    if (null === $user) {
        fwrite(STDERR, "user not found: '$email'\n");
        exit(2);
    }
    $services->get('Omeka\AuthenticationService')->getStorage()->write($user);
    // The MVC sets this on dispatch; controllers run here read user settings.
    $services->get('Omeka\Settings\User')->setTargetId($user->getId());
    return $user;
}

/**
 * Empties the unit of work (cold measurements, fresh reads) and authenticates
 * again: clear() detaches the identity, and the next create would fail on
 * `Resource#owner`.
 */
function curClear($services, string $email): void
{
    $services->get('Omeka\EntityManager')->clear();
    curAuthenticate($services, $email);
}

/** First current term of a class with a given notation, or 0. */
function curByNotation($api, int $schemeId, int $classId, string $notation): int
{
    $query = CurModel::termsQuery($schemeId, $classId, '', 1);
    $query['property'][] = ['property' => CurModel::NOTATION, 'type' => 'eq', 'text' => $notation];
    $items = $api->search('items', $query)->getContent();
    return $items ? (int) reset($items)->id() : 0;
}

/**
 * The real curriculum nodes the harnesses use: 1º and 2º ESO, their
 * Matemáticas, one knowledge item and one criterion of SMAT01. Read only.
 *
 * @return array<string,int>
 */
function curFixture($services): array
{
    $api = $services->get('Omeka\ApiManager');
    $terms = $services->get(CurriculumTerms::class);
    $scheme = (int) $terms->schemeId();
    $class = static fn (string $term): int => (int) $terms->classId($term);
    $smat01 = curByNotation($api, $scheme, $class(CurModel::MATERIA_CURSO), 'SMAT01');
    $first = static function (string $classTerm) use ($api, $scheme, $class, $smat01): int {
        $query = CurModel::withResource(
            CurModel::termsQuery($scheme, $class($classTerm), '', 1),
            CurModel::EDGE_MATERIA_CURSO,
            $smat01
        );
        $items = $api->search('items', $query)->getContent();
        return $items ? (int) reset($items)->id() : 0;
    };
    return [
        'scheme' => $scheme,
        'retiredSet' => (int) $terms->retiredSetId(),
        'S01' => curByNotation($api, $scheme, $class(CurModel::CURSO), 'S01'),
        'S02' => curByNotation($api, $scheme, $class(CurModel::CURSO), 'S02'),
        'SMAT01' => $smat01,
        'SMAT02' => curByNotation($api, $scheme, $class(CurModel::MATERIA_CURSO), 'SMAT02'),
        'saber' => $first(CurModel::SABER),
        'criterio' => $first(CurModel::CRITERIO),
        'saberClass' => $class(CurModel::SABER),
    ];
}

function curMarker(string $script): string
{
    return '[OERManager TASK-064 · prueba desechable] ' . $script . ' ' . date('c');
}

/** @return array<string,list<array<string,mixed>>> one literal value */
function curLiteral($api, string $term, string $text): array
{
    $id = (int) $api->search('properties', ['term' => $term])->getContent()[0]->id();
    return [$term => [['type' => 'literal', 'property_id' => $id, '@value' => $text]]];
}

/** @return array<string,list<array<string,mixed>>> one resource value */
function curLink($api, string $term, int $target): array
{
    $id = (int) $api->search('properties', ['term' => $term])->getContent()[0]->id();
    return [$term => [['type' => 'resource:item', 'property_id' => $id, 'value_resource_id' => $target]]];
}

/**
 * A private lrmi:LearningResource titled $marker, aligned through the
 * module's own RecatalogService (validated write, curation event).
 *
 * @param array<string,list<int>> $alignment dimension → target ids
 * @param list<int> $created receives the new id
 */
function curCreateRea($services, string $marker, array $alignment, string $contributor, array &$created): int
{
    $api = $services->get('Omeka\ApiManager');
    $class = $api->search('resource_classes', ['term' => 'lrmi:LearningResource'])->getContent()[0];
    $data = ['o:is_public' => false, 'o:resource_class' => ['o:id' => (int) $class->id()]]
        + curLiteral($api, 'dcterms:title', $marker)
        + curLiteral($api, 'dcterms:description', 'Disposable test REA of a TASK-064 container harness; '
            . 'deleted when it ends.');
    $id = (int) $api->create('items', $data)->getContent()->id();
    $created[] = $id;
    if ([] !== $alignment) {
        $services->get(\OERManager\Service\RecatalogService::class)->apply($id, $alignment, $contributor);
    }
    return $id;
}

/**
 * A private disposable term in the retired set, marked as the contract marks a
 * retired node (`skos:changeNote`) and pointing at its successor
 * (`dcterms:isReplacedBy`). No curriculum item is touched: only the new item
 * joins the retired set, and deleting it leaves the set as it was.
 *
 * @param list<int> $created receives the new id
 */
function curCreateRetiredTerm(
    $services,
    string $marker,
    int $retiredSetId,
    int $classId,
    int $successorId,
    array &$created
): int {
    $api = $services->get('Omeka\ApiManager');
    $data = [
        'o:is_public' => false,
        'o:resource_class' => ['o:id' => $classId],
        'o:item_set' => [['o:id' => $retiredSetId]],
    ]
        + curLiteral($api, 'dcterms:title', $marker)
        + curLiteral($api, CurModel::PREF_LABEL, 'TEST.1 · Término retirado de prueba')
        + curLiteral($api, CurModel::CHANGE_NOTE, 'Retirado (prueba desechable de OERManager)')
        + curLink($api, CurModel::IS_REPLACED_BY, $successorId);
    $id = (int) $api->create('items', $data)->getContent()->id();
    $created[] = $id;
    return $id;
}

/**
 * Deletes the disposable items in one batch (one by one in the same unit of
 * work fails with the Access module, 2026-11-16) and returns how many items
 * with $marker are left.
 *
 * @param list<int> $ids
 */
function curDeleteAll($services, array $ids, string $marker): int
{
    $api = $services->get('Omeka\ApiManager');
    if ([] !== $ids) {
        try {
            $api->batchDelete('items', $ids);
        } catch (\Exception $e) {
            printf("  batch delete failed (%s); deleting one by one\n", $e->getMessage());
            foreach ($ids as $id) {
                try {
                    $services->get('Omeka\EntityManager')->clear();
                    $api->delete('items', $id);
                } catch (\Exception $e) {
                    printf("  could not delete #%d: %s\n", $id, $e->getMessage());
                }
            }
        }
    }
    return $api->search('items', [
        'property' => [['property' => 'dcterms:title', 'type' => 'eq', 'text' => $marker]],
        'page' => 1,
        'per_page' => 1,
    ])->getTotalResults();
}
