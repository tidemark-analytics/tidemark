<?php

use App\Console\Commands\CheckUpdates;
use App\Console\Commands\PruneEvents;
use App\Console\Commands\RefreshExchangeRates;
use App\Console\Commands\RunAlerts;
use App\Console\Commands\SendReports;
use Illuminate\Support\Facades\Schedule;

// Shared hosting needs one cron entry:  * * * * * php /path/to/artisan schedule:run
// Queued work (buffered events, alert webhook deliveries) is picked up by the scheduler too, so one cron entry is all a shared host needs.
Schedule::command('queue:work --stop-when-empty --max-time=50 --queue='.config('tidemark.ingest_queue').',default')
    ->everyMinute()->withoutOverlapping()->when(fn () => config('tidemark.ingest') === 'queue' || config('queue.default') !== 'sync');
Schedule::command(PruneEvents::class)->dailyAt('03:15')->when(fn () => config('tidemark.retention_days') > 0);
Schedule::command('cache:prune-stale-tags')->hourly()->when(fn () => config('cache.default') === 'redis');
// Looks for a new release once a day, at a minute derived from the install's key so installs don't all call GitHub together.
Schedule::command(CheckUpdates::class)->dailyAt('04:'.str_pad((string) (crc32((string) config('app.key')) % 60), 2, '0', STR_PAD_LEFT))->when(fn () => config('tidemark.update_check'));
Schedule::command(RefreshExchangeRates::class)->dailyAt('16:30');
// E-mail reports go out at 08:00 in each site's timezone, so look for due ones every hour (and catch up after downtime).
Schedule::command(SendReports::class)->hourly()->withoutOverlapping();
// Alert rules are checked every five minutes.
Schedule::command(RunAlerts::class)->everyFiveMinutes()->withoutOverlapping();
