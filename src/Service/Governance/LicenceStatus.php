<?php

declare(strict_types=1);

namespace OERManager\Service\Governance;

/**
 * The three licence states ADR-0013 §3 asked for and slice 2 deferred to the
 * panel, plus the honest fourth one: with no vocabulary configured, membership
 * is not judged (ADR-0019 left validation open; this closes it).
 */
final class LicenceStatus
{
    public const MISSING = 'missing';
    public const IN_VOCAB = 'in_vocab';
    public const OUTSIDE_VOCAB = 'outside_vocab';
    public const UNCHECKED = 'unchecked';

    /**
     * @param list<array<string,string>> $values licence values, typed as in GovernanceFields
     * @param list<string>|null $vocabUris URIs of the configured vocabulary, null if unresolved
     */
    public static function of(array $values, ?array $vocabUris): string
    {
        if ([] === $values) {
            return self::MISSING;
        }
        if (null === $vocabUris) {
            return self::UNCHECKED;
        }
        $allowed = array_map([self::class, 'canonical'], $vocabUris);
        foreach ($values as $value) {
            $uri = self::canonical((string) ($value['uri'] ?? ''));
            if ('' === $uri || !in_array($uri, $allowed, true)) {
                return self::OUTSIDE_VOCAB;
            }
        }
        return self::IN_VOCAB;
    }

    /**
     * Host case and one trailing slash are not a different licence; the rest of
     * the URI — including any later occurrence of the host substring, in a path
     * or query — is compared verbatim, because a path is case-sensitive.
     */
    private static function canonical(string $uri): string
    {
        $uri = trim($uri);
        if (str_ends_with($uri, '/')) {
            $uri = substr($uri, 0, -1);
        }
        $host = (string) parse_url($uri, PHP_URL_HOST);
        if ('' === $host) {
            return $uri;
        }
        $offset = strpos($uri, $host);
        if (false === $offset) {
            return $uri;
        }
        return substr_replace($uri, strtolower($host), $offset, strlen($host));
    }
}
