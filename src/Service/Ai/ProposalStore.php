<?php

namespace OERManager\Service\Ai;

/**
 * Canal de estado/resultado entre el AiProposeJob (proceso CLI en 2º plano) y el
 * polling del navegador (proceso web): dos procesos PHP distintos que no comparten
 * memoria (TASK-020). Fichero JSON por jobId en un directorio PRIVADO (nunca en
 * files/ público). Escritura atómica (temp + rename) para que el lector nunca vea
 * un fichero a medias. `read` no borra: la recuperación tras abandono lo necesita;
 * la limpieza es por TTL (`sweepOld`).
 */
final class ProposalStore
{
    public function __construct(private string $baseDir)
    {
    }

    /** @param array<string,mixed> $state */
    public function write(int $jobId, array $state): void
    {
        if (!is_dir($this->baseDir)) {
            @mkdir($this->baseDir, 0700, true);
        }
        $json = json_encode($state, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $tmp = $this->baseDir . '/.' . $jobId . '.' . bin2hex(random_bytes(4)) . '.tmp';
        file_put_contents($tmp, (string) $json);
        rename($tmp, $this->path($jobId)); // atómico en el mismo sistema de ficheros
    }

    /** @return array<string,mixed>|null */
    public function read(int $jobId): ?array
    {
        $path = $this->path($jobId);
        if (!is_file($path)) {
            return null;
        }
        $raw = file_get_contents($path);
        if (false === $raw || '' === $raw) {
            return null;
        }
        $data = json_decode($raw, true);
        return is_array($data) ? $data : null;
    }

    public function sweepOld(int $ttlSeconds): void
    {
        $cutoff = time() - $ttlSeconds;
        foreach (glob($this->baseDir . '/*.json') ?: [] as $file) {
            if (@filemtime($file) < $cutoff) {
                @unlink($file);
            }
        }
    }

    private function path(int $jobId): string
    {
        return $this->baseDir . '/' . $jobId . '.json';
    }
}
