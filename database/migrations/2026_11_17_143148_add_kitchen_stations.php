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
        Schema::table('products', function (Blueprint $table) {
            $table->string('kitchen_station', 60)->nullable();
        });
        Schema::table('order_items', function (Blueprint $table) {
            $table->string('kitchen_station', 60)->nullable();
            $table->timestamp('station_finished_at')->nullable();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('order_items', fn (Blueprint $table) => $table->dropColumn(['kitchen_station', 'station_finished_at']));
        Schema::table('products', fn (Blueprint $table) => $table->dropColumn('kitchen_station'));
    }
};
