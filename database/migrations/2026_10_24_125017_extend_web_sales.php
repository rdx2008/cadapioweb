<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('stores', function (Blueprint $table) {
            $table->string('timezone')->default('America/Sao_Paulo');
            $table->json('weekly_hours')->nullable();
            $table->unsignedInteger('minimum_order_cents')->default(0);
        });
        Schema::table('products', function (Blueprint $table) {
            $table->string('photo_path')->nullable();
        });
        Schema::create('delivery_zones', function (Blueprint $table) {
            $table->id();
            $table->foreignId('store_id')->constrained();
            $table->string('name', 120);
            $table->unsignedInteger('fee_cents');
            $table->boolean('enabled')->default(true);
            $table->timestamps();
        });
        Schema::create('option_groups', function (Blueprint $table) {
            $table->id();
            $table->foreignId('product_id')->constrained();
            $table->string('name', 100);
            $table->unsignedInteger('minimum')->default(0);
            $table->unsignedInteger('maximum')->default(1);
            $table->timestamps();
        });
        Schema::create('product_options', function (Blueprint $table) {
            $table->id();
            $table->foreignId('option_group_id')->constrained();
            $table->string('name', 120);
            $table->unsignedInteger('price_cents')->default(0);
            $table->boolean('available')->default(true);
            $table->timestamps();
        });
        Schema::table('orders', function (Blueprint $table) {
            $table->string('delivery_zone_name')->nullable();
            $table->text('cancellation_reason')->nullable();
            $table->index(['tenant_id', 'status', 'id']);
        });
        Schema::table('order_items', function (Blueprint $table) {
            $table->json('options')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('order_items', fn (Blueprint $t) => $t->dropColumn('options'));
        Schema::table('orders', function (Blueprint $t) {
            $t->dropIndex(['tenant_id', 'status', 'id']);
            $t->dropColumn(['delivery_zone_name', 'cancellation_reason']);
        });
        Schema::dropIfExists('product_options');
        Schema::dropIfExists('option_groups');
        Schema::dropIfExists('delivery_zones');
        Schema::table('products', fn (Blueprint $t) => $t->dropColumn('photo_path'));
        Schema::table('stores', fn (Blueprint $t) => $t->dropColumn(['timezone', 'weekly_hours', 'minimum_order_cents']));
    }
};
