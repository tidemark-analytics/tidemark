<?php

namespace App\Contracts;

use App\Models\Site;

/**
 * Optional companion to Entitlements: an implementation that also implements this decides whether a site may use its own
 * logo, name and color on its public pages. Without it every site may, so self-hosted installs are never limited.
 */
interface AllowsBranding
{
    public function allowsBranding(Site $site): bool;
}
