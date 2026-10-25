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
            $table->string('logo_path')->nullable();
            $table->string('cover_path')->nullable();
            $table->boolean('delivery_by_radius')->default(false);
        });
        Schema::table('delivery_zones', function (Blueprint $table) {
            $table->unsignedInteger('radius_meters')->nullable();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('stores', function (Blueprint $table) {
            $table->dropColumn(['logo_path', 'cover_path', 'delivery_by_radius']);
        });
        Schema::table('delivery_zones', function (Blueprint $table) {
            $table->dropColumn('radius_meters');
        });
    }
};
