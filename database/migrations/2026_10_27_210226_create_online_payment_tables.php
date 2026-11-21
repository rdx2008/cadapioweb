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
        Schema::create('store_payment_accounts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('store_id')->constrained();
            $table->string('provider', 20);
            $table->string('environment', 12);
            $table->text('credential');
            $table->string('handle', 100)->nullable();
            $table->string('webhook_secret', 64);
            $table->boolean('enabled')->default(false);
            $table->timestamps();
            $table->unique(['store_id', 'provider']);
        });
        Schema::create('payment_attempts', function (Blueprint $table) {
            $table->id();
            $table->uuid('reference')->unique();
            $table->foreignId('order_id')->unique()->constrained();
            $table->foreignId('account_id')->constrained('store_payment_accounts');
            $table->string('provider', 20);
            $table->string('environment', 12);
            $table->text('credential');
            $table->string('handle', 100)->nullable();
            $table->string('state', 24)->default('creating');
            $table->unsignedInteger('amount_cents');
            $table->string('external_id', 120)->nullable()->index();
            $table->string('transaction_id', 120)->nullable();
            $table->text('checkout_url')->nullable();
            $table->text('hints')->nullable();
            $table->dateTime('expires_at');
            $table->dateTime('next_check_at')->nullable()->index();
            $table->dateTime('paid_at')->nullable();
            $table->boolean('needs_review')->default(false);
            $table->string('message', 240)->nullable();
            $table->timestamps();
            $table->unique(['provider', 'transaction_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('payment_attempts');
        Schema::dropIfExists('store_payment_accounts');
    }
};
