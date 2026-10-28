<?php

namespace App\Console\Commands;

use App\Models\Invitation;
use Illuminate\Console\Command;

class PruneInvitations extends Command
{
    protected $signature = 'tidemark:prune-invitations';

    protected $description = 'Delete expired invitations, so an invited e-mail address is not kept longer than the invitation lasts';

    public function handle(): int
    {
        $n = Invitation::where('expires_at', '<', now())->delete();
        $this->info("Deleted {$n} expired invitation".($n === 1 ? '' : 's').'.');

        return self::SUCCESS;
    }
}
