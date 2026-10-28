<?php

namespace App\Events;

use App\Models\Organization;
use App\Models\Site;
use App\Models\User;
use Illuminate\Foundation\Events\Dispatchable;

/** Dispatched after a site moves to another organization (its owner row follows when the owner differs). Extensions can listen to it. */
class SiteMoved
{
    use Dispatchable;

    public function __construct(public Site $site, public Organization $from, public Organization $to, public User $by) {}
}
