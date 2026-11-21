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
        Schema::create('inventory_counts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->restrictOnDelete();
            $table->foreignId('store_id')->constrained()->restrictOnDelete();
            $table->string('reference', 120);
            $table->string('reason', 400);
            $table->string('status', 12)->default('draft');
            $table->unsignedInteger('revision')->default(1);
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->uuid('request_key')->unique();
            $table->string('request_hash', 64);
            $table->foreignId('applied_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamp('applied_at')->nullable();
            $table->uuid('applied_key')->nullable()->unique();
            $table->string('applied_review', 64)->nullable();
            $table->foreignId('cancelled_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamp('cancelled_at')->nullable();
            $table->string('cancel_reason', 500)->nullable();
            $table->timestamps();
            $table->index(['tenant_id', 'store_id', 'id']);
        });
        Schema::create('inventory_count_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('count_id')->constrained('inventory_counts')->restrictOnDelete();
            $table->foreignId('item_id')->constrained('inventory_items')->restrictOnDelete();
            $table->json('snapshot');
            $table->string('baseline_hash', 64);
            $table->unsignedBigInteger('counted')->nullable();
            $table->foreignId('movement_id')->nullable()->unique()->constrained('inventory_movements')->restrictOnDelete();
            $table->unique(['count_id', 'item_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (DB::table('inventory_counts')->exists()) {
            throw new RuntimeException('Preserve as contagens de estoque e os ajustes registrados.');
        }
        Schema::dropIfExists('inventory_count_lines');
        Schema::dropIfExists('inventory_counts');
    }
};
