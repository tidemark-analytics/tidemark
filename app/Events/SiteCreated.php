<?php

namespace App\Events;

use App\Models\Site;
use App\Models\User;
use Illuminate\Foundation\Events\Dispatchable;

/** Dispatched after a user adds a site. Extensions can listen to it. */
class SiteCreated
{
    use Dispatchable;

    public function __construct(public Site $site, public User $owner) {}
}
