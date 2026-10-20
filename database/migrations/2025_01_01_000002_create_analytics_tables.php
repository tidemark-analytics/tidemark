<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Same shape as Plausible: one row per *visit* (session) carrying its source, location and device, and one row
 * per event. Visits are what visitors / visits / bounce rate / duration are computed from; events give pageviews and goals.
 */
return new class extends Migration
{
    public function getConnection(): ?string
    {
        return config('tidemark.analytics_connection');
    }

    public function up(): void
    {
        Schema::create('visits', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('site_id');
            $table->char('visitor_id', 32); // salted daily hash of ip + user agent; never reversible
            $table->timestamp('started_at');
            $table->timestamp('ended_at');
            $table->string('entry_page', 500)->nullable();
            $table->string('exit_page', 500)->nullable();
            $table->string('hostname', 100)->nullable();
            $table->string('referrer', 500)->nullable();
            $table->string('source', 150)->nullable();
            $table->string('channel', 24)->default('Direct');
            $table->string('utm_medium')->nullable();
            $table->string('utm_source')->nullable();
            $table->string('utm_campaign')->nullable();
            $table->string('utm_content')->nullable();
            $table->string('utm_term')->nullable();
            $table->char('country', 2)->nullable();
            $table->string('region', 20)->nullable();
            $table->string('city', 100)->nullable();
            $table->string('browser', 40)->nullable();
            $table->string('os', 40)->nullable();
            $table->string('device', 12)->nullable(); // Desktop | Laptop | Tablet | Mobile
            $table->unsignedInteger('pageviews')->default(0);
            $table->unsignedInteger('events')->default(0);
            $table->boolean('is_bounce')->default(true);
            $table->unsignedInteger('duration')->default(0); // seconds

            $table->index(['site_id', 'started_at'], 'vi_site_started');
            $table->index(['site_id', 'visitor_id', 'ended_at'], 'vi_site_visitor');
            $table->index(['site_id', 'source', 'started_at'], 'vi_site_source');
            $table->index(['site_id', 'country', 'started_at'], 'vi_site_country');
        });

        Schema::create('events', function (Blueprint $table) {
            $table->id();
            $table->uuid('site_id');
            $table->uuid('visit_id');
            $table->char('visitor_id', 32);
            $table->string('name', 120); // 'pageview' or a custom event name
            $table->string('pathname', 500)->nullable();
            $table->string('hostname', 100)->nullable();
            $table->timestamp('created_at');

            $table->index(['site_id', 'created_at'], 'ev_site_created');
            $table->index(['site_id', 'name', 'created_at'], 'ev_site_name');
            $table->index(['site_id', 'visit_id'], 'ev_site_visit');
            $table->index(['site_id', 'pathname', 'created_at'], 'ev_site_path');
        });

        Schema::create('event_props', function (Blueprint $table) {
            $table->id();
            $table->uuid('site_id');
            $table->unsignedBigInteger('event_id');
            $table->string('key', 120);
            $table->string('value', 500);

            $table->index(['site_id', 'key'], 'ep_site_key');
            $table->index('event_id', 'ep_event');
        });
    }

    public function down(): void
    {
        foreach (['event_props', 'events', 'visits'] as $t) {
            Schema::dropIfExists($t);
        }
    }
};
