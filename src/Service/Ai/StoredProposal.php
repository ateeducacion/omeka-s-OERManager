<?php

declare(strict_types=1);

namespace OERManager\Service\Ai;

/**
 * TASK-011: the pending AI proposal stored in the REA until a curator reviews
 * it (ADR-0007 addendum 1). One private `curation:data` literal: the stable,
 * untranslatable marker, a space and a versioned JSON object. Pure: no Omeka.
 *
 * No `debug` and no titles are stored: the prompts weigh tens of KB, and
 * titles are resolved when the panel opens, so a renamed term shows its
 * current title.
 */
final class StoredProposal
{
    public const TERM = 'curation:data';
    public const MARKER = 'OERManager/ai-proposal/1';
    public const VERSION = 1;

    public static function isProposal(string $text): bool
    {
        return str_starts_with($text, self::MARKER . ' ');
    }

    /** @param array<string,mixed> $proposal */
    public static function encode(array $proposal): string
    {
        $justifications = self::justifications((array) ($proposal['justifications'] ?? []));
        $data = [
            'v' => self::VERSION,
            'created' => (string) $proposal['created'],
            'model' => (string) ($proposal['model'] ?? ''),
            'item_modified' => $proposal['item_modified'] ?? null,
            // Objects, never lists: an empty map must stay `{}`, and a map of
            // ids must not turn into a list when its keys happen to be 0..n.
            'alignment' => (object) self::alignment((array) ($proposal['alignment'] ?? [])),
            'justifications' => (object) array_map(
                static fn (array $map): object => (object) $map,
                $justifications
            ),
        ];
        $json = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        return self::MARKER . ' ' . $json;
    }

    /** @return array<string,mixed>|null */
    public static function decode(string $text): ?array
    {
        if (!self::isProposal($text)) {
            return null;
        }
        try {
            $data = json_decode(substr($text, strlen(self::MARKER) + 1), true, 16, JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            return null;
        }
        if (
            !is_array($data)
            || self::VERSION !== ($data['v'] ?? null)
            || !is_string($data['created'] ?? null)
            || !is_array($data['alignment'] ?? null)
        ) {
            return null;
        }
        return [
            'created' => $data['created'],
            'model' => is_string($data['model'] ?? null) ? $data['model'] : '',
            'item_modified' => is_string($data['item_modified'] ?? null) ? $data['item_modified'] : null,
            'alignment' => self::alignment($data['alignment']),
            'justifications' => self::justifications(
                is_array($data['justifications'] ?? null) ? $data['justifications'] : []
            ),
        ];
    }

    /**
     * @param array<string,mixed> $payload ProposeRunner::run(): alignment as `{id,title}` chips
     * @return array<string,mixed>
     */
    public static function fromPayload(
        array $payload,
        string $model,
        \DateTimeInterface $now,
        ?\DateTimeInterface $itemModified
    ): array {
        $alignment = [];
        foreach ((array) ($payload['alignment'] ?? []) as $term => $chips) {
            foreach ((array) $chips as $chip) {
                $alignment[$term][] = is_array($chip) ? ($chip['id'] ?? 0) : $chip;
            }
        }
        return [
            'created' => $now->format(DATE_ATOM),
            'model' => $model,
            'item_modified' => $itemModified?->format(DATE_ATOM),
            'alignment' => self::alignment($alignment),
            'justifications' => self::justifications((array) ($payload['justifications'] ?? [])),
        ];
    }

    /** True when the REA was modified after the proposal was made. */
    public static function changed(array $proposal, ?\DateTimeInterface $modified): bool
    {
        if (null === $modified) {
            return false;
        }
        $then = $proposal['item_modified'] ?? null;
        return null === $then || $modified > new \DateTimeImmutable((string) $then);
    }

    /** @param iterable<string> $texts */
    public static function firstOf(iterable $texts): ?array
    {
        foreach ($texts as $text) {
            $proposal = self::decode((string) $text);
            if (null !== $proposal) {
                return $proposal;
            }
        }
        return null;
    }

    /** @param iterable<string> $texts */
    public static function anyMarked(iterable $texts): bool
    {
        foreach ($texts as $text) {
            if (self::isProposal((string) $text)) {
                return true;
            }
        }
        return false;
    }

    /** @return array{property:string,type:string,text:string} a native item search row on the marker */
    public static function searchRow(string $type): array
    {
        return ['property' => self::TERM, 'type' => $type, 'text' => self::MARKER];
    }

    /**
     * @param callable(int):?string $title null when the term no longer exists
     * @return array<string,mixed>
     */
    public static function forPanel(array $proposal, ?\DateTimeInterface $modified, callable $title): array
    {
        $alignment = [];
        foreach ($proposal['alignment'] as $term => $ids) {
            foreach ($ids as $id) {
                $text = $title($id);
                if (null !== $text) {
                    $alignment[$term][] = ['id' => $id, 'title' => $text];
                }
            }
        }
        return [
            'created' => $proposal['created'],
            'changed' => self::changed($proposal, $modified),
            'alignment' => $alignment,
            'justifications' => $proposal['justifications'],
        ];
    }

    /** @return array<string,list<int>> positive, unique ids; empty terms dropped */
    private static function alignment(array $alignment): array
    {
        $clean = [];
        foreach ($alignment as $term => $ids) {
            if (!is_string($term) || !is_array($ids)) {
                continue;
            }
            $list = [];
            foreach ($ids as $id) {
                $id = is_numeric($id) ? (int) $id : 0;
                if ($id > 0 && !in_array($id, $list, true)) {
                    $list[] = $id;
                }
            }
            if ($list) {
                $clean[$term] = $list;
            }
        }
        return $clean;
    }

    /** @return array<string,array<int,string>> */
    private static function justifications(array $justifications): array
    {
        $clean = [];
        foreach ($justifications as $term => $map) {
            if (!is_string($term) || !is_array($map)) {
                continue;
            }
            foreach ($map as $id => $text) {
                if (is_int($id) && $id > 0 && is_string($text) && '' !== $text) {
                    $clean[$term][$id] = $text;
                }
            }
        }
        return $clean;
    }
}
