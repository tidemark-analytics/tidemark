<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // A profile photo and an organization logo, stored like site logos (small, base64 in the database) so shared hosting needs no storage setup.
        Schema::table('users', function (Blueprint $table) {
            $table->longText('avatar')->nullable();
            $table->string('avatar_mime', 24)->nullable();
            $table->timestamp('avatar_updated_at')->nullable();
        });
        Schema::table('organizations', function (Blueprint $table) {
            $table->longText('logo')->nullable();
            $table->string('logo_mime', 24)->nullable();
            $table->timestamp('logo_updated_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('organizations', fn (Blueprint $t) => $t->dropColumn(['logo', 'logo_mime', 'logo_updated_at']));
        Schema::table('users', fn (Blueprint $t) => $t->dropColumn(['avatar', 'avatar_mime', 'avatar_updated_at']));
    }
};
