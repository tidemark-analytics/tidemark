<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Rollups belong to an organization instead of travelling with a person: a rollup adds up the sites of its organization. Existing rollups
 * go into their owner's Personal organization, where the sites they own already are.
 */
return new class extends Migration
{
    public function up(): void
    {
        $rollups = DB::table('sites')->where('kind', 'rollup')->whereNull('organization_id')->pluck('id');
        foreach ($rollups as $id) {
            $ownerId = DB::table('site_user')->where('site_id', $id)->where('role', 'owner')->value('user_id');
            $org = $ownerId ? DB::table('organizations')->where('owner_id', $ownerId)->where('personal', true)->value('id') : null;
            if ($org) {
                DB::table('sites')->where('id', $id)->update(['organization_id' => $org]);
            }
        }
    }

    public function down(): void
    {
        // a rollup keeping its organization does no harm to the earlier version
    }
};
