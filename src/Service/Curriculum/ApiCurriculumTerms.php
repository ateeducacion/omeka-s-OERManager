<?php

namespace OERManager\Service\Curriculum;

use Omeka\Api\Manager as ApiManager;
use Omeka\Api\Representation\AbstractResourceEntityRepresentation;
use Omeka\Settings\Settings;

/**
 * CurriculumTerms over the Omeka API, cached per request (the service is
 * shared, so one instance lives as long as the request or the job).
 */
class ApiCurriculumTerms implements CurriculumTerms
{
    /** Tree page size: one page holds a whole course subject in 1.1.2 (≤119). */
    public const TREE_PAGE_SIZE = 200;

    /** Pages per class and course subject before giving up (NFR-004 bound). */
    public const TREE_MAX_PAGES = 5;

    private bool $schemeResolved = false;
    private ?int $schemeId = null;
    private bool $retiredResolved = false;
    private ?int $retiredSetId = null;

    /** @var list<int>|null */
    private ?array $retiredIds = null;

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

    public function retiredIds(): array
    {
        if (null === $this->retiredIds) {
            $setId = $this->retiredSetId();
            $this->retiredIds = null === $setId ? [] : array_values(array_map('intval', (array) $this->api->search(
                'items',
                CurModel::retiredMembersQuery($setId),
                ['returnScalar' => 'id']
            )->getContent()));
        }
        return $this->retiredIds;
    }

    public function classId(string $term): ?int
    {
        if (!array_key_exists($term, $this->classIds)) {
            $classes = $this->api->search('resource_classes', CurModel::classQuery($term))->getContent();
            $this->classIds[$term] = $classes ? (int) reset($classes)->id() : null;
        }
        return $this->classIds[$term];
    }

    public function courseSubjectTree(int $courseSubjectId): ?array
    {
        $subject = $this->courseSubject($courseSubjectId);
        $schemeId = $this->schemeId();
        if (null === $subject || null === $schemeId) {
            return null;
        }
        $tree = ['subject' => ['id' => $courseSubjectId, 'title' => CurModel::label($subject)]];
        foreach (CurModel::treeDimensions() as $dimension) {
            $shape = CurModel::treeShape($dimension);
            $rows = [];
            // Blocks first: the leaves' parents are then already loaded, so
            // reading a parent id does not fetch it again.
            foreach ([$shape['block'] => false, $shape['leaf'] => true] as $class => $selectable) {
                foreach ($this->courseSubjectTerms($schemeId, $class, $courseSubjectId) as $item) {
                    $rows[] = [
                        'id' => (int) $item->id(),
                        'parentId' => $selectable ? CurModel::refId($item, $shape['parent']) : 0,
                        'notation' => CurModel::notation($item),
                        'title' => CurModel::label($item),
                        'definition' => CurModel::definition($item),
                        'selectable' => $selectable,
                    ];
                }
            }
            $tree[$dimension] = LeafTree::build($rows);
        }
        return $tree;
    }

    public function retiredTerms(array $ids): array
    {
        $ids = CurModel::ids($ids);
        if (!$ids) {
            return [];
        }
        $retired = array_flip($this->retiredIds());
        $terms = [];
        foreach ($ids as $id) {
            if (!isset($retired[$id])) {
                continue;
            }
            try {
                $item = $this->api->read('items', $id)->getContent();
            } catch (\Exception $e) {
                continue;
            }
            $terms[] = [
                'id' => $id,
                'title' => CurModel::label($item),
                'replacedBy' => array_values(array_filter(
                    array_column(CurModel::refs($item, CurModel::IS_REPLACED_BY), 'title'),
                    static fn (string $title): bool => '' !== trim($title)
                )),
            ];
        }
        return $terms;
    }

    /** The item if it is a `cur:MateriaCurso` of the curriculum item set. */
    private function courseSubject(int $id): ?AbstractResourceEntityRepresentation
    {
        $schemeId = $this->schemeId();
        if ($id <= 0 || null === $schemeId) {
            return null;
        }
        try {
            $item = $this->api->read('items', $id)->getContent();
        } catch (\Exception $e) {
            return null;
        }
        $inScheme = array_key_exists($schemeId, $item->itemSets());
        return $inScheme && CurModel::MATERIA_CURSO === CurModel::classTerm($item) ? $item : null;
    }

    /**
     * Every current term of $class under one course subject, page by page.
     * The page cap only guards against a corrupt corpus: 1.1.2 needs one page.
     *
     * @return list<AbstractResourceEntityRepresentation>
     */
    private function courseSubjectTerms(int $schemeId, string $class, int $courseSubjectId): array
    {
        $classId = $this->classId($class);
        if (null === $classId) {
            return [];
        }
        $items = [];
        for ($page = 1; $page <= self::TREE_MAX_PAGES; $page++) {
            $batch = $this->api->search(
                'items',
                CurModel::courseSubjectQuery($schemeId, $classId, $courseSubjectId, self::TREE_PAGE_SIZE, $page)
            )->getContent();
            foreach ($batch as $item) {
                $items[] = $item;
            }
            if (count($batch) < self::TREE_PAGE_SIZE) {
                break;
            }
        }
        return $items;
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
