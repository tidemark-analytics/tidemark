<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('report_subscriptions', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('user_id');
            $table->uuid('site_id');
            $table->string('frequency', 7); // weekly or monthly
            $table->string('last_period_end', 10)->nullable(); // the last day (site timezone) of the newest report sent, so each period is sent once
            $table->string('token', 40)->unique(); // for the unsubscribe link in each email
            $table->timestamps();
            $table->unique(['user_id', 'site_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('report_subscriptions');
    }
};
