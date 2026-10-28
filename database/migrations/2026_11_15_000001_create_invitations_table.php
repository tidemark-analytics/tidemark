<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // An invitation is not a user: nothing exists for the person until they open the link, so the address stays free to register.
        Schema::create('invitations', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('email');
            $table->string('token_hash', 64)->unique();
            $table->uuid('invited_by')->index();
            $table->uuid('site_id')->nullable()->index();
            $table->string('role', 12)->default('viewer'); // admin | viewer
            $table->timestamp('expires_at')->index();
            $table->timestamps();
            $table->index('email');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('invitations');
    }
};
