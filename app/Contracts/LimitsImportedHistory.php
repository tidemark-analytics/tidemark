<?php

namespace App\Contracts;

use App\Models\Site;

/**
 * Optional companion to Entitlements: an implementation that also implements this can limit how far back an import may reach.
 */
interface LimitsImportedHistory
{
    /** The oldest data an import into this site may keep (older visits are skipped), or null for no limit. */
    public function importCutoff(Site $site): ?\DateTimeInterface;
}
