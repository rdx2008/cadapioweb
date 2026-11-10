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
            $table->unsignedSmallInteger('preparation_target_minutes')->default(30);
        });
        Schema::table('orders', function (Blueprint $table) {
            $table->timestamp('accepted_at')->nullable();
            $table->timestamp('preparation_started_at')->nullable();
            $table->timestamp('ready_at')->nullable();
            $table->unsignedSmallInteger('preparation_target_minutes')->nullable();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('orders', fn (Blueprint $table) => $table->dropColumn(['accepted_at', 'preparation_started_at', 'ready_at', 'preparation_target_minutes']));
        Schema::table('stores', fn (Blueprint $table) => $table->dropColumn('preparation_target_minutes'));
    }
};
