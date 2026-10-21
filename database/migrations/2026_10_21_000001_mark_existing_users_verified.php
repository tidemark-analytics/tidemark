<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/** Email verification is opt-in and new; accounts that already exist are treated as verified so turning it on never locks them out. */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('users')->whereNull('email_verified_at')->update(['email_verified_at' => now()]);
    }

    public function down(): void {}
};
