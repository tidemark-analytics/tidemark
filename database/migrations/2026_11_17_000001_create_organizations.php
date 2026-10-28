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
        // An organization has a name, one owner and any number of admins; sites and teams belong to it.
        Schema::create('organizations', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('name', 100);
            $table->uuid('owner_id')->index();
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

        // Everyone who already owns sites gets an organization named after them, and their sites move into it.
        $owners = DB::table('site_user')->join('sites', 'sites.id', '=', 'site_user.site_id')
            ->where('site_user.role', 'owner')->where('sites.kind', '!=', 'rollup')->pluck('site_user.user_id')->unique();
        foreach ($owners as $userId) {
            $name = DB::table('users')->where('id', $userId)->value('name');
            if ($name === null) {
                continue;
            }
            $id = (string) Str::uuid();
            DB::table('organizations')->insert(['id' => $id, 'name' => Str::limit($name."'s organization", 100, ''), 'owner_id' => $userId, 'created_at' => now(), 'updated_at' => now()]);
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
