<?php

declare(strict_types=1);

namespace OERManager\Job;

use OERManager\Service\Curriculum\DerivedSubjectWriter;
use OERManager\Service\Curriculum\SubjectDerivation;
use OERManager\Service\MasterViewQuery;
use Omeka\Job\AbstractJob;

/**
 * TASK-076: recomputes the derived stage subject of every REA and updates only
 * those that differ, so a second run changes nothing and `o:modified` of the
 * rest does not move. The update is an empty partial update: the
 * `api.hydrate.post` listener does the writing. Run after deploying TASK-076
 * and after each curriculum release (a MateriaCurso may change its
 * cur:materia). Launched by a global administrator from the configuration page.
 *
 * Does not clear the EntityManager, for the reason GovernanceBatchJob gives:
 * clearing detaches the job's owner; about 12 KB per item, some 35 MB for 3 000 REA.
 */
class ResyncDerivedSubjects extends AbstractJob
{
    public function perform(): void
    {
        $services = $this->getServiceLocator();
        $api = $services->get('Omeka\ApiManager');
        $entityManager = $services->get('Omeka\EntityManager');
        $logger = $services->get('Omeka\Logger');
        $classId = $services->get(MasterViewQuery::class)->buildSearchParams([])['resource_class_id'] ?? null;
        if (null === $classId) {
            $logger->warn('OERManager resync derived subjects: no lrmi:LearningResource class, nothing to do.');
            return;
        }
        $result = self::run(
            static fn (): array => array_map('intval', array_values($api->search('items', [
                'resource_class_id' => $classId,
                'return_scalar' => 'id',
            ])->getContent())),
            static fn (int $id): ?object => $entityManager->find(\Omeka\Entity\Item::class, $id),
            $services->get(DerivedSubjectWriter::class),
            static function (int $id) use ($api): void {
                $api->update('items', $id, [], [], ['isPartial' => true]);
            },
            fn (): bool => $this->shouldStop(),
            static function (int $id, \Throwable $e) use ($logger): void {
                $logger->err(sprintf('OERManager resync derived subjects: item %d: %s', $id, $e->getMessage()));
            }
        );
        $logger->info(sprintf(
            'OERManager resync derived subjects: %d REA examined, %d changed, %d failed.',
            $result['examined'],
            $result['changed'],
            $result['failed']
        ));
    }

    /**
     * @param callable():list<int> $ids
     * @param callable(int):?object $entity
     * @param callable(int):void $update
     * @param callable():bool $shouldStop
     * @param (callable(int, \Throwable):void)|null $onError
     * @return array{examined:int, changed:int, failed:int}
     */
    public static function run(
        callable $ids,
        callable $entity,
        DerivedSubjectWriter $writer,
        callable $update,
        callable $shouldStop,
        ?callable $onError = null
    ): array {
        $result = ['examined' => 0, 'changed' => 0, 'failed' => 0];
        foreach ($ids() as $id) {
            if ($shouldStop()) {
                break;
            }
            $item = $entity($id);
            if (null === $item) {
                continue;
            }
            $result['examined']++;
            if (SubjectDerivation::matches($writer->storedIds($item), $writer->derivedIds($item))) {
                continue;
            }
            try {
                $update($id);
                $result['changed']++;
            } catch (\Throwable $e) {
                $result['failed']++;
                if (null !== $onError) {
                    $onError($id, $e);
                }
            }
        }
        return $result;
    }
}
