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
        Schema::table('ifood_apps', function (Blueprint $table) {
            $table->timestamp('last_polled_at')->nullable();
            $table->string('last_poll_error')->nullable();
        });
        Schema::table('ifood_connections', function (Blueprint $table) {
            $table->boolean('receiving_enabled')->default(false);
        });
        Schema::create('ifood_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('app_id')->constrained('ifood_apps');
            $table->unsignedBigInteger('connection_id');
            $table->foreignId('tenant_id')->constrained();
            $table->foreignId('store_id');
            $table->foreign(['store_id', 'tenant_id'])->references(['id', 'tenant_id'])->on('stores');
            $table->uuid('external_id');
            $table->uuid('merchant_id');
            $table->uuid('external_order_id')->nullable();
            $table->string('code', 80);
            $table->timestamp('occurred_at', 6);
            $table->text('payload');
            $table->timestamp('acknowledged_at')->nullable();
            $table->string('status')->default('received');
            $table->unsignedInteger('attempts')->default(0);
            $table->timestamp('retry_at')->nullable();
            $table->string('last_error')->nullable();
            $table->timestamps();
            $table->unique(['app_id', 'external_id']);
            $table->index(['app_id', 'status', 'retry_at']);
        });
        Schema::create('ifood_orders', function (Blueprint $table) {
            $table->id();
            $table->foreignId('app_id')->constrained('ifood_apps');
            $table->foreignId('tenant_id')->constrained();
            $table->foreignId('store_id');
            $table->foreign(['store_id', 'tenant_id'])->references(['id', 'tenant_id'])->on('stores');
            $table->uuid('merchant_id');
            $table->uuid('external_order_id');
            $table->string('display_id', 80);
            $table->string('customer_name');
            $table->string('status');
            $table->string('order_type');
            $table->string('timing');
            $table->timestamp('ordered_at');
            $table->timestamp('preparation_at')->nullable();
            $table->timestamp('last_event_at', 6);
            $table->unsignedInteger('total_cents');
            $table->unsignedInteger('prepaid_cents');
            $table->unsignedInteger('pending_cents');
            $table->text('snapshot');
            $table->timestamps();
            $table->unique(['app_id', 'external_order_id']);
            $table->index(['tenant_id', 'store_id', 'status']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('ifood_orders');
        Schema::dropIfExists('ifood_events');
        Schema::table('ifood_connections', fn (Blueprint $table) => $table->dropColumn('receiving_enabled'));
        Schema::table('ifood_apps', fn (Blueprint $table) => $table->dropColumn(['last_polled_at', 'last_poll_error']));
    }
};
