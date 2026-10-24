<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('segments', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('site_id')->index();
            $table->uuid('user_id')->nullable(); // who created it; a personal segment is visible to this person only
            $table->string('type', 8)->default('personal'); // personal | site
            $table->string('name', 100);
            $table->text('filters'); // JSON list of filter rules, as in the dashboard's URL
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('segments');
    }
};
