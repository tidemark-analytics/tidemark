<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * More ways to pass the second sign-in step: a code e-mailed to the account address, and passkeys (WebAuthn credentials kept on a phone,
 * laptop or security key). Recovery codes are the way back in when none of them is at hand.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->timestamp('two_factor_email_confirmed_at')->nullable();
            $table->text('two_factor_recovery_codes')->nullable();
        });

        Schema::create('passkeys', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('user_id')->constrained()->cascadeOnDelete();
            $table->string('name', 100);
            $table->string('credential_id', 512)->unique();
            $table->text('public_key');
            $table->unsignedBigInteger('sign_count')->default(0);
            $table->timestamp('last_used_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('passkeys');
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['two_factor_email_confirmed_at', 'two_factor_recovery_codes']);
        });
    }
};
