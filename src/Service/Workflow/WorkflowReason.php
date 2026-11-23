<?php

declare(strict_types=1);

namespace OERManager\Service\Workflow;

/**
 * The reason given when a REA is rejected, discarded or unpublished
 * (TASK-039). It is written to `curation:note` as up to two PRIVATE values:
 * the reason (a CustomVocab term typed `customvocab:N`, or a literal when no
 * vocabulary resolves) and an optional detail literal. Each is cut to 200
 * characters.
 */
final class WorkflowReason
{
    public const MAX_LENGTH = 200;

    private function __construct(
        public readonly string $text,
        public readonly ?string $dataType,
        public readonly string $detail
    ) {
    }

    /** Free text, with no vocabulary: trimmed, cut, and no detail. */
    public static function literal(string $text): self
    {
        return new self(self::cut($text), null, '');
    }

    /** A term of the vocabulary whose data type is `$dataType`, with an optional detail. */
    public static function term(string $text, string $dataType, string $detail = ''): self
    {
        return new self(self::cut($text), $dataType, self::cut($detail));
    }

    public static function cut(string $text): string
    {
        return trim(mb_substr(trim($text), 0, self::MAX_LENGTH));
    }

    /** @return list<array{type:string, property_id:int, '@value':string, is_public:false}> */
    public function noteValues(int $propertyId): array
    {
        if ('' === $this->text) {
            return [];
        }
        $values = [self::value($this->dataType ?? 'literal', $propertyId, $this->text)];
        if ('' !== $this->detail) {
            $values[] = self::value('literal', $propertyId, $this->detail);
        }
        return $values;
    }

    /** @return array{type:string, property_id:int, '@value':string, is_public:false} */
    private static function value(string $type, int $propertyId, string $text): array
    {
        return ['type' => $type, 'property_id' => $propertyId, '@value' => $text, 'is_public' => false];
    }
}
