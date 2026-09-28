<?php

declare(strict_types=1);

namespace OERManager\Service\Governance;

/**
 * Plans between preview and apply (TASK-028 slice 4): one private JSON file
 * per random token, same atomic-write pattern as ProposalStore. A plan is taken
 * once, only by its owner and only before it expires; the token is validated
 * against its exact shape before any path is built from it.
 */
final class BatchPlanStore
{
    private const TOKEN_PATTERN = '/^[a-f0-9]{32}$/';

    public function __construct(private string $baseDir, private int $ttlSeconds = 1800)
    {
    }

    public function put(BatchPlan $plan): string
    {
        if (!is_dir($this->baseDir)) {
            @mkdir($this->baseDir, 0700, true);
        }
        $token = bin2hex(random_bytes(16));
        $tmp = $this->baseDir . '/.' . $token . '.tmp';
        $json = json_encode(
            $plan->toArray(),
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
        );
        file_put_contents($tmp, (string) $json);
        rename($tmp, $this->path($token));
        return $token;
    }

    public function take(string $token, int $ownerId): ?BatchPlan
    {
        if (1 !== preg_match(self::TOKEN_PATTERN, $token)) {
            return null;
        }
        $path = $this->path($token);
        if (!is_file($path)) {
            return null;
        }
        if (@filemtime($path) < time() - $this->ttlSeconds) {
            @unlink($path);
            return null;
        }
        $data = json_decode((string) file_get_contents($path), true);
        $plan = is_array($data) ? BatchPlan::fromArray($data) : null;
        if (null === $plan || $plan->ownerId !== $ownerId) {
            return null;
        }
        @unlink($path);
        return $plan;
    }

    public function sweepOld(): void
    {
        $cutoff = time() - $this->ttlSeconds;
        foreach (glob($this->baseDir . '/*.json') ?: [] as $file) {
            if (@filemtime($file) < $cutoff) {
                @unlink($file);
            }
        }
    }

    private function path(string $token): string
    {
        return $this->baseDir . '/' . $token . '.json';
    }
}
