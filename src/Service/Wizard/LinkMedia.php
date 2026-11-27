<?php

declare(strict_types=1);

namespace OERManager\Service\Wizard;

/**
 * The media of a link added in the wizard's step ① (owner's review of PR
 * #100): a YouTube video with Omeka's `youtube` ingester, a link of another
 * oEmbed provider Omeka knows with `oembed`, anything else with `url`, which
 * downloads it and lets Omeka's file validation decide (a web page is refused
 * unless the deployment allows `text/html`).
 */
final class LinkMedia
{
    /** What Omeka's `youtube` ingester accepts: a /watch?v= page or a youtu.be link. */
    private const YOUTUBE = [
        '#^https?://((m|www)\.)?youtube\.com/watch\?(.*&)?v=[^&\#]+#i',
        '#^https?://youtu\.be/[^/?\#]+#i',
    ];

    /**
     * @param list<mixed> $oembedPatterns Omeka's `oembed.whitelist`: [regex, endpoint] pairs
     * @return array<string,string> the media's API data, without `o:item`
     */
    public static function spec(string $url, array $oembedPatterns): array
    {
        foreach (self::YOUTUBE as $pattern) {
            if (1 === preg_match($pattern, $url)) {
                return ['o:ingester' => 'youtube', 'o:source' => $url];
            }
        }
        foreach ($oembedPatterns as $entry) {
            $pattern = is_array($entry) ? (string) ($entry[0] ?? '') : '';
            if ('' !== $pattern && 1 === @preg_match($pattern, $url)) {
                return ['o:ingester' => 'oembed', 'o:source' => $url];
            }
        }
        return ['o:ingester' => 'url', 'ingest_url' => $url];
    }

    /**
     * Whether a link's host is on the public internet, so the server may
     * download it (security review: SSRF). Every address the host resolves to
     * must be global: no loopback, private, link-local, shared, reserved or
     * documentation range, in IPv4 or IPv6. A host that does not resolve is
     * refused. Omeka's HTTP client may still follow a redirect: this guards
     * the link as typed, not every hop.
     *
     * @param (callable(string): list<string>)|null $resolve host => addresses; DNS (A and AAAA) by default
     */
    public static function isPublicHost(string $url, ?callable $resolve = null): bool
    {
        $host = strtolower(rtrim((string) parse_url($url, PHP_URL_HOST), '.'));
        $host = trim($host, '[]');
        if ('' === $host) {
            return false;
        }
        $addresses = false !== filter_var($host, FILTER_VALIDATE_IP)
            ? [$host]
            : ($resolve ?? self::resolve(...))($host);
        if ([] === $addresses) {
            return false;
        }
        $flags = defined('FILTER_FLAG_GLOBAL_RANGE')
            ? FILTER_FLAG_GLOBAL_RANGE
            : FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE;
        foreach ($addresses as $address) {
            if (false === filter_var($address, FILTER_VALIDATE_IP, $flags)) {
                return false;
            }
        }
        return true;
    }

    /** @return list<string> the host's IPv4 and IPv6 addresses */
    private static function resolve(string $host): array
    {
        $addresses = gethostbynamel($host) ?: [];
        foreach (@dns_get_record($host, DNS_AAAA) ?: [] as $record) {
            if (isset($record['ipv6'])) {
                $addresses[] = (string) $record['ipv6'];
            }
        }
        return array_values(array_unique($addresses));
    }
}
