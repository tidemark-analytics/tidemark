<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('annotations', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('site_id');
            $table->uuid('user_id')->nullable(); // who wrote it
            $table->date('date'); // the day it belongs to, in the site's timezone
            $table->string('note', 280);
            $table->string('visibility', 10)->default('team'); // private (only the author), team (everyone on the site) or public (also public and shared views)
            $table->timestamps();
            $table->index(['site_id', 'date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('annotations');
    }
};
