<?php

declare(strict_types=1);

namespace OERManager\Service\Governance;

use OERManager\Service\ComputedFilter;
use OERManager\Service\ComputedPredicates;
use OERManager\Service\IntegrityChecker;
use OERManager\Service\MasterViewQuery;
use Omeka\Api\Manager as ApiManager;

/**
 * Which REA a batch acts on (TASK-028 slice 4), resolved server-side and
 * sized for the ~3000-REA production catalogue: ids come back as scalars, and
 * existing values are counted with one exists-query per field instead of
 * reading every item. Ids posted by the client are never trusted: they are
 * re-searched inside the REA class.
 */
class BatchSelection
{
    public const BATCH_MAX = 5000;

    public function __construct(
        private ApiManager $api,
        private MasterViewQuery $masterViewQuery,
        private IntegrityChecker $integrityChecker
    ) {
    }

    /**
     * @param array<mixed> $ids ticked rows, as posted
     * @return list<int>
     */
    public function resolveIds(array $ids): array
    {
        $clean = array_values(array_unique(array_filter(
            array_map(static fn ($id): int => is_numeric($id) ? (int) $id : 0, $ids),
            static fn (int $id): bool => $id > 0
        )));
        if ([] === $clean) {
            throw new BatchSelectionException('empty');
        }
        sort($clean);
        if (count($clean) > self::BATCH_MAX) {
            throw new BatchSelectionException('too_many');
        }
        $params = $this->baseParams([]);
        $params['id'] = $clean;
        return $this->ids($params);
    }

    /**
     * @param array<string,mixed> $query the master view's GET parameters
     * @return list<int>
     */
    public function resolveMatching(array $query): array
    {
        unset($query['page'], $query['per_page'], $query['sort_by'], $query['sort_order']);
        $params = $this->baseParams($query);

        $keys = ComputedPredicates::activeKeys($query);
        if ([] === $keys) {
            return $this->ids($params);
        }

        $params['page'] = 1;
        $params['per_page'] = ComputedFilter::HARD_CAP;
        $response = $this->api->search('items', $params);
        if ($response->getTotalResults() > ComputedFilter::HARD_CAP) {
            throw new BatchSelectionException('computed_truncated');
        }
        $candidates = [];
        foreach ($response->getContent() as $item) {
            $candidates[(int) $item->id()] = $item;
        }
        $predicate = ComputedPredicates::predicate($keys, $candidates, $query, $this->integrityChecker);
        $ids = array_values(array_filter(array_keys($candidates), $predicate));
        sort($ids);
        return $this->guard($ids);
    }

    /** @param list<int> $ids */
    public function countWithValue(array $ids, string $term): int
    {
        if ([] === $ids) {
            return 0;
        }
        return (int) $this->api->search('items', [
            'id' => $ids,
            'property' => [['joiner' => 'and', 'property' => $term, 'type' => 'ex']],
            'page' => 1,
            'per_page' => 1,
        ])->getTotalResults();
    }

    /** @return array<string,mixed> */
    private function baseParams(array $query): array
    {
        $params = $this->masterViewQuery->buildSearchParams($query);
        // Without the class the search would match the whole Omeka catalogue,
        // not the REA: the same trap CatalogSnapshot guards against.
        if (empty($params['resource_class_id'])) {
            throw new BatchSelectionException('class_unresolved');
        }
        unset(
            $params['page'],
            $params['per_page'],
            $params['sort_by'],
            $params['sort_order']
        );
        return $params;
    }

    /** @return list<int> */
    private function ids(array $params): array
    {
        $response = $this->api->search('items', $params, ['returnScalar' => 'id']);
        $ids = array_map('intval', (array) $response->getContent());
        sort($ids);
        return $this->guard(array_values(array_unique($ids)));
    }

    /**
     * @param list<int> $ids
     * @return list<int>
     */
    private function guard(array $ids): array
    {
        if ([] === $ids) {
            throw new BatchSelectionException('empty');
        }
        if (count($ids) > self::BATCH_MAX) {
            throw new BatchSelectionException('too_many');
        }
        return $ids;
    }
}
