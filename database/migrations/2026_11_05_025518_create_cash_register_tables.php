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
        Schema::create('cash_sessions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->restrictOnDelete();
            $table->foreignId('store_id')->constrained()->restrictOnDelete();
            $table->foreignId('open_store_id')->nullable()->unique()->constrained('stores')->restrictOnDelete();
            $table->foreignId('opened_by')->constrained('users')->restrictOnDelete();
            $table->foreignId('closed_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->uuid('request_key')->unique();
            $table->uuid('close_key')->nullable()->unique();
            $table->bigInteger('opening_cents');
            $table->bigInteger('counted_cents')->nullable();
            $table->bigInteger('expected_cents')->nullable();
            $table->bigInteger('difference_cents')->nullable();
            $table->text('closing_note')->nullable();
            $table->json('closing_totals')->nullable();
            $table->timestamp('opened_at');
            $table->timestamp('closed_at')->nullable();
            $table->timestamps();
            $table->index(['tenant_id', 'store_id', 'opened_at']);
        });
        Schema::create('cash_entries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->restrictOnDelete();
            $table->foreignId('store_id')->constrained()->restrictOnDelete();
            $table->foreignId('cash_session_id')->constrained()->restrictOnDelete();
            $table->foreignId('user_id')->constrained('users')->restrictOnDelete();
            $table->foreignId('order_id')->nullable()->constrained()->restrictOnDelete();
            $table->uuid('request_key')->unique();
            $table->string('kind', 20);
            $table->string('payment_method', 20);
            $table->bigInteger('amount_cents');
            $table->string('note', 500);
            $table->timestamp('created_at');
            $table->unique(['order_id', 'kind']);
            $table->index(['cash_session_id', 'id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('cash_entries');
        Schema::dropIfExists('cash_sessions');
    }
};
