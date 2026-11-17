<?php

declare(strict_types=1);

namespace OERManager\Service\Workflow;

/**
 * Máquina de estados del flujo autor→curador de REAs (RF-016, ADR-0018,
 * ampliada por ADR-0023 §2 en TASK-067). Puro: compara el literal de
 * `curation:status` por igualdad exacta, nunca toca el core. `Borrador` es la
 * AUSENCIA de valor (null).
 *
 * Desde TASK-067 «Publicado» SÍ es un valor (antes publicar borraba el
 * estado): se conserva anotado con quién y cuándo (ADR-0002), y un REA
 * público sin él es «Publicado fuera del flujo». «Descartado» es terminal;
 * solo un curador lo reabre. La visibilidad es el `o:is_public` nativo, que
 * entra en las guardas que la necesitan.
 */
final class WorkflowStatus
{
    public const STATUS_TERM = 'curation:status';
    public const NOTE_TERM = 'curation:note';

    public const PROPOSED = 'Propuesto';
    public const REJECTED = 'Rechazado';
    public const DISCARDED = 'Descartado';
    public const PUBLISHED = 'Publicado';

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
     * Insignia que lleva un REA (RF-017, TASK-044; TASK-067 añade descartado y
     * publicado). Borrador y cualquier literal desconocido no llevan ninguna —
     * igualdad exacta, como el resto de la máquina de estados.
     */
    public static function badge(?string $status): ?string
    {
        return match ($status) {
            self::PROPOSED => 'proposed',
            self::REJECTED => 'rejected',
            self::DISCARDED => 'discarded',
            self::PUBLISHED => 'published',
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

    /**
     * «Confirmar y publicar»: un propuesto, o un REA público fuera del flujo
     * que el curador regulariza (spec TASK-067 §3). Nunca un descartado (hay
     * que reabrirlo antes) ni uno ya publicado.
     */
    public static function canPublish(?string $status, bool $isPublic): bool
    {
        if (self::PROPOSED === $status) {
            return true;
        }
        return $isPublic && in_array($status, [null, self::REJECTED], true);
    }

    /** Descartar: lo que aún no se ha publicado ni descartado. */
    public static function canDiscard(?string $status): bool
    {
        return in_array($status, [null, self::PROPOSED, self::REJECTED], true);
    }

    /** Reabrir: solo un descartado vuelve a Propuesto. */
    public static function canReopen(?string $status): bool
    {
        return self::DISCARDED === $status;
    }

    /** Despublicar: un publicado, o cualquier REA público (regularizar, spec §3). */
    public static function canUnpublish(?string $status, bool $isPublic): bool
    {
        return self::PUBLISHED === $status || $isPublic;
    }

    /**
     * Botones que se ofrecen (TASK-067): cada transición exige el privilegio
     * de SU acción y que el estado la permita. Lo comparten la página nativa
     * del item y el panel, para que la UI no ofrezca lo que el ACL deniega.
     *
     * @param callable(string): bool $allowed privilegio de IndexController → ¿concedido?
     * @return array{canPropose:bool, canReject:bool, canPublish:bool, canDiscard:bool,
     *     canUnpublish:bool, canReopen:bool}
     */
    public static function available(?string $status, bool $isPublic, callable $allowed): array
    {
        return [
            'canPropose' => self::canPropose($status) && $allowed('propose'),
            'canReject' => self::canReject($status) && $allowed('reject-proposal'),
            'canPublish' => self::canPublish($status, $isPublic) && $allowed('publish-proposal'),
            'canDiscard' => self::canDiscard($status) && $allowed('discard'),
            'canUnpublish' => self::canUnpublish($status, $isPublic) && $allowed('unpublish'),
            'canReopen' => self::canReopen($status) && $allowed('reopen'),
        ];
    }

    /** ADR-0023 §2: público sin el estado Publicado (TASK-038 se detecta, no se bloquea). */
    public static function isOutOfFlow(?string $status, bool $isPublic): bool
    {
        return $isPublic && self::PUBLISHED !== $status;
    }

    /** Lo que cuenta «Cola (N)»: propuestos y públicos fuera del flujo, sin descartados. */
    public static function inQueue(?string $status, bool $isPublic): bool
    {
        return self::PROPOSED === $status
            || (self::DISCARDED !== $status && self::isOutOfFlow($status, $isPublic));
    }
}
