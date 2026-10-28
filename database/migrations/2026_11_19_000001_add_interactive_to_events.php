<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Whether an event was an interaction. Events sent with `i: 0` (scroll depth and time on page) are measurements of a page view, not something
 * a visitor did, so a hosted service can leave them out of the events it meters.
 */
return new class extends Migration
{
    public function getConnection(): ?string
    {
        return config('tidemark.analytics_connection');
    }

    public function up(): void
    {
        Schema::table('events', function (Blueprint $table) {
            $table->boolean('interactive')->default(true);
        });
    }

    public function down(): void
    {
        Schema::table('events', function (Blueprint $table) {
            $table->dropColumn('interactive');
        });
    }
};
