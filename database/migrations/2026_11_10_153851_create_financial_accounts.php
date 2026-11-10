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
        Schema::create('financial_accounts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->restrictOnDelete();
            $table->foreignId('store_id')->constrained()->restrictOnDelete();
            $table->foreignId('purchase_id')->nullable()->unique()->constrained('inventory_purchases')->restrictOnDelete();
            $table->string('direction', 12);
            $table->string('description', 160);
            $table->string('counterparty', 120);
            $table->string('category', 25);
            $table->date('due_on');
            $table->bigInteger('amount_cents');
            $table->bigInteger('settled_cents')->default(0);
            $table->string('status', 12)->default('open');
            $table->unsignedInteger('revision')->default(1);
            $table->string('notes', 500)->nullable();
            $table->string('cancel_reason', 500)->nullable();
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->timestamps();
            $table->index(['tenant_id', 'store_id', 'status', 'due_on']);
        });
        Schema::create('financial_settlements', function (Blueprint $table) {
            $table->id();
            $table->foreignId('account_id')->constrained('financial_accounts')->restrictOnDelete();
            $table->bigInteger('amount_cents');
            $table->date('settled_on');
            $table->string('method', 15);
            $table->string('destination', 12);
            $table->foreignId('cash_entry_id')->nullable()->unique()->constrained('cash_entries')->restrictOnDelete();
            $table->string('note', 500);
            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            $table->date('reversed_on')->nullable();
            $table->timestamp('reversed_at')->nullable();
            $table->foreignId('reversed_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->foreignId('reversal_cash_entry_id')->nullable()->unique()->constrained('cash_entries')->restrictOnDelete();
            $table->string('reversal_reason', 500)->nullable();
            $table->timestamp('created_at');
        });
        Schema::create('financial_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('account_id')->constrained('financial_accounts')->restrictOnDelete();
            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            $table->string('action', 15);
            $table->uuid('request_key')->unique();
            $table->string('request_hash', 64);
            $table->json('snapshot');
            $table->timestamp('created_at');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (DB::table('financial_accounts')->exists()) {
            throw new RuntimeException('Há contas financeiras registradas; preserve o histórico antes de reverter.');
        }
        Schema::dropIfExists('financial_events');
        Schema::dropIfExists('financial_settlements');
        Schema::dropIfExists('financial_accounts');
    }
};
