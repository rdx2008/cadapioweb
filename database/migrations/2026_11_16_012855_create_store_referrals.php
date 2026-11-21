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
        Schema::create('store_referrals', function (Blueprint $table) {
            $table->id();
            $table->foreignId('store_id')->unique()->constrained()->restrictOnDelete();
            $table->boolean('enabled')->default(false);
            $table->string('reward', 140);
            $table->unsignedBigInteger('minimum_cents')->default(0);
            $table->timestamps();
        });
        Schema::create('referral_invites', function (Blueprint $table) {
            $table->id();
            $table->foreignId('store_id')->constrained()->restrictOnDelete();
            $table->foreignId('inviter_order_id')->constrained('orders')->restrictOnDelete();
            $table->string('customer_hash', 64);
            $table->string('code', 24)->unique();
            $table->unique(['store_id', 'customer_hash']);
        });
        Schema::create('referral_conversions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('store_id')->constrained()->restrictOnDelete();
            $table->foreignId('invite_id')->constrained('referral_invites')->restrictOnDelete();
            $table->foreignId('order_id')->unique()->constrained()->restrictOnDelete();
            $table->string('customer_hash', 64);
            $table->string('reward', 140);
            $table->timestamp('delivered_at')->nullable();
            $table->foreignId('delivered_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->index(['store_id', 'customer_hash']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('referral_conversions');
        Schema::dropIfExists('referral_invites');
        Schema::dropIfExists('store_referrals');
    }
};
