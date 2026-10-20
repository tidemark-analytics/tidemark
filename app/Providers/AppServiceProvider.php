<?php

namespace App\Providers;

use App\Analytics\Ingest\DatabaseSink;
use App\Analytics\Ingest\EventSink;
use App\Analytics\Ingest\QueueSink;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // Swap the sink (queue, ClickHouse, Kafka ...) without touching the collector.
        $this->app->bind(EventSink::class, fn () => config('tidemark.ingest') === 'queue' ? new QueueSink : new DatabaseSink);
    }

    public function boot(): void
    {
        //
    }
}
