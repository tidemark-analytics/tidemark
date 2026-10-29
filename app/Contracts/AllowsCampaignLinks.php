<?php

namespace App\Contracts;

use App\Models\Site;

/**
 * Optional companion to Entitlements, like AllowsAlerts: an implementation that also implements this decides whether a site may create
 * and count campaign links and tracking pixels. Without it every site may, so self-hosted installs are never limited. Links that already
 * exist keep forwarding either way, so a printed or emailed link never breaks.
 */
interface AllowsCampaignLinks
{
    public function allowsCampaignLinks(Site $site): bool;
}
