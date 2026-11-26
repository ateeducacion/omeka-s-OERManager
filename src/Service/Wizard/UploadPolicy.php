<?php

declare(strict_types=1);

namespace OERManager\Service\Wizard;

/**
 * Which files step ① accepts (TASK-077 spec): eXeLearning packages and zips,
 * PDF, images, audio, video and office documents, judged by the media type
 * read from the content, never by the extension. Omeka's own allow-lists
 * (Settings › Security) are applied too, so a file Omeka would refuse at
 * `save` is refused before it is stored, while nothing typed is lost.
 */
final class UploadPolicy
{
    public const TYPE = 'type';
    public const EXTENSION = 'extension';

    private const EXACT = [
        'application/zip',
        'application/x-zip-compressed',
        'application/pdf',
        'application/msword',
        'application/vnd.ms-excel',
        'application/vnd.ms-powerpoint',
        'application/rtf',
        // What finfo reports for RTF and some legacy Office files.
        'text/rtf',
        'application/vnd.ms-office',
        'application/cdfv2',
    ];

    private const PREFIXES = [
        'image/',
        'audio/',
        'video/',
        'application/vnd.openxmlformats-officedocument.',
        'application/vnd.oasis.opendocument.',
    ];

    /**
     * Null when the file is accepted, otherwise `type` or `extension`.
     *
     * @param array<string,mixed> $omeka `disable_file_validation`, `media_type_whitelist`, `extension_whitelist`
     */
    public static function refusal(string $mediaType, string $name, array $omeka): ?string
    {
        $mediaType = strtolower(trim($mediaType));
        if (!self::isWizardType($mediaType)) {
            return self::TYPE;
        }
        if (!empty($omeka['disable_file_validation'])) {
            return null;
        }
        $types = array_map('strtolower', (array) ($omeka['media_type_whitelist'] ?? []));
        $extensions = array_map('strtolower', (array) ($omeka['extension_whitelist'] ?? []));
        $extension = strtolower((string) pathinfo($name, PATHINFO_EXTENSION));
        if (!in_array($mediaType, $types, true) || '' === $extension || !in_array($extension, $extensions, true)) {
            return self::EXTENSION;
        }
        return null;
    }

    /** Upload limit in bytes: the smaller of `upload_max_filesize` and `post_max_size` (0 = no limit). */
    public static function limitBytes(string $uploadMaxFilesize, string $postMaxSize): int
    {
        $limits = array_filter(
            [self::bytes($uploadMaxFilesize), self::bytes($postMaxSize)],
            static fn (int $bytes) => $bytes > 0
        );
        return $limits ? min($limits) : 0;
    }

    private static function isWizardType(string $mediaType): bool
    {
        if (in_array($mediaType, self::EXACT, true)) {
            return true;
        }
        foreach (self::PREFIXES as $prefix) {
            if (str_starts_with($mediaType, $prefix)) {
                return true;
            }
        }
        return false;
    }

    private static function bytes(string $ini): int
    {
        $ini = trim($ini);
        if (1 !== preg_match('/^(\d+)\s*([kmg]?)$/i', $ini, $m)) {
            return 0;
        }
        $factor = ['' => 1, 'k' => 1024, 'm' => 1048576, 'g' => 1073741824][strtolower($m[2])];
        return (int) $m[1] * $factor;
    }
}
