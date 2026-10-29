<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('data_deletions', function (Blueprint $table) {
            $table->id();
            $table->uuid('site_id');
            $table->uuid('user_id')->nullable();
            $table->string('user_name', 120)->nullable(); // kept so the log still reads right after an account is removed
            $table->json('filters');
            $table->date('date_from')->nullable();
            $table->date('date_to')->nullable();
            $table->unsignedInteger('visits');
            $table->unsignedInteger('events');
            $table->timestamp('created_at');

            $table->index(['site_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('data_deletions');
    }
};
