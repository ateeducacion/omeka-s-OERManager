<?php

declare(strict_types=1);

namespace OERManager\Service;

use Omeka\Api\Manager as ApiManager;

/**
 * Resolves `IdConstraints` to item ids (TASK-070). Each branch is an id-only
 * search (`return_scalar => 'id'`: nothing is hydrated) carrying ONLY the REA
 * class and the branch: the master view's own filters go in the final search,
 * never here, because the core joins property rows without parentheses.
 *
 * Not final so the controller tests can stand it in.
 */
class IdConstraintResolver
{
    public function __construct(
        private readonly ApiManager $api,
        private readonly MasterViewQuery $masterViewQuery,
        private readonly ReaTemplateFields $templateFields
    ) {
    }

    /**
     * @param array<string,mixed> $query the master view's GET parameters
     * @return list<int>|null null when the request needs no constraint
     */
    public function resolve(array $query): ?array
    {
        // The template is read only when asked for: every master view load
        // passes through here, most of them without `missing[]=required`.
        $requiredTerms = in_array(IdConstraints::MISSING_REQUIRED, (array) ($query['missing'] ?? []), true)
            ? $this->templateFields->requiredTerms()
            : [];
        $groups = IdConstraints::groups($query, $requiredTerms);
        if ([] === $groups) {
            return null;
        }
        $classId = $this->masterViewQuery->buildSearchParams([])['resource_class_id'] ?? null;
        if (null === $classId) {
            return [];
        }
        $idsPerGroup = [];
        foreach ($groups as $branches) {
            $idsPerGroup[] = array_map(
                fn (array $branch): array => $this->ids((int) $classId, $branch),
                $branches
            );
        }
        return IdConstraints::combine($idsPerGroup);
    }

    public function hasRequiredFields(): bool
    {
        return [] !== $this->templateFields->requiredTerms();
    }

    /**
     * @param array<string,mixed> $branch
     * @return list<int>
     */
    private function ids(int $classId, array $branch): array
    {
        $content = $this->api->search(
            'items',
            ['resource_class_id' => $classId, 'return_scalar' => 'id'] + $branch
        )->getContent();
        return array_map('intval', array_values((array) $content));
    }
}
