<?php

namespace App\Analytics\Ingest;

/**
 * Where hits go. The default writes to the database; the queue implementation lets a cloud cell absorb traffic spikes.
 * A ClickHouse or Kafka sink can be bound instead without touching the collector.
 */
interface EventSink
{
    /** @param array<string,mixed> $hit a fully resolved hit (see Collector::buildHit) */
    public function record(array $hit): void;
}
