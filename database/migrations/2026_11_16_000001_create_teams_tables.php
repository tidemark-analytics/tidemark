<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('teams', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('organization_id')->index(); // the organization that owns the team
            $table->string('name', 80);
            $table->timestamps();
            $table->unique(['organization_id', 'name']);
        });

        Schema::create('team_user', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('team_id');
            $table->uuid('user_id');
            $table->timestamps();
            $table->unique(['team_id', 'user_id']);
            $table->index('user_id');
        });

        // A team has no role of its own: each site says what that team may do there.
        Schema::create('site_team', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('site_id');
            $table->uuid('team_id');
            $table->string('role', 12)->default('viewer'); // admin | viewer
            $table->timestamps();
            $table->unique(['site_id', 'team_id']);
            $table->index('team_id');
        });

        Schema::table('invitations', function (Blueprint $table) {
            $table->uuid('team_id')->nullable()->index();
        });
    }

    public function down(): void
    {
        Schema::table('invitations', fn (Blueprint $t) => $t->dropColumn('team_id'));
        foreach (['site_team', 'team_user', 'teams'] as $t) {
            Schema::dropIfExists($t);
        }
    }
};
