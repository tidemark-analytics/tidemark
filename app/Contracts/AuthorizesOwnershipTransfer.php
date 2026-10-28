<?php

namespace App\Contracts;

use App\Models\Organization;
use App\Models\User;

/**
 * Optional companion to Entitlements: an implementation that also implements this decides whether an organization may change owner
 * (for example because the plan and billing have to move with it). Without it, owners can always hand an organization to one of its admins.
 */
interface AuthorizesOwnershipTransfer
{
    /** True when the transfer may go ahead, otherwise the reason to show. */
    public function allowsOwnershipTransfer(Organization $organization, User $to): true|string;
}
