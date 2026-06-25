<?php

namespace OERManager\Service;

/**
 * Resultado inmutable de una comprobación de integridad RDF (RF-006, TASK-005).
 * El status se calcula automáticamente desde la severidad de los issues:
 * 'error' si hay algún enlace roto, 'warning' si faltan campos, 'ok' si pasa.
 */
class IntegrityResult
{
    public const STATUS_OK = 'ok';
    public const STATUS_WARNING = 'warning';
    public const STATUS_ERROR = 'error';

    private string $status;

    /**
     * @param array<int, array{severity: string, code: string, field: string, message: string}> $issues
     */
    public function __construct(private readonly array $issues = [])
    {
        $hasError = false;
        $hasWarning = false;
        foreach ($issues as $issue) {
            if ($issue['severity'] === 'error') {
                $hasError = true;
            } elseif ($issue['severity'] === 'warning') {
                $hasWarning = true;
            }
        }
        $this->status = $hasError
            ? self::STATUS_ERROR
            : ($hasWarning ? self::STATUS_WARNING : self::STATUS_OK);
    }

    public function getStatus(): string
    {
        return $this->status;
    }

    /**
     * @return array<int, array{severity: string, code: string, field: string, message: string}>
     */
    public function getIssues(): array
    {
        return $this->issues;
    }

    public function isOk(): bool
    {
        return $this->status === self::STATUS_OK;
    }

    /** Issues filtrados por severidad ('error' o 'warning'). */
    public function getIssuesBySeverity(string $severity): array
    {
        return array_values(array_filter(
            $this->issues,
            fn(array $issue): bool => $issue['severity'] === $severity
        ));
    }
}
