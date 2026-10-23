<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sites', function (Blueprint $table) {
            $table->string('brand_name', 60)->nullable();
            $table->string('brand_color', 7)->nullable();
            $table->mediumText('brand_logo')->nullable(); // base64 of the uploaded image, shown on public pages as a data URI
            $table->string('brand_logo_mime', 24)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('sites', function (Blueprint $table) {
            $table->dropColumn(['brand_name', 'brand_color', 'brand_logo', 'brand_logo_mime']);
        });
    }
};
