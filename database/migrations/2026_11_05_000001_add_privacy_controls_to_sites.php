<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sites', function (Blueprint $table) {
            $table->boolean('honor_gpc')->default(false);
            $table->boolean('honor_dnt')->default(false);
            $table->string('location_precision', 10)->default('city');
            $table->string('url_params', 500)->nullable();
            $table->boolean('referrer_domain_only')->default(false);
        });
    }

    public function down(): void
    {
        Schema::table('sites', function (Blueprint $table) {
            $table->dropColumn(['honor_gpc', 'honor_dnt', 'location_precision', 'url_params', 'referrer_domain_only']);
        });
    }
};
