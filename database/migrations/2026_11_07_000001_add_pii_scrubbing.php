<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sites', function (Blueprint $table) {
            $table->boolean('pii_scrub')->default(true);
        });
        // how many values were masked per day and kind of pattern; never the values themselves
        Schema::create('pii_masks', function (Blueprint $table) {
            $table->id();
            $table->uuid('site_id');
            $table->date('day');
            $table->string('pattern', 20);
            $table->unsignedInteger('count')->default(0);
            $table->unique(['site_id', 'day', 'pattern']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pii_masks');
        Schema::table('sites', function (Blueprint $table) {
            $table->dropColumn('pii_scrub');
        });
    }
};
