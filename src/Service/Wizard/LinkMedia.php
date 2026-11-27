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
}
