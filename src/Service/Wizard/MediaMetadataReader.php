<?php

declare(strict_types=1);

namespace OERManager\Service\Wizard;

use OERManager\Service\Content\ContentExtractor;

/**
 * Suggestions for step ② from the file uploaded in step ① (TASK-077): the
 * title, description, authorship and licence declared by an eXeLearning
 * package (`content.xml`, ODE properties `pp_*`), and the title of a PDF.
 *
 * The file is untrusted data. The zip is read under ContentExtractor's limits
 * (entry size, compression ratio), the XML without network, DTD or entity
 * substitution, and a document that declares entities is refused. The PDF is
 * never parsed: only its first and last bytes are scanned for the info
 * dictionary's `/Title` or the XMP `dc:title`, so a heavy PDF costs nothing.
 * It never throws: an unreadable file means no suggestions.
 */
final class MediaMetadataReader
{
    /** ODE property => suggestion key. */
    private const ODE_KEYS = [
        'pp_title' => 'title',
        'pp_description' => 'description',
        'pp_author' => 'author',
        'pp_license' => 'licence',
    ];

    /** Bytes scanned at each end of a PDF. */
    private const PDF_HEAD = 1048576;
    private const PDF_TAIL = 262144;

    /** A suggestion is a label, not a text. */
    private const MAX_VALUE = 2000;

    /** @var array<string,mixed> */
    private array $limits;

    /** @param array<string,mixed> $limits overrides of ContentExtractor::DEFAULTS */
    public function __construct(array $limits = [])
    {
        $this->limits = $limits + ContentExtractor::DEFAULTS;
    }

    /** @return array{title?:string,description?:string,author?:string,licence?:string} */
    public function read(string $path, string $name): array
    {
        try {
            if (!is_file($path) || !is_readable($path)) {
                return [];
            }
            $head = (string) file_get_contents($path, false, null, 0, 5);
            if (str_starts_with($head, '%PDF')) {
                return $this->pdf($path);
            }
            if (str_starts_with($head, 'PK') && class_exists(\ZipArchive::class)) {
                return $this->package($path);
            }
        } catch (\Throwable $e) {
            return [];
        }
        return [];
    }

    /** @return array<string,string> */
    private function package(string $path): array
    {
        $zip = new \ZipArchive();
        if (true !== $zip->open($path, \ZipArchive::RDONLY)) {
            return [];
        }
        try {
            $stat = $zip->statName('content.xml');
            if (false === $stat) {
                return [];
            }
            $size = (int) $stat['size'];
            $compressed = max(1, (int) $stat['comp_size']);
            $bomb = $size > 1048576 && $size / $compressed > (float) $this->limits['max_compression_ratio'];
            if ($bomb || $size > (int) $this->limits['max_entry_bytes']) {
                return [];
            }
            $xml = $zip->getFromIndex((int) $stat['index'], (int) $this->limits['max_entry_bytes']);
        } finally {
            $zip->close();
        }
        return is_string($xml) ? $this->odeProperties($xml) : [];
    }

    /** @return array<string,string> */
    private function odeProperties(string $xml): array
    {
        if ('' === trim($xml) || 1 === preg_match('/<!ENTITY/i', $xml)) {
            return [];
        }
        $previous = libxml_use_internal_errors(true);
        try {
            $doc = new \DOMDocument();
            $loaded = $doc->loadXML($xml, LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING);
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }
        if (!$loaded || 'ode' !== $doc->documentElement?->localName) {
            return [];
        }
        $xpath = new \DOMXPath($doc);
        $out = [];
        $query = "/*[local-name()='ode']/*[local-name()='odeProperties']/*[local-name()='odeProperty']";
        foreach ($xpath->query($query) ?: [] as $node) {
            $key = strtolower(trim($xpath->evaluate("string(*[local-name()='key'])", $node)));
            $field = self::ODE_KEYS[$key] ?? null;
            if (null === $field || isset($out[$field])) {
                continue;
            }
            $value = self::plain($xpath->evaluate("string(*[local-name()='value'])", $node));
            // eXeLearning's default title is not a real one.
            if ('' !== $value && !('title' === $field && 'exelearning' === strtolower($value))) {
                $out[$field] = $value;
            }
        }
        return array_intersect_key(array_merge(array_flip(self::ODE_KEYS), $out), $out);
    }

    /** @return array<string,string> */
    private function pdf(string $path): array
    {
        $size = (int) filesize($path);
        $bytes = (string) file_get_contents($path, false, null, 0, self::PDF_HEAD);
        if ($size > self::PDF_HEAD) {
            $bytes .= (string) file_get_contents($path, false, null, max(self::PDF_HEAD, $size - self::PDF_TAIL));
        }
        if (1 === preg_match('/<dc:title>.*?<rdf:li[^>]*>([^<]{1,2000})<\/rdf:li>/s', $bytes, $m)) {
            $title = self::plain(html_entity_decode($m[1], ENT_QUOTES | ENT_XML1, 'UTF-8'));
            if ('' !== $title) {
                return ['title' => $title];
            }
        }
        if (1 === preg_match('/\/Title\s*(\((?:\\\\.|[^\\\\()])*\)|<[0-9A-Fa-f\s]*>)/s', $bytes, $m)) {
            $title = self::plain(self::pdfString($m[1]));
            if ('' !== $title) {
                return ['title' => $title];
            }
        }
        return [];
    }

    /** A PDF literal `(…)` or hex `<…>` string, as UTF-8. */
    private static function pdfString(string $token): string
    {
        if (str_starts_with($token, '<')) {
            $hex = (string) preg_replace('/\s+/', '', trim($token, '<>'));
            $raw = (string) hex2bin(0 === strlen($hex) % 2 ? $hex : $hex . '0');
        } else {
            $raw = (string) preg_replace_callback(
                '/\\\\([nrtbf()\\\\]|[0-7]{1,3})/',
                static function (array $m): string {
                    $map = ['n' => "\n", 'r' => "\r", 't' => "\t", 'b' => "\x08", 'f' => "\f"];
                    return $map[$m[1]] ?? (ctype_digit($m[1]) ? chr(octdec($m[1]) & 0xFF) : $m[1]);
                },
                substr($token, 1, -1)
            );
        }
        if (str_starts_with($raw, "\xFE\xFF")) {
            return (string) mb_convert_encoding(substr($raw, 2), 'UTF-8', 'UTF-16BE');
        }
        return mb_check_encoding($raw, 'UTF-8') ? $raw : (string) mb_convert_encoding($raw, 'UTF-8', 'ISO-8859-1');
    }

    /** Text without markup, whitespace collapsed and bounded. */
    private static function plain(string $value): string
    {
        $text = html_entity_decode(strip_tags($value), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $text = trim((string) preg_replace('/\s+/u', ' ', $text));
        $text = (string) preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', $text);
        return mb_substr($text, 0, self::MAX_VALUE);
    }
}
