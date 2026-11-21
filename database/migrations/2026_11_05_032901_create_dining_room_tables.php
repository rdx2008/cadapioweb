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
        Schema::create('dining_tables', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->restrictOnDelete();
            $table->foreignId('store_id')->constrained()->restrictOnDelete();
            $table->string('name', 80);
            $table->string('name_key', 80);
            $table->unsignedInteger('seats');
            $table->boolean('enabled')->default(true);
            $table->unique(['store_id', 'name_key']);
            $table->timestamps();
        });
        Schema::create('dining_tabs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->restrictOnDelete();
            $table->foreignId('store_id')->constrained()->restrictOnDelete();
            $table->foreignId('dining_table_id')->constrained()->restrictOnDelete();
            $table->foreignId('active_table_id')->nullable()->unique()->constrained('dining_tables')->restrictOnDelete();
            $table->string('table_name', 80);
            $table->string('guest_label', 120)->nullable();
            $table->unsignedInteger('guests');
            $table->foreignId('opened_by')->constrained('users')->restrictOnDelete();
            $table->foreignId('closed_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->uuid('request_key')->unique();
            $table->uuid('close_key')->nullable()->unique();
            $table->bigInteger('closing_total_cents')->nullable();
            $table->timestamp('opened_at');
            $table->timestamp('closed_at')->nullable();
            $table->timestamps();
            $table->index(['tenant_id', 'store_id', 'opened_at']);
        });
        Schema::table('orders', function (Blueprint $table) {
            $table->foreignId('dining_tab_id')->nullable()->constrained()->restrictOnDelete();
            $table->string('dining_label', 120)->nullable();
        });
        Schema::create('dining_settlements', function (Blueprint $table) {
            $table->id();
            $table->foreignId('dining_tab_id')->constrained()->restrictOnDelete();
            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            $table->uuid('request_key')->unique();
            $table->string('review_hash', 64);
            $table->string('payment_method', 20);
            $table->bigInteger('amount_cents');
            $table->json('order_ids');
            $table->timestamp('created_at');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('dining_settlements');
        Schema::table('orders', function (Blueprint $table) {
            $table->dropConstrainedForeignId('dining_tab_id');
            $table->dropColumn('dining_label');
        });
        Schema::dropIfExists('dining_tabs');
        Schema::dropIfExists('dining_tables');
    }
};
