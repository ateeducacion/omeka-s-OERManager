<?php

declare(strict_types=1);

namespace OERManager\Service\Governance;

/**
 * A frozen batch (TASK-028 slice 4): who asked, which ids, which values, which
 * mode. The id list is fixed at preview time so the number the curator
 * confirms is the set the job processes. Pure.
 */
final class BatchPlan
{
    /**
     * @param list<int> $ids
     * @param array<string,list<string>> $raw
     */
    public function __construct(
        public readonly int $ownerId,
        public readonly array $ids,
        public readonly array $raw,
        public readonly string $mode
    ) {
    }

    /**
     * Preview per ticked field, from how many of the ids already have a value.
     *
     * @param array<string,int> $haveValue term => count of ids with a value
     * @return array<string,array<string,int>>
     */
    public function summary(array $haveValue): array
    {
        $total = count($this->ids);
        $summary = [];
        foreach (array_keys($this->raw) as $term) {
            $have = min($total, max(0, (int) ($haveValue[$term] ?? 0)));
            $summary[$term] = BatchRequest::MODE_REPLACE === $this->mode
                ? ['total' => $total, 'write' => $total, 'overwrite' => $have]
                : ['total' => $total, 'write' => $total - $have, 'skipped_has_value' => $have];
        }
        return $summary;
    }

    /**
     * Largest per-field write count: 0 means applying would write nothing.
     *
     * @param array<string,array<string,int>> $summary
     */
    public static function writeTotal(array $summary): int
    {
        $max = 0;
        foreach ($summary as $field) {
            $max = max($max, (int) $field['write']);
        }
        return $max;
    }

    /** @return array<string,mixed> */
    public function toArray(): array
    {
        return ['owner' => $this->ownerId, 'ids' => $this->ids, 'raw' => $this->raw, 'mode' => $this->mode];
    }

    /** @param array<string,mixed> $data */
    public static function fromArray(array $data): ?self
    {
        if (
            !isset($data['owner'], $data['ids'], $data['raw'], $data['mode'])
            || !is_array($data['ids']) || !is_array($data['raw'])
        ) {
            return null;
        }
        return new self(
            (int) $data['owner'],
            array_values(array_map('intval', $data['ids'])),
            $data['raw'],
            (string) $data['mode']
        );
    }
}
