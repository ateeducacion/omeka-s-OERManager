<?php

declare(strict_types=1);

namespace OERManager\Service\Governance;

/**
 * The five governance fields of RF-015 and the rules for turning form input
 * into values (ADR-0013 §5.5, ADR-0019 for the licence).
 *
 * Pure on purpose: validation is what decides whether a write reaches the
 * catalogue, and nothing that touches the Omeka core can be tested on the host
 * (the TASK-008 harness limitation).
 */
final class GovernanceFields
{
    public const LICENCE = 'dcterms:license';
    public const CREATOR = 'dcterms:creator';
    public const PUBLISHER = 'dcterms:publisher';
    public const RIGHTS_HOLDER = 'dcterms:rightsHolder';
    public const SOURCE = 'dcterms:source';

    /** term => [multiple?, kind]. `kind` is the value shape before the vocabulary upgrade. */
    private const SPEC = [
        self::LICENCE => ['multiple' => false, 'kind' => 'uri'],
        self::CREATOR => ['multiple' => true, 'kind' => 'literal'],
        self::PUBLISHER => ['multiple' => false, 'kind' => 'literal'],
        self::RIGHTS_HOLDER => ['multiple' => false, 'kind' => 'literal'],
        self::SOURCE => ['multiple' => false, 'kind' => 'uri'],
    ];

    /** @return list<string> */
    public static function all(): array
    {
        return array_keys(self::SPEC);
    }

    public static function isGovernanceTerm(string $term): bool
    {
        return isset(self::SPEC[$term]);
    }

    public static function isMultiple(string $term): bool
    {
        return self::SPEC[$term]['multiple'] ?? false;
    }

    /**
     * @param array<string, list<string>> $raw term => raw strings from the form
     * @return array{values: array<string, list<array<string,string>>>, errors: array<string,string>}
     */
    public static function normalise(array $raw): array
    {
        $values = [];
        $errors = [];

        foreach (self::SPEC as $term => $spec) {
            if (!array_key_exists($term, $raw)) {
                continue;
            }
            $entries = [];
            foreach ((array) $raw[$term] as $candidate) {
                $text = trim((string) $candidate);
                if ('' === $text) {
                    continue;
                }
                if ('uri' === $spec['kind'] && !self::isHttpUri($text)) {
                    $errors[$term] = 'not-http-uri';
                    continue 2;
                }
                $entries[] = 'uri' === $spec['kind']
                    ? ['type' => 'uri', 'uri' => $text]
                    : ['type' => 'literal', 'value' => $text];
            }
            if (!$spec['multiple'] && count($entries) > 1) {
                $errors[$term] = 'too-many';
                continue;
            }
            $values[$term] = $entries;
        }

        return ['values' => $values, 'errors' => $errors];
    }

    private static function isHttpUri(string $candidate): bool
    {
        $scheme = strtolower((string) parse_url($candidate, PHP_URL_SCHEME));
        return in_array($scheme, ['http', 'https'], true)
            && '' !== (string) parse_url($candidate, PHP_URL_HOST);
    }
}
