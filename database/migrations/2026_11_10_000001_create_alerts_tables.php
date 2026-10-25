<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('alert_webhooks', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('site_id')->index();
            $table->string('name', 80);
            $table->string('kind', 10)->default('generic'); // generic (signed JSON), slack or discord
            $table->text('url'); // encrypted: chat webhook URLs are secrets
            $table->text('secret')->nullable(); // encrypted, signs generic payloads
            $table->unsignedSmallInteger('last_status')->nullable(); // HTTP status of the latest delivery, 0 when it never connected
            $table->string('last_error', 200)->nullable();
            $table->timestamp('last_at')->nullable();
            $table->timestamps();
        });

        Schema::create('alert_rules', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('site_id')->index();
            $table->string('type', 30);
            $table->json('config');
            $table->boolean('email')->default(false);
            $table->json('webhook_ids');
            $table->string('quiet_start', 5)->nullable(); // HH:MM in the site's timezone; nothing is sent between start and end
            $table->string('quiet_end', 5)->nullable();
            $table->boolean('enabled')->default(true);
            $table->json('state')->nullable(); // what the rule remembers between runs (counts so far today, sources already announced…)
            $table->timestamp('last_fired_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('alert_rules');
        Schema::dropIfExists('alert_webhooks');
    }
};
