<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sites', function (Blueprint $table) {
            $table->string('imported_from', 20)->nullable(); // plausible
            $table->date('imported_start')->nullable();
            $table->date('imported_end')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('sites', function (Blueprint $table) {
            $table->dropColumn(['imported_from', 'imported_start', 'imported_end']);
        });
    }
};
