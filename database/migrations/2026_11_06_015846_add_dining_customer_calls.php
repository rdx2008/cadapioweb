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
        Schema::table('dining_tabs', function (Blueprint $table) {
            $table->text('guest_token')->nullable();
            $table->string('guest_token_hash', 64)->nullable()->unique();
        });
        Schema::create('dining_calls', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->restrictOnDelete();
            $table->foreignId('store_id')->constrained()->restrictOnDelete();
            $table->foreignId('dining_tab_id')->constrained()->restrictOnDelete();
            $table->string('kind', 20);
            $table->string('status', 20)->default('pending');
            $table->string('pending_key', 80)->nullable()->unique();
            $table->foreignId('handled_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamp('handled_at')->nullable();
            $table->string('cancelled_reason', 30)->nullable();
            $table->timestamp('created_at');
            $table->index(['store_id', 'status', 'created_at']);
        });
        Schema::create('dining_call_requests', function (Blueprint $table) {
            $table->id();
            $table->foreignId('dining_call_id')->constrained()->restrictOnDelete();
            $table->uuid('request_key')->unique();
            $table->timestamp('created_at');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (DB::table('dining_calls')->exists()) {
            throw new RuntimeException('Há solicitações de clientes. Mantenha a migração para preservar o histórico.');
        }
        Schema::dropIfExists('dining_call_requests');
        Schema::dropIfExists('dining_calls');
        Schema::table('dining_tabs', function (Blueprint $table) {
            $table->dropUnique(['guest_token_hash']);
            $table->dropColumn(['guest_token', 'guest_token_hash']);
        });
    }
};
