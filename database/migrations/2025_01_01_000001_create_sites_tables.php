<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sites', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('domain')->unique();
            $table->string('timezone', 64)->default('UTC');
            $table->boolean('public')->default(false);
            // default tracking extensions (they change the snippet's script name and create matching goals)
            $table->boolean('track_outbound')->default(false);
            $table->boolean('track_downloads')->default(false);
            $table->boolean('track_forms')->default(false);
            $table->timestamps();
        });

        Schema::create('site_user', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('site_id');
            $table->uuid('user_id');
            $table->string('role', 12)->default('viewer'); // owner | admin | viewer
            $table->timestamps();
            $table->unique(['site_id', 'user_id']);
            $table->index('user_id');
        });

        Schema::create('goals', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('site_id')->index();
            $table->string('type', 8); // event | page
            $table->string('value');   // event name, or a page path (supports * and **)
            $table->timestamps();
            $table->unique(['site_id', 'type', 'value']);
        });

        Schema::create('shared_links', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('site_id')->index();
            $table->string('name');
            $table->string('slug', 40)->unique();
            $table->string('password_hash')->nullable();
            $table->timestamps();
        });

        Schema::create('shield_rules', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('site_id')->index();
            $table->string('type', 10); // ip | country | page | hostname
            $table->string('value');
            $table->timestamps();
            $table->unique(['site_id', 'type', 'value']);
        });

        Schema::create('api_keys', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('user_id')->index();
            $table->string('name');
            $table->string('token_hash', 64)->unique();
            $table->string('prefix', 12);
            $table->timestamp('last_used_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        foreach (['api_keys', 'shield_rules', 'shared_links', 'goals', 'site_user', 'sites'] as $t) {
            Schema::dropIfExists($t);
        }
    }
};
