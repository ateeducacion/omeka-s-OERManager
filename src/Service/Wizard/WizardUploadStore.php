<?php

declare(strict_types=1);

namespace OERManager\Service\Wizard;

/**
 * Files uploaded in the wizard's step ① until `save` ingests them (TASK-077).
 *
 * A private directory outside Omeka's public `files/` (as ProposalStore), one
 * file per upload named by an unguessable token, with its metadata beside it.
 * A token is only good for the user who uploaded it: another user's token is
 * answered as missing. Old uploads are swept by TTL; an ingested one is
 * forgotten at once.
 */
final class WizardUploadStore
{
    /** An upload not saved within a day is dropped. */
    public const TTL = 86400;

    public function __construct(private readonly string $baseDir)
    {
    }

    /** Moves `$tmpPath` into the store and returns its token. */
    public function put(int $userId, string $tmpPath, string $name, string $mediaType): string
    {
        if (!is_dir($this->baseDir)) {
            @mkdir($this->baseDir, 0700, true);
        }
        $token = bin2hex(random_bytes(16));
        $path = $this->filePath($token);
        if (!@rename($tmpPath, $path)) {
            if (!@copy($tmpPath, $path)) {
                throw new \RuntimeException('upload_not_stored');
            }
            @unlink($tmpPath);
        }
        @chmod($path, 0600);
        $meta = [
            'user' => $userId,
            'name' => self::cleanName($name),
            'mediaType' => $mediaType,
            'size' => (int) filesize($path),
        ];
        file_put_contents($this->metaPath($token), (string) json_encode($meta, JSON_UNESCAPED_UNICODE));
        @chmod($this->metaPath($token), 0600);
        return $token;
    }

    /** @return array{path:string,name:string,mediaType:string,size:int}|null */
    public function get(int $userId, string $token): ?array
    {
        if (!self::isToken($token) || !is_file($this->filePath($token)) || !is_file($this->metaPath($token))) {
            return null;
        }
        $meta = json_decode((string) file_get_contents($this->metaPath($token)), true);
        if (!is_array($meta) || (int) ($meta['user'] ?? 0) !== $userId) {
            return null;
        }
        return [
            'path' => $this->filePath($token),
            'name' => (string) ($meta['name'] ?? ''),
            'mediaType' => (string) ($meta['mediaType'] ?? ''),
            'size' => (int) ($meta['size'] ?? 0),
        ];
    }

    public function forget(string $token): void
    {
        if (self::isToken($token)) {
            @unlink($this->filePath($token));
            @unlink($this->metaPath($token));
        }
    }

    public function sweepOld(int $ttlSeconds = self::TTL): void
    {
        $cutoff = time() - $ttlSeconds;
        foreach (glob($this->baseDir . '/*') ?: [] as $file) {
            if (@filemtime($file) < $cutoff) {
                @unlink($file);
            }
        }
    }

    public static function isToken(string $token): bool
    {
        return 1 === preg_match('/^[0-9a-f]{32}$/', $token);
    }

    /** The original name, without a path and bounded: it becomes the media's source. */
    public static function cleanName(string $name): string
    {
        $base = basename(str_replace('\\', '/', $name));
        $base = (string) preg_replace('/[\x00-\x1F\x7F]/u', '', $base);
        return mb_substr('' === $base ? 'recurso' : $base, 0, 200);
    }

    private function filePath(string $token): string
    {
        return $this->baseDir . '/' . $token . '.bin';
    }

    private function metaPath(string $token): string
    {
        return $this->baseDir . '/' . $token . '.json';
    }
}
