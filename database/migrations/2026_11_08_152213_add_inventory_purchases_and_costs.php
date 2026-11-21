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
        Schema::table('inventory_items', function (Blueprint $table) {
            $table->bigInteger('stock_value_cents')->nullable();
            $table->bigInteger('average_cost_micros')->nullable();
            $table->unsignedInteger('valuation_revision')->default(0);
        });
        DB::table('inventory_items')->where('balance', 0)->update(['stock_value_cents' => 0]);
        Schema::create('inventory_suppliers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->restrictOnDelete();
            $table->foreignId('store_id')->constrained()->restrictOnDelete();
            $table->string('name', 120);
            $table->string('name_key', 120);
            $table->string('document', 30)->nullable();
            $table->string('phone', 25)->nullable();
            $table->string('email', 190)->nullable();
            $table->string('notes', 500)->nullable();
            $table->boolean('active')->default(true);
            $table->timestamps();
            $table->unique(['store_id', 'name_key']);
        });
        Schema::create('inventory_purchases', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->restrictOnDelete();
            $table->foreignId('store_id')->constrained()->restrictOnDelete();
            $table->foreignId('supplier_id')->constrained('inventory_suppliers')->restrictOnDelete();
            $table->string('supplier_name', 120);
            $table->string('status', 15)->default('draft');
            $table->string('reference', 80)->nullable();
            $table->date('purchased_on');
            $table->string('notes', 500)->nullable();
            $table->bigInteger('subtotal_cents');
            $table->bigInteger('freight_cents');
            $table->bigInteger('discount_cents');
            $table->bigInteger('total_cents');
            $table->unsignedInteger('revision')->default(1);
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->foreignId('received_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamp('received_at')->nullable();
            $table->foreignId('cancelled_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamp('cancelled_at')->nullable();
            $table->string('cancel_reason', 500)->nullable();
            $table->timestamps();
            $table->index(['store_id', 'status', 'id']);
        });
        Schema::create('inventory_purchase_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('purchase_id')->constrained('inventory_purchases')->restrictOnDelete();
            $table->foreignId('item_id')->constrained('inventory_items')->restrictOnDelete();
            $table->string('item_name', 120);
            $table->string('unit', 5);
            $table->bigInteger('quantity');
            $table->bigInteger('unit_price_cents');
            $table->bigInteger('subtotal_cents');
            $table->bigInteger('freight_cents');
            $table->bigInteger('discount_cents');
            $table->bigInteger('total_cents');
            $table->unique(['purchase_id', 'item_id']);
        });
        Schema::create('inventory_purchase_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('purchase_id')->constrained('inventory_purchases')->restrictOnDelete();
            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            $table->string('action', 15);
            $table->json('snapshot');
            $table->uuid('request_key')->unique();
            $table->string('request_hash', 64);
            $table->timestamp('created_at');
        });
        Schema::create('inventory_valuations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('item_id')->constrained('inventory_items')->restrictOnDelete();
            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            $table->bigInteger('quantity');
            $table->string('item_name', 120);
            $table->string('unit', 5);
            $table->bigInteger('previous_value_cents')->nullable();
            $table->bigInteger('value_cents');
            $table->bigInteger('average_cost_micros');
            $table->string('reason', 500);
            $table->uuid('request_key')->unique();
            $table->string('review_hash', 64);
            $table->timestamp('created_at');
        });
        Schema::table('inventory_movements', function (Blueprint $table) {
            $table->foreignId('purchase_line_id')->nullable()->constrained('inventory_purchase_lines')->restrictOnDelete();
            $table->unique('purchase_line_id');
            $table->bigInteger('value_cents')->nullable();
            $table->bigInteger('stock_value_after_cents')->nullable();
            $table->bigInteger('average_cost_micros')->nullable();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (DB::table('inventory_suppliers')->exists() || DB::table('inventory_purchases')->exists() || DB::table('inventory_valuations')->exists() || DB::table('inventory_movements')->whereNotNull('value_cents')->exists()) {
            throw new RuntimeException('Preserve compras, fornecedores e avaliações de estoque antes de reverter esta migração.');
        }
        Schema::table('inventory_movements', function (Blueprint $table) {
            $table->dropUnique(['purchase_line_id']);
            $table->dropConstrainedForeignId('purchase_line_id');
            $table->dropColumn(['value_cents', 'stock_value_after_cents', 'average_cost_micros']);
        });
        Schema::dropIfExists('inventory_valuations');
        Schema::dropIfExists('inventory_purchase_events');
        Schema::dropIfExists('inventory_purchase_lines');
        Schema::dropIfExists('inventory_purchases');
        Schema::dropIfExists('inventory_suppliers');
        Schema::table('inventory_items', function (Blueprint $table) {
            $table->dropColumn(['stock_value_cents', 'average_cost_micros', 'valuation_revision']);
        });
    }
};
