<?php

namespace App\Analytics\Ingest;

use App\Jobs\IngestJob;

/** Defers writes to a queue worker. On shared hosting the scheduler drains it every minute. */
class QueueSink implements EventSink
{
    public function record(array $hit): void
    {
        IngestJob::dispatch($hit)->onQueue(config('tidemark.ingest_queue'));
    }
}
