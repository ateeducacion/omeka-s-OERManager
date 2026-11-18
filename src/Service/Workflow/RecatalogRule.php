<?php

declare(strict_types=1);

namespace OERManager\Service\Workflow;

use OERManager\Permissions\ReaAcl;

/**
 * Who may re-catalogue a REA — curricular alignment and thematic axes — by
 * workflow state and role (owner decision of 2026-11-18, ADR-0023 addendum;
 * TASK-035 spec §2). Pure: the controller applies it on every curricular write
 * of the panel and the panel uses the same answer to decide whether to offer
 * the editor, so the UI never offers what the server refuses.
 *
 * | State                         | curator / global_admin | proposer (own REA) |
 * | Borrador (no status, private) | yes                    | yes                |
 * | Propuesto                     | yes                    | no                 |
 * | Rechazado (private)           | yes                    | yes                |
 * | Publicado                     | yes (audited, undoable)| no                 |
 * | Out of flow (public)          | yes (regularise)       | no                 |
 * | Descartado                    | no (until «Reabrir»)   | no                 |
 *
 * It only narrows: the native API still decides each write item by item.
 */
final class RecatalogRule
{
    public const CURATOR = 'curator';
    public const PROPOSER = 'proposer';

    /** Refusal codes, in the order they are checked. */
    public const ROLE = 'role';
    public const DISCARDED = 'discarded';
    public const NO_UPDATE = 'no_update';
    public const NOT_OWNER = 'not_owner';
    public const STATUS = 'status';

    /** @var array<string, string> refusal code => message */
    public const MESSAGES = [
        self::ROLE => 'Tu perfil no permite re-catalogar REA.', // @translate
        self::DISCARDED => 'REA descartado: no se puede re-catalogar hasta reabrirlo.', // @translate
        self::NO_UPDATE => 'No tienes permiso para editar este REA.', // @translate
        self::NOT_OWNER => 'Solo puedes re-catalogar los REA de los que eres propietario.', // @translate
        // phpcs:ignore Generic.Files.LineLength.TooLong -- one msgid for the catalogue
        self::STATUS => 'Solo puedes re-catalogar tus REA en borrador o rechazados; en este estado lo hace el equipo curador.', // @translate
    ];

    /** Profile of a user role for this rule, or null if the role does not re-catalogue. */
    public static function profile(?string $role): ?string
    {
        return match ($role) {
            'global_admin', ReaAcl::CURATOR => self::CURATOR,
            ReaAcl::PROPOSER => self::PROPOSER,
            default => null,
        };
    }

    /**
     * Null when the user may re-catalogue the item, otherwise the refusal code.
     *
     * @param bool $isOwner the user owns the item
     * @param bool $canUpdate the user holds the native `update` permission on the item
     */
    public static function denial(
        ?string $status,
        bool $isPublic,
        ?string $profile,
        bool $isOwner,
        bool $canUpdate
    ): ?string {
        if (null === $profile) {
            return self::ROLE;
        }
        if (WorkflowStatus::DISCARDED === $status) {
            return self::DISCARDED;
        }
        if (!$canUpdate) {
            return self::NO_UPDATE;
        }
        if (self::CURATOR === $profile) {
            return null;
        }
        if (!$isOwner) {
            return self::NOT_OWNER;
        }
        // Private Borrador or Rechazado: the states in which the author may propose.
        return WorkflowStatus::canPropose($status, $isPublic) ? null : self::STATUS;
    }

    /** Message of a refusal code; empty for null or an unknown code. */
    public static function message(?string $code): string
    {
        return null === $code ? '' : (self::MESSAGES[$code] ?? '');
    }
}
