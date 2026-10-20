<?php

namespace App\Console\Commands;

use App\Analytics\Support\Sql;
use Illuminate\Console\Command;

class PruneEvents extends Command
{
    protected $signature = 'tidemark:prune {--days= : Override TIDEMARK_RETENTION_DAYS}';

    protected $description = 'Delete raw analytics rows older than the retention period';

    public function handle(): int
    {
        $days = (int) ($this->option('days') ?: config('tidemark.retention_days'));
        if ($days <= 0) {
            $this->info('Retention is unlimited; nothing to prune.');

            return self::SUCCESS;
        }
        $cutoff = now()->subDays($days)->format('Y-m-d H:i:s');
        $db = Sql::connection();
        $total = 0;
        // Delete in slices so a big backlog never holds one long lock.
        do {
            $ids = $db->table('events')->where('created_at', '<', $cutoff)->limit(5000)->pluck('id');
            if ($ids->isEmpty()) {
                break;
            }
            $db->table('event_props')->whereIn('event_id', $ids)->delete();
            $total += $db->table('events')->whereIn('id', $ids)->delete();
        } while (true);
        do {
            $ids = $db->table('visits')->where('ended_at', '<', $cutoff)->limit(5000)->pluck('id');
            if ($ids->isEmpty()) {
                break;
            }
            $total += $db->table('visits')->whereIn('id', $ids)->delete();
        } while (true);
        $this->info("Pruned {$total} rows older than {$days} days.");

        return self::SUCCESS;
    }
}
