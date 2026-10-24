<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Revenue sent with an event (`revenue: {amount, currency}`), kept as sent and, when the event is a goal that reports revenue
 * in some currency, converted into that currency so totals add up.
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
            $table->decimal('revenue', 18, 4)->nullable();
            $table->char('currency', 3)->nullable();
            $table->decimal('revenue_reporting', 18, 4)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('events', function (Blueprint $table) {
            $table->dropColumn(['revenue', 'currency', 'revenue_reporting']);
        });
    }
};
