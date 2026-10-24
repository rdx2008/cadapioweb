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
        Schema::table('ifood_connections', function (Blueprint $table) {
            $table->boolean('actions_enabled')->default(false);
        });
        Schema::table('ifood_orders', function (Blueprint $table) {
            $table->string('delivered_by')->nullable();
            $table->text('cancellation_reasons')->nullable();
            $table->timestamp('reasons_checked_at')->nullable();
        });
        Schema::create('ifood_commands', function (Blueprint $table) {
            $table->id();
            $table->foreignId('app_id')->constrained('ifood_apps');
            $table->foreignId('order_id')->constrained('ifood_orders');
            $table->foreignId('tenant_id')->constrained();
            $table->foreignId('store_id')->constrained();
            $table->unsignedBigInteger('connection_id');
            $table->foreignId('user_id')->nullable()->constrained();
            $table->uuid('request_key');
            $table->uuid('app_revision');
            $table->string('action');
            $table->string('status')->default('queued');
            $table->text('payload');
            $table->unsignedInteger('attempts')->default(0);
            $table->timestamp('locked_at')->nullable();
            $table->timestamp('sent_at', 6)->nullable();
            $table->timestamp('retry_at')->nullable();
            $table->string('last_error')->nullable();
            $table->timestamps();
            $table->unique(['order_id', 'request_key']);
            $table->index(['status', 'retry_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('ifood_commands');
        Schema::table('ifood_orders', fn (Blueprint $table) => $table->dropColumn(['delivered_by', 'cancellation_reasons', 'reasons_checked_at']));
        Schema::table('ifood_connections', fn (Blueprint $table) => $table->dropColumn('actions_enabled'));
    }
};
