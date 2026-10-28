<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    public function up(): void
    {
        // An organization has a name, one owner and any number of admins; sites and teams belong to it. Each person also has a Personal one.
        Schema::create('organizations', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('name', 100);
            $table->uuid('owner_id')->index();
            $table->boolean('personal')->default(false); // every person has exactly one: just theirs, never shared, never deleted
            $table->uuid('transfer_to')->nullable(); // an admin the owner offered ownership to; they accept (or not) before it expires
            $table->timestamp('transfer_expires_at')->nullable();
            $table->timestamps();
        });

        Schema::create('organization_user', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('organization_id');
            $table->uuid('user_id');
            $table->timestamps();
            $table->unique(['organization_id', 'user_id']);
            $table->index('user_id');
        });

        Schema::table('sites', function (Blueprint $table) {
            $table->uuid('organization_id')->nullable()->index();
        });

        Schema::table('invitations', function (Blueprint $table) {
            $table->uuid('organization_id')->nullable()->index(); // the organization the person is invited to help manage
        });

        // Everyone gets a Personal organization, and the sites they own move into it.
        foreach (DB::table('users')->pluck('id') as $userId) {
            $id = (string) Str::uuid();
            DB::table('organizations')->insert(['id' => $id, 'name' => 'Personal', 'owner_id' => $userId, 'personal' => true, 'created_at' => now(), 'updated_at' => now()]);
            DB::table('sites')->whereIn('id', DB::table('site_user')->where('user_id', $userId)->where('role', 'owner')->select('site_id'))->where('kind', '!=', 'rollup')->update(['organization_id' => $id]);
        }
    }

    public function down(): void
    {
        foreach (['invitations', 'sites'] as $table) {
            Schema::table($table, fn (Blueprint $t) => $t->dropIndex(['organization_id']));
            Schema::table($table, fn (Blueprint $t) => $t->dropColumn('organization_id'));
        }
        Schema::dropIfExists('organization_user');
        Schema::dropIfExists('organizations');
    }
};
