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
        Schema::table('stores', function (Blueprint $table) {
            $table->boolean('inventory_auto')->default(false);
        });
        Schema::table('orders', function (Blueprint $table) {
            $table->json('inventory_plan')->nullable();
            $table->timestamp('inventory_consumed_at')->nullable();
        });
        Schema::table('inventory_movements', function (Blueprint $table) {
            $table->foreignId('order_id')->nullable()->constrained()->restrictOnDelete();
            $table->unique(['order_id', 'item_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (DB::table('orders')->whereNotNull('inventory_plan')->exists()) {
            throw new RuntimeException('Preserve os planos e as baixas de estoque dos pedidos antes de reverter esta migração.');
        }
        Schema::table('inventory_movements', function (Blueprint $table) {
            $table->dropUnique(['order_id', 'item_id']);
            $table->dropConstrainedForeignId('order_id');
        });
        Schema::table('orders', function (Blueprint $table) {
            $table->dropColumn(['inventory_plan', 'inventory_consumed_at']);
        });
        Schema::table('stores', function (Blueprint $table) {
            $table->dropColumn('inventory_auto');
        });
    }
};
