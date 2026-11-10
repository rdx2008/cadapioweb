<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tenants', function (Blueprint $t) {
            $t->id();
            $t->string('name');
            $t->string('slug')->unique();
            $t->string('courier_module')->default('disabled');
            $t->timestamps();
        });
        Schema::table('users', function (Blueprint $t) {
            $t->foreignId('tenant_id')->nullable()->constrained();
            $t->string('role')->default('owner');
        });
        Schema::create('stores', function (Blueprint $t) {
            $t->id();
            $t->foreignId('tenant_id')->constrained();
            $t->string('name');
            $t->string('slug')->unique();
            $t->string('address');
            $t->decimal('latitude', 10, 7);
            $t->decimal('longitude', 10, 7);
            $t->boolean('open')->default(true);
            $t->string('rate_mode')->default('fixed');
            $t->unsignedInteger('base_cents')->default(800);
            $t->unsignedInteger('per_km_cents')->default(200);
            $t->unsignedInteger('minimum_cents')->default(800);
            $t->unsignedInteger('customer_fee_cents')->default(500);
            $t->timestamps();
            $t->unique(['id', 'tenant_id']);
        });
        Schema::create('products', function (Blueprint $t) {
            $t->id();
            $t->foreignId('tenant_id')->constrained();
            $t->foreignId('store_id');
            $t->foreign(['store_id', 'tenant_id'])->references(['id', 'tenant_id'])->on('stores');
            $t->string('name');
            $t->string('category');
            $t->text('description')->nullable();
            $t->unsignedInteger('price_cents');
            $t->boolean('available')->default(true);
            $t->timestamps();
        });
        Schema::create('orders', function (Blueprint $t) {
            $t->id();
            $t->uuid('public_id')->unique();
            $t->foreignId('tenant_id')->constrained();
            $t->foreignId('store_id');
            $t->foreign(['store_id', 'tenant_id'])->references(['id', 'tenant_id'])->on('stores');
            $t->string('idempotency_key');
            $t->string('customer_name');
            $t->string('phone');
            $t->string('mode');
            $t->text('address')->nullable();
            $t->string('status')->default('pending');
            $t->string('payment_status')->default('pending');
            $t->string('payment_method')->default('cash');
            $t->unsignedInteger('subtotal_cents');
            $t->unsignedInteger('fee_cents');
            $t->unsignedInteger('total_cents');
            $t->timestamps();
            $t->unique(['store_id', 'idempotency_key']);
        });
        Schema::create('order_items', function (Blueprint $t) {
            $t->id();
            $t->foreignId('order_id')->constrained();
            $t->string('name');
            $t->unsignedInteger('quantity');
            $t->unsignedInteger('unit_cents');
        });
        Schema::create('drivers', function (Blueprint $t) {
            $t->id();
            $t->foreignId('user_id')->unique()->constrained();
            $t->string('phone');
            $t->string('vehicle');
            $t->string('status')->default('pending');
            $t->boolean('available')->default(false);
            $t->decimal('latitude', 10, 7)->nullable();
            $t->decimal('longitude', 10, 7)->nullable();
            $t->decimal('accuracy', 8, 2)->nullable();
            $t->timestamp('heartbeat_at')->nullable();
            $t->foreignId('checkin_store_id')->nullable()->constrained('stores');
            $t->timestamp('checked_in_at')->nullable();
            $t->timestamps();
        });
        Schema::create('rides', function (Blueprint $t) {
            $t->id();
            $t->foreignId('tenant_id')->constrained();
            $t->foreignId('store_id')->constrained();
            $t->foreignId('order_id')->unique()->constrained();
            $t->foreignId('driver_id')->nullable()->constrained();
            $t->string('status')->default('searching');
            $t->string('rate_mode');
            $t->unsignedInteger('fare_cents');
            $t->unsignedInteger('route_meters')->nullable();
            $t->unsignedInteger('cash_cents')->default(0);
            $t->boolean('settled')->default(false);
            $t->timestamp('completed_at')->nullable();
            $t->timestamps();
        });
        Schema::create('offers', function (Blueprint $t) {
            $t->id();
            $t->foreignId('ride_id')->constrained();
            $t->foreignId('driver_id')->constrained();
            $t->string('status')->default('open');
            $t->timestamp('expires_at');
            $t->timestamps();
            $t->unique(['ride_id', 'driver_id']);
        });
        Schema::create('driver_claims', function (Blueprint $t) {
            $t->foreignId('driver_id')->primary()->constrained();
            $t->foreignId('ride_id')->unique()->constrained();
            $t->timestamp('expires_at')->nullable();
        });
        Schema::create('settings', function (Blueprint $t) {
            $t->string('key')->primary();
            $t->text('value');
        });
        Schema::create('whatsapp_connections', function (Blueprint $t) {
            $t->id();
            $t->foreignId('tenant_id')->constrained();
            $t->foreignId('store_id')->unique()->constrained();
            $t->string('instance')->unique();
            $t->string('phone');
            $t->string('mode');
            $t->string('status')->default('pending');
            $t->text('secret');
            $t->timestamps();
        });
        Schema::create('audit_events', function (Blueprint $t) {
            $t->id();
            $t->foreignId('tenant_id')->nullable()->constrained();
            $t->foreignId('user_id')->nullable()->constrained();
            $t->string('action');
            $t->json('details');
            $t->timestamp('created_at');
        });
    }

    public function down(): void
    {
        foreach (['audit_events', 'whatsapp_connections', 'settings', 'driver_claims', 'offers', 'rides', 'drivers', 'order_items', 'orders', 'products', 'stores'] as $table) {
            Schema::dropIfExists($table);
        }Schema::table('users', function (Blueprint $t) {
            $t->dropConstrainedForeignId('tenant_id');
            $t->dropColumn('role');
        });
        Schema::dropIfExists('tenants');
    }
};
