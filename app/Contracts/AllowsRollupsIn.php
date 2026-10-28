<?php

namespace App\Contracts;

use App\Models\Organization;

/**
 * Optional companion to AllowsRollups for an extension that gives each organization its own plan: rollups belong to an organization,
 * so whether one may be created is decided for that organization rather than for the person.
 */
interface AllowsRollupsIn
{
    public function allowsRollupsIn(Organization $organization): bool;
}
