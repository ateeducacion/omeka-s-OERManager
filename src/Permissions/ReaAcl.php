<?php

declare(strict_types=1);

namespace OERManager\Permissions;

use OERManager\Controller\Admin\GovernanceBatchController;
use OERManager\Controller\Admin\IndexController;
use OERManager\Controller\Admin\StatsController;

/**
 * REA roles and OERManager's whole ACL (TASK-067, ADR-0023 §1).
 *
 * OERManager grants its actions only to `rea_proponente`, `rea_curador` and
 * `global_admin` (which the core allows everything). `author`, `editor`,
 * `reviewer`, `site_admin` and `site_editor` get nothing from this module, so
 * what those native roles mean in an 818-site instance no longer matters.
 *
 * The grants are per privilege, never per controller (TASK-029): a new action
 * is unreachable until it is listed here, and `ReaAclTest` fails until it is
 * decided (granted to a role or listed in ADMIN_ONLY).
 *
 * Privileges are the action names as the router dispatches them, with dashes
 * (`Mvc\MvcListeners::authorizeUserAgainstController`). Who may touch EACH
 * item is not decided here: every write goes through the API, whose native
 * ACL (IsolatedSites' per-site denial included) decides item by item.
 *
 * Plain array data on purpose: Module::onBootstrap() and the uninstall read
 * it, and the host tests pin it.
 */
final class ReaAcl
{
    public const PROPOSER = 'rea_proponente';
    public const CURATOR = 'rea_curador';

    /** Added by IsolatedSites (parent `editor`); the parent of both REA roles when present. */
    public const SITE_EDITOR = 'site_editor';

    /** @var array<string, string> role => label */
    public const LABELS = [
        self::PROPOSER => 'Proponente REA', // @translate
        self::CURATOR => 'Curador REA', // @translate
    ];

    /**
     * Parents when IsolatedSites is not installed: the least native role with
     * which each profile still works. A proposer creates and edits their own
     * items (`author`, OwnsEntityAssertion); a curator edits any REA (`editor`).
     */
    public const FALLBACK_PARENTS = [
        self::PROPOSER => 'author',
        self::CURATOR => 'editor',
    ];

    /** @var array<string, array<string, list<string>>> controller => role => privileges */
    public const GRANTS = [
        IndexController::class => [
            self::PROPOSER => [
                'propose',
                // ADR-0023 §1: their own REA, without the master view.
                'my-proposals',
                // TASK-035 §3 (owner decision 2026-11-18): the detail panel,
                // opened from «Mis propuestas», and the re-cataloguer. Which
                // REA and in which state is RecatalogRule's call (their own,
                // private, Borrador or Rechazado); the API decides each write.
                // The panel reads (drawer-details, drawer-history) answer only
                // for REA they own (RecatalogRule::readDenied, security
                // review); search-terms and curriculum-tree read only the
                // curriculum. No AI, governance, visibility, batches or statistics.
                'drawer-details',
                'drawer-history',
                'search-terms',
                'curriculum-tree',
                'recatalog-preview',
                'recatalog-apply',
                'recatalog-last-event',
                'recatalog-undo',
            ],
            self::CURATOR => [
                // Master view and its reads.
                'index',
                'search',
                'search-terms',
                // TASK-064 slice 4: knowledge/criteria tree, read only.
                'curriculum-tree',
                'drawer-details',
                'drawer-history',
                // Re-cataloguer and governance (RF-004, RF-005, RF-015).
                'recatalog-preview',
                'recatalog-apply',
                'governance-apply',
                'recatalog-last-event',
                'recatalog-undo',
                // AI proposal (single); the batch one is TASK-011.
                'ai-propose',
                'ai-propose-status',
                'ai-propose-cancel',
                'ai-evaluate',
                // Visibility escape hatch, accepted by the owner (ADR-0018 addendum).
                'set-visibility',
                // Workflow. `propose` too: one global role per user, and team
                // members both propose and curate (spec §2, for the owner to confirm).
                'propose',
                'reject-proposal',
                'publish-proposal',
                // ADR-0023 §2 (TASK-067).
                'discard',
                'unpublish',
                'reopen',
            ],
        ],
        StatsController::class => [
            self::CURATOR => ['index', 'export', 'layout'],
        ],
        // Licence and authorship batch (TASK-028 slice 4): the curator's own batches.
        GovernanceBatchController::class => [
            self::CURATOR => ['form', 'preview', 'apply', 'status', 'cancel', 'recent', 'undo'],
        ],
    ];

    /**
     * Native roles the core allows everything (`AclFactory::addRulesForSiteAdmin`
     * runs `allow('site_admin')`). ADR-0023 §1.2 leaves them out of OERManager,
     * so they are denied the module's controllers explicitly; `global_admin` is
     * not listed and keeps everything.
     */
    public const DENIED_ALLOW_ALL_ROLES = ['site_admin'];

    /**
     * Decided and granted to nobody: only `global_admin` holds them. The
     * configuration carries the LLM key and the catalogue's vocabularies;
     * undoing someone else's batch is for when its author is away.
     */
    public const ADMIN_ONLY = [
        IndexController::class => ['config'],
        GovernanceBatchController::class => [GovernanceBatchController::PRIVILEGE_UNDO_ANY],
    ];

    /**
     * Adds the roles and the grants to Omeka's ACL.
     *
     * The parent must already exist: IsolatedSites adds `site_editor` in its
     * own onBootstrap(), and the core's `Module\Manager::sortModules()` ksorts
     * module ids, so IsolatedSites bootstraps before OERManager. Without it the
     * fallback parents apply.
     *
     * @param \Omeka\Permissions\Acl $acl
     */
    public static function register(object $acl): void
    {
        $hasSiteEditor = $acl->hasRole(self::SITE_EDITOR);
        foreach (self::LABELS as $role => $label) {
            if (!$acl->hasRole($role)) {
                $acl->addRole($role, self::parentFor($role, $hasSiteEditor));
            }
            $acl->addRoleLabel($role, $label);
        }
        foreach (self::GRANTS as $resource => $byRole) {
            foreach ($byRole as $role => $privileges) {
                $acl->allow($role, $resource, $privileges);
            }
        }
        $acl->deny(self::DENIED_ALLOW_ALL_ROLES, array_keys(self::GRANTS));
    }

    public static function parentFor(string $role, bool $hasSiteEditor): string
    {
        return $hasSiteEditor ? self::SITE_EDITOR : self::FALLBACK_PARENTS[$role];
    }

    /**
     * Role each REA role's users get back on uninstall (ADR-0023 §1.6), so
     * nobody is left with a role the ACL no longer knows.
     *
     * @return array<string, string> REA role => role to move its users to
     */
    public static function uninstallTargets(bool $hasSiteEditor): array
    {
        $targets = [];
        foreach (array_keys(self::LABELS) as $role) {
            $targets[$role] = self::parentFor($role, $hasSiteEditor);
        }
        return $targets;
    }
}
