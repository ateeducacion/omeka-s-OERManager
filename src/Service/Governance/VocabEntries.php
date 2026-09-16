<?php

declare(strict_types=1);

namespace OERManager\Service\Governance;

/**
 * Entries of a CustomVocab identified by a setting (ADR-0013, "seed, do not
 * own"). Never identified by label: labels change with i18n and with admin
 * edits. Unresolved means unresolved, not empty — the difference decides
 * whether the panel judges vocabulary membership at all.
 */
final class VocabEntries
{
    private ?int $vocabId;
    /** @var callable(int):list<array<string,string>> */
    private $reader;
    /** @var list<array<string,string>>|null */
    private ?array $entries = null;
    private bool $resolved = false;

    public function __construct(?int $vocabId, callable $reader)
    {
        $this->vocabId = $vocabId && $vocabId > 0 ? $vocabId : null;
        $this->reader = $reader;
    }

    /** @return list<array<string,string>> */
    public function entries(): array
    {
        if (!$this->resolved) {
            $this->resolved = true;
            if (null !== $this->vocabId) {
                try {
                    $this->entries = array_values(($this->reader)($this->vocabId));
                } catch (\Throwable $e) {
                    $this->entries = null;
                }
            }
        }
        return $this->entries ?? [];
    }

    /** @return list<string>|null null when the vocabulary does not resolve */
    public function uris(): ?array
    {
        $this->entries();
        if (null === $this->entries) {
            return null;
        }
        return array_values(array_filter(array_map(
            static fn (array $entry): string => trim((string) ($entry['uri'] ?? '')),
            $this->entries
        ), static fn (string $uri): bool => '' !== $uri));
    }

    public function dataType(): ?string
    {
        $this->entries();
        return null === $this->entries || null === $this->vocabId ? null : 'customvocab:' . $this->vocabId;
    }

    public function isAvailable(): bool
    {
        return [] !== $this->entries();
    }
}
