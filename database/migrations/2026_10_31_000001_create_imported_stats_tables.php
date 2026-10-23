<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Daily totals imported from another analytics tool (Plausible's CSV export). They are kept as aggregates, like the tool
 * itself keeps them, instead of being turned into made-up visits: a site with years of history would otherwise gain millions of rows.
 * Rows are only ever added and summed, so the same date may appear more than once.
 */
return new class extends Migration
{
    public function getConnection(): ?string
    {
        return config('tidemark.analytics_connection');
    }

    public function up(): void
    {
        Schema::create('imported_visitors', function (Blueprint $table) {
            $table->id();
            $table->uuid('site_id');
            $table->date('date'); // the site's own calendar day
            $table->unsignedBigInteger('visitors')->default(0);
            $table->unsignedBigInteger('visits')->default(0);
            $table->unsignedBigInteger('pageviews')->default(0);
            $table->unsignedBigInteger('bounces')->default(0);
            $table->unsignedBigInteger('duration')->default(0); // seconds, summed over visits

            $table->index(['site_id', 'date'], 'iv_site_date');
        });

        Schema::create('imported_breakdowns', function (Blueprint $table) {
            $table->id();
            $table->uuid('site_id');
            $table->date('date');
            $table->string('dim', 16); // a dashboard dimension (source, country, page, entry_page, ...) or `event` for custom events
            $table->string('name', 500)->default('');
            $table->unsignedBigInteger('visitors')->default(0);
            $table->unsignedBigInteger('visits')->default(0); // entrances for entry pages, exits for exit pages, events for `event`
            $table->unsignedBigInteger('pageviews')->default(0);
            $table->unsignedBigInteger('bounces')->default(0);
            $table->unsignedBigInteger('duration')->default(0);

            $table->index(['site_id', 'dim', 'date'], 'ib_site_dim_date');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('imported_breakdowns');
        Schema::dropIfExists('imported_visitors');
    }
};
