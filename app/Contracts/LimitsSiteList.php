<?php

namespace App\Contracts;

use App\Models\User;

/**
 * Optional companion to Entitlements. Administrators normally see every site on the installation in their site list and site
 * switcher. An implementation of this makes the list show only the sites the user belongs to, and says where the rest can be
 * browsed instead (a path in the dashboard, such as an extension's account tab). Nothing else changes: administrators can still
 * open any site by its address.
 */
interface LimitsSiteList
{
    /** The path to browse the other sites at, or null to keep the usual list. */
    public function otherSitesPath(User $user): ?string;
}
