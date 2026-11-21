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
        Schema::create('inventory_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->restrictOnDelete();
            $table->foreignId('store_id')->constrained()->restrictOnDelete();
            $table->string('name', 120);
            $table->string('name_key', 120);
            $table->string('unit', 5);
            $table->bigInteger('balance')->default(0);
            $table->bigInteger('minimum')->default(0);
            $table->boolean('active')->default(true);
            $table->timestamps();
            $table->unique(['store_id', 'name_key']);
        });
        Schema::create('inventory_movements', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->restrictOnDelete();
            $table->foreignId('store_id')->constrained()->restrictOnDelete();
            $table->foreignId('item_id')->constrained('inventory_items')->restrictOnDelete();
            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            $table->string('item_name', 120);
            $table->string('unit', 5);
            $table->string('kind', 10);
            $table->bigInteger('quantity');
            $table->bigInteger('balance_after');
            $table->string('reason', 500);
            $table->uuid('request_key')->unique();
            $table->timestamp('created_at');
            $table->index(['store_id', 'id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (DB::table('inventory_items')->exists()) {
            throw new RuntimeException('Preserve o cadastro e o histórico do estoque antes de reverter esta migração.');
        }
        Schema::dropIfExists('inventory_movements');
        Schema::dropIfExists('inventory_items');
    }
};
