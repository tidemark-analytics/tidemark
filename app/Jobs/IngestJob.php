<?php

namespace App\Jobs;

use App\Analytics\Ingest\DatabaseSink;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class IngestJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    /** @param array<string,mixed> $hit */
    public function __construct(public array $hit) {}

    public function handle(DatabaseSink $sink): void
    {
        $sink->record($this->hit);
    }
}
