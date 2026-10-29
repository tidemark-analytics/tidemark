<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('campaign_links', function (Blueprint $table) {
            $table->id();
            $table->uuid('site_id');
            $table->string('slug', 16)->unique();
            $table->string('kind', 8); // link (a short redirect) or pixel
            $table->string('name', 120);
            $table->string('destination', 1000)->nullable(); // a pixel has none
            $table->string('utm_source')->nullable();
            $table->string('utm_medium')->nullable();
            $table->string('utm_campaign')->nullable();
            $table->string('utm_content')->nullable();
            $table->string('utm_term')->nullable();
            $table->timestamps();

            $table->index(['site_id', 'kind']);
        });

        // Daily totals kept apart from visits: a link click or a pixel view is counted even when the visit behind it is filtered out.
        Schema::create('campaign_link_counts', function (Blueprint $table) {
            $table->unsignedBigInteger('link_id');
            $table->date('day');
            $table->unsignedInteger('hits')->default(0);

            $table->primary(['link_id', 'day']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('campaign_link_counts');
        Schema::dropIfExists('campaign_links');
    }
};
