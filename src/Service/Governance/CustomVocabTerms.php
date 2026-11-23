<?php

declare(strict_types=1);

namespace OERManager\Service\Governance;

use Omeka\Api\Manager as ApiManager;

/**
 * Reader for VocabEntries over a TERM-typed CustomVocab (TASK-039): each
 * term as `['value' => term]`. A URI or item-set vocabulary yields no
 * entries, because a reason is a plain term; such a vocabulary therefore
 * counts as not configured. A missing vocabulary, or an inactive CustomVocab,
 * throws, and VocabEntries turns that into «unresolved».
 */
final class CustomVocabTerms
{
    public function __construct(private readonly ApiManager $api)
    {
    }

    /** @return list<array{value:string}> */
    public function __invoke(int $id): array
    {
        $vocab = $this->api->read('custom_vocabs', $id)->getContent();
        if ('literal' !== $vocab->type()) {
            return [];
        }
        $entries = [];
        foreach (array_values($vocab->listTerms() ?? []) as $term) {
            $entries[] = ['value' => (string) $term];
        }
        return $entries;
    }
}
