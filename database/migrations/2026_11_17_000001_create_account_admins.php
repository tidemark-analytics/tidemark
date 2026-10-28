<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // People the account owner has named to manage the whole account with them. The owner is a user; the account is what that user owns.
        Schema::create('account_admins', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('owner_id');
            $table->uuid('user_id');
            $table->timestamps();
            $table->unique(['owner_id', 'user_id']);
            $table->index('user_id');
        });

        Schema::table('invitations', function (Blueprint $table) {
            $table->uuid('account_id')->nullable()->index(); // the owner whose account the person is invited to help manage
        });
    }

    public function down(): void
    {
        Schema::table('invitations', fn (Blueprint $t) => $t->dropColumn('account_id'));
        Schema::dropIfExists('account_admins');
    }
};
