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

    /**
     * Literal crudo de `curation:status` → estado. Vacío o en blanco es
     * Borrador (null). Única regla de lectura: la usan el servicio y la fila
     * de la vista maestra, que lee el valor de la representación que ya tiene
     * (RF-017, sin consulta extra por fila).
     */
    public static function normalize(?string $raw): ?string
    {
        $status = trim((string) $raw);
        return '' === $status ? null : $status;
    }

    /**
     * Insignia que lleva un REA (RF-017, TASK-044): `proposed`, `rejected` o
     * null. Borrador, publicado y cualquier literal desconocido no llevan
     * ninguna — igualdad exacta, como el resto de la máquina de estados.
     */
    public static function badge(?string $status): ?string
    {
        return match ($status) {
            self::PROPOSED => 'proposed',
            self::REJECTED => 'rejected',
            default => null,
        };
    }

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
