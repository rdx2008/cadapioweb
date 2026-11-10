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
        Schema::table('users', function (Blueprint $table) {
            $table->boolean('access_enabled')->default(true);
            $table->string('staff_profile')->nullable();
            $table->unique(['id', 'tenant_id']);
        });
        Schema::create('user_store_access', function (Blueprint $table) {
            $table->foreignId('user_id');
            $table->foreignId('tenant_id');
            $table->foreignId('store_id');
            $table->primary(['user_id', 'store_id']);
            $table->foreign(['user_id', 'tenant_id'])->references(['id', 'tenant_id'])->on('users')->cascadeOnDelete();
            $table->foreign(['store_id', 'tenant_id'])->references(['id', 'tenant_id'])->on('stores')->cascadeOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('user_store_access');
        Schema::table('users', function (Blueprint $table) {
            $table->dropUnique(['id', 'tenant_id']);
            $table->dropColumn(['access_enabled', 'staff_profile']);
        });
    }
};
