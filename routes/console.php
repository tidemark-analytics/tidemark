<?php

use App\Console\Commands\PruneEvents;
use Illuminate\Support\Facades\Schedule;

// Shared hosting needs one cron entry:  * * * * * php /path/to/artisan schedule:run
Schedule::command('queue:work --stop-when-empty --max-time=50 --queue='.config('tidemark.ingest_queue').',default')
    ->everyMinute()->withoutOverlapping()->when(fn () => config('tidemark.ingest') === 'queue');
Schedule::command(PruneEvents::class)->dailyAt('03:15')->when(fn () => config('tidemark.retention_days') > 0);
Schedule::command('cache:prune-stale-tags')->hourly()->when(fn () => config('cache.default') === 'redis');
