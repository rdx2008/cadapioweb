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
        Schema::table('tenants', function (Blueprint $table) {
            $table->string('status', 20)->default('active')->index();
            $table->string('suspension_reason', 500)->nullable();
            $table->timestamp('suspended_at')->nullable();
            $table->timestamp('deleted_at')->nullable()->index();
        });
        Schema::table('orders', function (Blueprint $table) {
            $table->index(['tenant_id', 'created_at']);
            $table->index(['store_id', 'created_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dropIndex(['tenant_id', 'created_at']);
            $table->dropIndex(['store_id', 'created_at']);
        });
        Schema::table('tenants', function (Blueprint $table) {
            $table->dropIndex(['status']);
            $table->dropIndex(['deleted_at']);
            $table->dropColumn(['status', 'suspension_reason', 'suspended_at', 'deleted_at']);
        });
    }
};
