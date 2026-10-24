<?php

namespace App\Contracts;

use App\Models\User;

/**
 * Optional companion to Entitlements. Administrators normally see every site on the installation in their site list and site
 * switcher. An implementation of this makes the list show only the sites the user belongs to, and says where the rest can be
 * browsed instead (a path, such as an extension's own interface). An empty path keeps the list to the user's own sites without mentioning the others at all. Nothing else changes: administrators can still
 * open any site by its address.
 */
interface LimitsSiteList
{
    /** The path to browse the other sites at, an empty string to show only the user's own sites with no hint of others, or null to keep the usual list. */
    public function otherSitesPath(User $user): ?string;
}
