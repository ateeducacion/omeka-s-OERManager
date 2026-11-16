<?php

namespace OERManager\Service\Curriculum;

use Omeka\Api\Manager as ApiManager;
use Omeka\Settings\Settings;

/**
 * CurriculumTerms over the Omeka API, cached per request (the service is
 * shared, so one instance lives as long as the request or the job).
 */
class ApiCurriculumTerms implements CurriculumTerms
{
    private bool $schemeResolved = false;
    private ?int $schemeId = null;
    private bool $retiredResolved = false;
    private ?int $retiredSetId = null;

    /** @var array<int,string> item set id → scheme URI ('' if not a scheme) */
    private array $schemeUris = [];

    /** @var array<string,?int> */
    private array $classIds = [];

    public function __construct(private ApiManager $api, private Settings $settings)
    {
    }

    public function schemeId(): ?int
    {
        if (!$this->schemeResolved) {
            $this->schemeResolved = true;
            $configured = (int) $this->settings->get(self::SCHEME_SETTING);
            $this->schemeId = $this->isScheme($configured) ? $configured : $this->detectSchemeId();
        }
        return $this->schemeId;
    }

    public function isScheme(int $itemSetId): bool
    {
        return '' !== $this->schemeUriOf($itemSetId);
    }

    public function detectSchemeId(): ?int
    {
        $found = [];
        foreach ($this->api->search('item_sets', CurModel::schemeQuery())->getContent() as $itemSet) {
            $uri = CurModel::schemeUri($itemSet);
            if ('' !== $uri) {
                $id = (int) $itemSet->id();
                $this->schemeUris[$id] = $uri;
                $found[$id] = true;
            }
        }
        // Two curricula would make every query ambiguous: the admin chooses.
        return 1 === count($found) ? (int) array_key_first($found) : null;
    }

    public function retiredSetId(): ?int
    {
        if (!$this->retiredResolved) {
            $this->retiredResolved = true;
            $schemeId = $this->schemeId();
            $uri = null === $schemeId ? '' : $this->schemeUriOf($schemeId);
            if ('' !== $uri) {
                $sets = $this->api->search('item_sets', CurModel::retiredSetQuery($uri))->getContent();
                $this->retiredSetId = $sets ? (int) reset($sets)->id() : null;
            }
        }
        return $this->retiredSetId;
    }

    public function classId(string $term): ?int
    {
        if (!array_key_exists($term, $this->classIds)) {
            $classes = $this->api->search('resource_classes', CurModel::classQuery($term))->getContent();
            $this->classIds[$term] = $classes ? (int) reset($classes)->id() : null;
        }
        return $this->classIds[$term];
    }

    private function schemeUriOf(int $itemSetId): string
    {
        if ($itemSetId <= 0) {
            return '';
        }
        if (!array_key_exists($itemSetId, $this->schemeUris)) {
            try {
                $itemSet = $this->api->read('item_sets', $itemSetId)->getContent();
                $this->schemeUris[$itemSetId] = CurModel::schemeUri($itemSet);
            } catch (\Exception $e) {
                $this->schemeUris[$itemSetId] = '';
            }
        }
        return $this->schemeUris[$itemSetId];
    }
}
