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
            $table->string('delivery_mode')->nullable();
            $table->string('delivery_pricing')->default('bands');
            $table->unsignedInteger('delivery_max_meters')->default(5000);
            $table->unsignedInteger('delivery_fixed_cents')->default(500);
            $table->unsignedInteger('delivery_base_cents')->default(0);
            $table->unsignedInteger('delivery_per_km_cents')->default(100);
            $table->unsignedInteger('delivery_minimum_cents')->default(0);
        });
        Schema::table('delivery_zones', function (Blueprint $table) {
            $table->string('city', 120)->nullable();
            $table->string('state_code', 2)->nullable();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('delivery_zones', function (Blueprint $table) {
            $table->dropColumn(['city', 'state_code']);
        });
        Schema::table('stores', function (Blueprint $table) {
            $table->dropColumn(['delivery_mode', 'delivery_pricing', 'delivery_max_meters', 'delivery_fixed_cents', 'delivery_base_cents', 'delivery_per_km_cents', 'delivery_minimum_cents']);
        });
    }
};
