<?php

namespace App\Contracts;

use App\Models\Site;

/**
 * Optional companion to Entitlements: an implementation that also implements this decides whether a site may use funnels and
 * revenue reporting. Without it every site may, so self-hosted installs are never limited. Events keep their revenue either
 * way, so upgrading later shows the history.
 */
interface AllowsConversionReports
{
    public function allowsFunnels(Site $site): bool;

    public function allowsRevenue(Site $site): bool;
}
