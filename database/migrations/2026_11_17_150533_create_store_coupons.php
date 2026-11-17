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
        Schema::create('store_coupons', function (Blueprint $table) {
            $table->id();
            $table->foreignId('store_id')->constrained()->restrictOnDelete();
            $table->string('code', 24);
            $table->string('kind', 12);
            $table->unsignedInteger('value');
            $table->unsignedInteger('minimum_cents')->default(0);
            $table->unsignedInteger('maximum_uses');
            $table->unsignedInteger('per_customer');
            $table->date('starts_on');
            $table->date('ends_on');
            $table->boolean('enabled')->default(true);
            $table->timestamps();
            $table->unique(['store_id', 'code']);
        });
        Schema::table('orders', function (Blueprint $table) {
            $table->foreignId('coupon_id')->nullable()->constrained('store_coupons')->restrictOnDelete();
            $table->string('coupon_code', 24)->nullable();
            $table->string('coupon_customer_hash', 64)->nullable();
            $table->unsignedInteger('discount_cents')->default(0);
            $table->index(['coupon_id', 'coupon_customer_hash', 'status'], 'orders_coupon_usage');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dropIndex('orders_coupon_usage');
            $table->dropConstrainedForeignId('coupon_id');
            $table->dropColumn(['coupon_code', 'coupon_customer_hash', 'discount_cents']);
        });
        Schema::dropIfExists('store_coupons');
    }
};
