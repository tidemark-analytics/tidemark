<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sites', function (Blueprint $table) {
            $table->string('kind', 8)->default('site'); // site | rollup (a read-only view that adds up other sites)
            $table->boolean('rollup_all')->default(false); // a rollup that includes every site its owner belongs to, now and later
        });

        Schema::create('rollup_sites', function (Blueprint $table) {
            $table->uuid('rollup_id');
            $table->uuid('site_id');
            $table->primary(['rollup_id', 'site_id']);
            $table->index('site_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('rollup_sites');
        Schema::table('sites', function (Blueprint $table) {
            $table->dropColumn(['kind', 'rollup_all']);
        });
    }
};
