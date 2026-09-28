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
        // Check all required keys exist
        if (!isset($data['owner'], $data['ids'], $data['raw'], $data['mode'])) {
            return null;
        }

        // Validate owner: numeric and > 0
        $owner = $data['owner'];
        if (!is_numeric($owner)) {
            return null;
        }
        $ownerId = (int) $owner;
        if ($ownerId <= 0) {
            return null;
        }

        // Validate ids: non-empty array with each element int/numeric string > 0
        if (!is_array($data['ids']) || empty($data['ids'])) {
            return null;
        }
        $ids = [];
        foreach ($data['ids'] as $id) {
            if (!is_numeric($id)) {
                return null;
            }
            $idInt = (int) $id;
            if ($idInt <= 0) {
                return null;
            }
            $ids[] = $idInt;
        }

        // Validate raw: non-empty array with non-empty string keys and list<string> values
        if (!is_array($data['raw']) || empty($data['raw'])) {
            return null;
        }
        foreach ($data['raw'] as $key => $value) {
            // Key must be non-empty string
            if (!is_string($key) || $key === '') {
                return null;
            }
            // Value must be a list (array)
            if (!is_array($value)) {
                return null;
            }
            // Every element in value must be a string
            foreach ($value as $element) {
                if (!is_string($element)) {
                    return null;
                }
            }
        }

        // Validate mode
        $mode = (string) $data['mode'];
        if ($mode !== BatchRequest::MODE_FILL && $mode !== BatchRequest::MODE_REPLACE) {
            return null;
        }

        return new self($ownerId, $ids, $data['raw'], $mode);
    }
}
