<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('cash_entries', function (Blueprint $table) {
            $table->dropUnique(['order_id', 'kind']);
            $table->index(['order_id', 'kind']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (DB::table('cash_entries')->select('order_id', 'kind')->whereNotNull('order_id')->groupBy('order_id', 'kind')->havingRaw('COUNT(*) > 1')->exists()) {
            throw new RuntimeException('Há recebimentos divididos. Preserve os registros e mantenha esta migração aplicada.');
        }
        Schema::table('cash_entries', function (Blueprint $table) {
            $table->dropIndex(['order_id', 'kind']);
            $table->unique(['order_id', 'kind']);
        });
    }
};
