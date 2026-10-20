<?php

namespace App\Console\Commands;

use App\Updates\UpdateException;
use App\Updates\Updater;
use Illuminate\Console\Command;

class CheckUpdates extends Command
{
    protected $signature = 'tidemark:check-updates';

    protected $description = 'Check GitHub for a newer Tidemark release, so the dashboard can show an update indicator';

    public function handle(Updater $updater): int
    {
        if (! config('tidemark.update_check')) {
            $this->info('Update checks are turned off (TIDEMARK_UPDATE_CHECK=false).');

            return self::SUCCESS;
        }
        try {
            $latest = $updater->latest(true);
        } catch (UpdateException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }
        $this->info($latest['newer'] ? "Update available: {$latest['version']} (installed: {$latest['current']})." : "Up to date ({$latest['current']}).");

        return self::SUCCESS;
    }
}
