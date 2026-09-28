<?php

declare(strict_types=1);

namespace OERManager\Service\Governance;

/**
 * The curator's batch request (TASK-028 slice 4), validated. Pure.
 *
 * A term present in the input is a field the curator ticked; an absent one is
 * «do not touch». `dcterms:source` is never batched: it is the URI one REA came
 * from. A ticked field must carry a value — clearing a field across thousands
 * of REA is not an assignment and is out of this slice.
 */
final class BatchRequest
{
    public const MODE_FILL = 'fill';
    public const MODE_REPLACE = 'replace';

    public const BATCH_TERMS = [
        GovernanceFields::LICENCE,
        GovernanceFields::CREATOR,
        GovernanceFields::PUBLISHER,
        GovernanceFields::RIGHTS_HOLDER,
    ];

    /**
     * @param array<string,list<string>> $raw
     * @param array<string,string> $errors
     */
    private function __construct(
        public readonly array $raw,
        public readonly string $mode,
        public readonly array $errors
    ) {
    }

    public static function fromPost(mixed $governance, mixed $mode): self
    {
        $mode = null === $mode || '' === $mode ? self::MODE_FILL : (string) $mode;
        $posted = is_array($governance) ? $governance : [];

        $raw = [];
        foreach (self::BATCH_TERMS as $term) {
            if (!array_key_exists($term, $posted)) {
                continue;
            }
            $raw[$term] = array_values(array_filter(
                array_map(static fn ($value): string => trim((string) $value), (array) $posted[$term]),
                static fn (string $value): bool => '' !== $value
            ));
        }

        $errors = [];
        if (!in_array($mode, [self::MODE_FILL, self::MODE_REPLACE], true)) {
            $errors['_'] = 'mode';
        }
        if ([] === $raw) {
            $errors['_'] = 'no-field';
        }
        foreach ($raw as $term => $values) {
            if ([] === $values) {
                $errors[$term] = 'required';
            }
        }
        $errors += GovernanceFields::normalise($raw)['errors'];

        return new self($raw, $mode, $errors);
    }

    public function isValid(): bool
    {
        return [] === $this->errors;
    }
}
