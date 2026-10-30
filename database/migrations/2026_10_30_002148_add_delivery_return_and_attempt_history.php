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
        Schema::table('rides', function (Blueprint $table) {
            $table->unsignedInteger('dispatch_round')->default(1);
            $table->uuid('last_retry_key')->nullable();
            $table->uuid('return_request_key')->nullable();
            $table->text('return_reason')->nullable();
            $table->foreignId('return_requested_by')->nullable()->constrained('users');
            $table->timestamp('return_requested_at')->nullable();
            $table->timestamp('return_confirmed_at')->nullable();
        });
        Schema::table('offers', function (Blueprint $table) {
            $table->unsignedInteger('dispatch_round')->default(1);
            $table->dropUnique(['ride_id', 'driver_id']);
            $table->unique(['ride_id', 'driver_id', 'dispatch_round']);
        });
        Schema::create('delivery_attempts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained();
            $table->foreignId('ride_id')->constrained();
            $table->foreignId('driver_id')->constrained();
            $table->foreignId('closed_by')->constrained('users');
            $table->uuid('request_key');
            $table->string('outcome', 40);
            $table->text('reason');
            $table->text('return_reason')->nullable();
            $table->unsignedInteger('quoted_fare_cents');
            $table->unsignedInteger('compensation_cents');
            $table->unsignedInteger('expected_cash_cents');
            $table->unsignedInteger('customer_cash_cents');
            $table->boolean('settled')->default(false);
            $table->foreignId('settled_by')->nullable()->constrained('users');
            $table->timestamp('settled_at')->nullable();
            $table->timestamp('closed_at');
            $table->timestamps();
            $table->unique(['ride_id', 'request_key']);
            $table->index(['tenant_id', 'ride_id']);
            $table->index(['driver_id', 'id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (DB::table('offers')->select('ride_id', 'driver_id')->groupBy('ride_id', 'driver_id')->havingRaw('count(*) > 1')->exists()) {
            throw new RuntimeException('O histórico contém novas tentativas. Preserve-o antes de reverter esta migration.');
        }
        Schema::dropIfExists('delivery_attempts');
        Schema::table('offers', function (Blueprint $table) {
            $table->dropUnique(['ride_id', 'driver_id', 'dispatch_round']);
            $table->unique(['ride_id', 'driver_id']);
            $table->dropColumn('dispatch_round');
        });
        Schema::table('rides', function (Blueprint $table) {
            $table->dropConstrainedForeignId('return_requested_by');
            $table->dropColumn(['dispatch_round', 'last_retry_key', 'return_request_key', 'return_reason', 'return_requested_at', 'return_confirmed_at']);
        });
    }
};
