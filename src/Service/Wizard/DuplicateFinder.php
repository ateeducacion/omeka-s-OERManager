<?php

declare(strict_types=1);

namespace OERManager\Service\Wizard;

use Omeka\Api\Manager as ApiManager;

/**
 * The non-blocking duplicate warning of «④ Revisar» (TASK-077 spec): other
 * REA with the same title (exact; the database collation ignores case) or
 * with a media file of the same original name.
 *
 * The title search goes through the API, so it only sees what the user may
 * read. The media names are looked up by a reader that returns item ids only
 * (Omeka's media search has no `source` filter); each id is then read
 * through the API, so a REA the user may not read is never revealed.
 */
class DuplicateFinder
{
    public const MAX = 5;

    /** @var \Closure(string): list<int> */
    private \Closure $itemIdsByMediaName;

    /** @param callable(string): list<int> $itemIdsByMediaName */
    public function __construct(
        private readonly ApiManager $api,
        callable $itemIdsByMediaName,
        private readonly ?int $learningResourceClassId
    ) {
        $this->itemIdsByMediaName = \Closure::fromCallable($itemIdsByMediaName);
    }

    /** @return list<array{id:int,title:string,reason:string}> */
    public function find(string $title, string $mediaName, ?int $exceptId): array
    {
        $found = [];
        $title = trim($title);
        if ('' !== $title) {
            $query = [
                'property' => [['property' => 'dcterms:title', 'type' => 'eq', 'text' => $title]],
                'page' => 1,
                'per_page' => self::MAX,
            ];
            if (null !== $this->learningResourceClassId) {
                $query['resource_class_id'] = $this->learningResourceClassId;
            }
            foreach ($this->api->search('items', $query)->getContent() as $item) {
                $found[(int) $item->id()] ??= ['id' => (int) $item->id(), 'title' => (string) $item->displayTitle(),
                    'reason' => 'title'];
            }
        }
        $mediaName = WizardUploadStore::cleanName($mediaName);
        if ('' !== trim($mediaName) && 'recurso' !== $mediaName) {
            foreach (($this->itemIdsByMediaName)($mediaName) as $id) {
                $id = (int) $id;
                if (isset($found[$id]) || $id === $exceptId || count($found) > self::MAX) {
                    continue;
                }
                try {
                    $item = $this->api->read('items', $id)->getContent();
                } catch (\Exception $e) {
                    continue;
                }
                $found[$id] = ['id' => $id, 'title' => (string) $item->displayTitle(), 'reason' => 'name'];
            }
        }
        unset($found[(int) $exceptId]);
        return array_slice(array_values($found), 0, self::MAX);
    }
}
