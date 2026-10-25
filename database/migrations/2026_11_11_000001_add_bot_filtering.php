<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sites', function (Blueprint $table) {
            $table->boolean('bot_filter')->default(true);
        });
        // how many hits were left out per day and reason; never anything about who sent them
        Schema::create('filtered_hits', function (Blueprint $table) {
            $table->id();
            $table->uuid('site_id');
            $table->date('day');
            $table->string('reason', 20);
            $table->unsignedInteger('count')->default(0);
            $table->unique(['site_id', 'day', 'reason']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('filtered_hits');
        Schema::table('sites', function (Blueprint $table) {
            $table->dropColumn('bot_filter');
        });
    }
};
