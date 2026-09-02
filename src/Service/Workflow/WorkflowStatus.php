<?php

declare(strict_types=1);

namespace OERManager\Service\Workflow;

/**
 * Máquina de estados del flujo autor→curador de REAs (RF-016, ADR-0018).
 * Puro: compara el literal de `curation:status` por igualdad exacta, nunca
 * toca el core. `Borrador` es la AUSENCIA de valor (null) — no hay una
 * constante para él, y "publicado" no es un 4º valor: al publicar,
 * `curation:status` se elimina (ver WorkflowService::publish()).
 */
final class WorkflowStatus
{
    public const STATUS_TERM = 'curation:status';
    public const NOTE_TERM = 'curation:note';

    public const PROPOSED = 'Propuesto';
    public const REJECTED = 'Rechazado';

    /** Borrador (ausente) o ya rechazado: el autor puede (re)proponer. */
    public static function canPropose(?string $status): bool
    {
        return null === $status || self::REJECTED === $status;
    }

    /** Solo un propuesto puede rechazarse. */
    public static function canReject(?string $status): bool
    {
        return self::PROPOSED === $status;
    }

    /** Solo un propuesto puede publicarse por este flujo. */
    public static function canPublish(?string $status): bool
    {
        return self::PROPOSED === $status;
    }
}
