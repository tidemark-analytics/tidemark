<?php

namespace App\Contracts;

use App\Models\Organization;
use App\Models\Site;

/**
 * Optional companion to Entitlements for installs that give each organization its own plan (Tidemark Cloud). When the bound implementation
 * also implements this, adding a site, and moving one, are judged against the organization the site goes into rather than against its
 * owner as a person. Without it nothing changes: self-hosted installs have no plans.
 */
interface ScopesToOrganizations
{
    /** True when the organization may have another site, otherwise the message to show. */
    public function canCreateSiteIn(Organization $organization): true|string;

    /**
     * What moving the site into $to would cost it that the person should read first (for example data older than the new plan keeps would
     * be removed). Plain sentences; empty when there is nothing to warn about.
     *
     * @return array<int,string>
     */
    public function moveWarnings(Site $site, Organization $to): array;
}
