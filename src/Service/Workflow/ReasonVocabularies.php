<?php

declare(strict_types=1);

namespace OERManager\Service\Workflow;

use OERManager\Service\Governance\VocabEntries;

/**
 * The reasons to reject and to discard come from their own CustomVocab
 * (TASK-039). Without a usable vocabulary (setting empty, vocabulary missing
 * or not term-typed, CustomVocab inactive, no terms), the reason is free
 * text, as before; the flow is never blocked. Terms are matched exactly,
 * after trimming, and never by id or position: the vocabulary is the
 * administrator's.
 */
final class ReasonVocabularies
{
    public const REJECT = 'reject';
    public const DISCARD = 'discard';

    public function __construct(
        private readonly VocabEntries $reject,
        private readonly VocabEntries $discard
    ) {
    }

    /** @return list<string>|null the terms the form offers; null = free text */
    public function terms(string $action): ?array
    {
        $vocab = $this->vocab($action);
        if (null === $vocab || null === $vocab->dataType()) {
            return null;
        }
        $terms = [];
        foreach ($vocab->entries() as $entry) {
            if (isset($entry['uri'])) {
                continue;
            }
            $term = trim((string) ($entry['value'] ?? ''));
            if ('' !== $term && !in_array($term, $terms, true)) {
                $terms[] = $term;
            }
        }
        return [] === $terms ? null : $terms;
    }

    /**
     * @return array{reason: WorkflowReason}|array{error: string} the error is
     *         `reason_required` or `reason_invalid`
     */
    public function resolve(string $action, string $reason, string $detail): array
    {
        $reason = trim($reason);
        if ('' === $reason) {
            return ['error' => 'reason_required'];
        }
        $terms = $this->terms($action);
        if (null === $terms) {
            return ['reason' => WorkflowReason::literal($reason)];
        }
        if (!in_array($reason, $terms, true)) {
            return ['error' => 'reason_invalid'];
        }
        return ['reason' => WorkflowReason::term($reason, (string) $this->vocab($action)?->dataType(), $detail)];
    }

    private function vocab(string $action): ?VocabEntries
    {
        return match ($action) {
            self::REJECT => $this->reject,
            self::DISCARD => $this->discard,
            default => null,
        };
    }
}
