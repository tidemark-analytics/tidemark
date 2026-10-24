<?php

namespace App\Contracts;

use App\Models\User;

/**
 * Optional companion to Entitlements: an implementation that also implements this decides whether a person may create rollups,
 * dashboards that add up several of their sites. Without it everyone may, so self-hosted installs are never limited.
 */
interface AllowsRollups
{
    public function allowsRollups(User $user): bool;
}
