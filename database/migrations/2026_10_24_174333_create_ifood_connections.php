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
        Schema::create('ifood_apps', function (Blueprint $table) {
            $table->id();
            $table->string('environment')->unique();
            $table->string('client_id');
            $table->text('client_secret');
            $table->uuid('revision');
            $table->timestamps();
        });
        Schema::create('ifood_connections', function (Blueprint $table) {
            $table->id();
            $table->foreignId('app_id')->constrained('ifood_apps');
            $table->foreignId('tenant_id')->constrained();
            $table->foreignId('store_id')->unique();
            $table->foreign(['store_id', 'tenant_id'])->references(['id', 'tenant_id'])->on('stores');
            $table->uuid('merchant_id');
            $table->string('merchant_name');
            $table->string('status')->default('pending');
            $table->json('operations')->nullable();
            $table->string('last_error')->nullable();
            $table->timestamp('checked_at')->nullable();
            $table->timestamps();
            $table->unique(['app_id', 'merchant_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('ifood_connections');
        Schema::dropIfExists('ifood_apps');
    }
};
