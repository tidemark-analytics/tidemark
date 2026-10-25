<?php

namespace App\Contracts;

/**
 * Optional companion to Entitlements: an implementation that also implements this keeps masking of personal data on for every site
 * and hides the setting, for a host that must guarantee it. Without it each site owner chooses (on by default).
 */
interface EnforcesPiiScrubbing
{
    public function enforcesPiiScrubbing(): bool;
}
