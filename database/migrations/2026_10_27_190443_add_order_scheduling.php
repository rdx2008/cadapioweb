<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('stores', function (Blueprint $table) {
            $table->boolean('scheduling_enabled')->default(false);
            $table->unsignedSmallInteger('scheduling_lead_minutes')->default(60);
            $table->unsignedSmallInteger('scheduling_prep_minutes')->default(30);
            $table->unsignedSmallInteger('scheduling_days')->default(7);
        });
        Schema::table('orders', function (Blueprint $table) {
            $table->dateTime('scheduled_at')->nullable()->index();
            $table->dateTime('prepare_at')->nullable();
            $table->string('scheduled_timezone', 64)->nullable();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dropIndex(['scheduled_at']);
            $table->dropColumn(['scheduled_at', 'prepare_at', 'scheduled_timezone']);
        });
        Schema::table('stores', function (Blueprint $table) {
            $table->dropColumn(['scheduling_enabled', 'scheduling_lead_minutes', 'scheduling_prep_minutes', 'scheduling_days']);
        });
    }
};
