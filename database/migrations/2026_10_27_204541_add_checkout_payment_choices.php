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
            $table->boolean('accepts_cash')->default(true);
            $table->boolean('accepts_card')->default(false);
            $table->boolean('accepts_pix')->default(false);
        });
        Schema::table('orders', function (Blueprint $table) {
            $table->unsignedInteger('change_for_cents')->nullable();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('orders', fn (Blueprint $table) => $table->dropColumn('change_for_cents'));
        Schema::table('stores', fn (Blueprint $table) => $table->dropColumn(['accepts_cash', 'accepts_card', 'accepts_pix']));
    }
};
