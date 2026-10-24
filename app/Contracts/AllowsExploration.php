<?php

namespace App\Contracts;

use App\Models\Site;

/**
 * Optional companion to Entitlements, like AllowsConversionReports: an implementation that also implements this decides whether a
 * site may use saved segments and the journeys explorer. Without it every site may, so self-hosted installs are never limited.
 */
interface AllowsExploration
{
    public function allowsSegments(Site $site): bool;

    public function allowsJourneys(Site $site): bool;
}
