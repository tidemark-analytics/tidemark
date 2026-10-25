<?php

namespace Tests\Feature;

use Illuminate\Console\Scheduling\Schedule;
use Tests\TestCase;

/** The one cron entry (`schedule:run`) has to be enough: queued work gets a worker, and the report, alert and cleanup tasks are on the schedule. */
class SchedulerTest extends TestCase
{
    private function worker(): ?object
    {
        return collect(app(Schedule::class)->events())->first(fn ($e) => str_contains($e->command, 'queue:work'));
    }

    public function test_queued_work_gets_a_worker_unless_the_queue_is_synchronous(): void
    {
        config(['queue.default' => 'sync', 'tidemark.ingest' => 'sync']);
        $this->assertFalse($this->worker()->filtersPass($this->app), 'nothing is queued, so nothing to run');

        config(['queue.default' => 'database']); // the default of a fresh install: alert webhooks are queued
        $this->assertTrue($this->worker()->filtersPass($this->app));

        config(['queue.default' => 'sync', 'tidemark.ingest' => 'queue']);
        $this->assertTrue($this->worker()->filtersPass($this->app), 'buffered events still get their worker');
    }

    public function test_the_report_and_alert_commands_are_scheduled(): void
    {
        $commands = collect(app(Schedule::class)->events())->map(fn ($e) => $e->command)->implode("\n");
        foreach (['reports:send', 'alerts:run'] as $name) {
            $this->assertStringContainsString($name, $commands);
        }
    }
}
