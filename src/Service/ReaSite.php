<?php

declare(strict_types=1);

namespace OERManager\Service;

use Omeka\Api\Manager as ApiManager;
use Omeka\Settings\Settings;

/**
 * The REA site (TASK-067, ADR-0023 §1.4): every REA belongs to it and the team
 * holds a permission on it, so a REA outside it is one the team could not edit
 * under IsolatedSites. Configured by id (`oermanager_rea_site_id`); while it is
 * empty, or points at a site that no longer exists, the rule is not evaluated.
 *
 * Cost: one read of the site and one id-only search of its items per request,
 * cached here — the same pattern as the retired-term ids (TASK-064). Never a
 * query per row. Under isolation the search only sees the user's granted
 * sites: a curator without a permission on the REA site sees every REA
 * flagged, which is the configuration error the warning is for.
 *
 * Not final so the integrity tests can stand it in.
 */
class ReaSite
{
    public const SETTING = 'oermanager_rea_site_id';

    /** @var array<int, true>|null */
    private ?array $members = null;

    private bool $resolved = false;

    public function __construct(
        private readonly ApiManager $api,
        private readonly Settings $settings
    ) {
    }

    public function siteId(): ?int
    {
        return GovernanceSettings::parseId($this->settings->get(self::SETTING));
    }

    /** Whether a site with this id exists (used when saving the configuration). */
    public function exists(int $siteId): bool
    {
        try {
            $this->api->read('sites', $siteId);
            return true;
        } catch (\Exception $e) {
            return false;
        }
    }

    /** true/false, or null when the rule is not evaluated (no valid REA site). */
    public function contains(int $itemId): ?bool
    {
        if (!$this->resolved) {
            $this->resolved = true;
            $siteId = $this->siteId();
            if (null !== $siteId && $this->exists($siteId)) {
                $ids = (array) $this->api->search('items', ['site_id' => $siteId], ['returnScalar' => 'id'])
                    ->getContent();
                $this->members = array_fill_keys(array_map('intval', array_keys($ids)), true);
            }
        }
        return null === $this->members ? null : isset($this->members[$itemId]);
    }
}
