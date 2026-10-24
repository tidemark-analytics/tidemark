<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('goals', function (Blueprint $table) {
            $table->char('currency', 3)->nullable(); // set on a custom-event goal to report the revenue its events carry
        });

        Schema::create('funnels', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('site_id')->index();
            $table->string('name', 100);
            $table->timestamps();
        });

        Schema::create('funnel_steps', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('funnel_id');
            $table->uuid('goal_id');
            $table->unsignedTinyInteger('position');
            $table->unique(['funnel_id', 'position']);
            $table->index('goal_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('funnel_steps');
        Schema::dropIfExists('funnels');
        Schema::table('goals', function (Blueprint $table) {
            $table->dropColumn('currency');
        });
    }
};
