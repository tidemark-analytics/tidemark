<?php

namespace App\Support;

use App\Contracts\Entitlements;
use App\Models\User;

class DefaultEntitlements implements Entitlements
{
    public function canCreateSite(User $user): true|string
    {
        $limit = (int) config('tidemark.limits.sites_per_user');
        if ($limit > 0 && ! $user->is_admin && $user->sites()->wherePivot('role', 'owner')->count() >= $limit) {
            return "Your plan allows up to {$limit} sites.";
        }

        return true;
    }

    public function allowsRecording(array $site): bool
    {
        return true;
    }
}
