<?php

namespace App\Contracts;

use App\Models\User;

/**
 * Decides what an account may do. Self-hosted installs use DefaultEntitlements (only the optional site cap from the .env file);
 * an extension package can bind its own implementation, for example to enforce plans and monthly event quotas.
 * Bind it with `$this->app->singleton(Entitlements::class, …)` in the package's service provider.
 */
interface Entitlements
{
    /** True when the user may add another site, otherwise a message explaining why not (shown to the user). */
    public function canCreateSite(User $user): true|string;

    /**
     * Called for every accepted event, so it must be fast (use a cache). Return false to drop the event silently; the
     * tracker still gets "202 ok" so visitors' browsers never see an error.
     *
     * @param  array{id:string,domain:string,owner_id:?string,organization_id:?string}  $site
     */
    public function allowsRecording(array $site): bool;
}
