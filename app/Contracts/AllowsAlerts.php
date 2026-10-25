<?php

namespace App\Contracts;

use App\Models\Site;

/**
 * Optional companion to Entitlements: an implementation that also implements this decides whether a site may use alert rules and
 * webhooks. Without it every site may, so self-hosted installs are never limited.
 */
interface AllowsAlerts
{
    public function allowsAlerts(Site $site): bool;
}
