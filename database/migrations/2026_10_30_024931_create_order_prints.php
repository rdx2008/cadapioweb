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
        Schema::create('order_prints', function (Blueprint $table) {
            $table->id();
            $table->uuid('reference')->unique();
            $table->foreignId('tenant_id')->constrained();
            $table->foreignId('store_id')->constrained();
            $table->foreignId('order_id')->constrained();
            $table->foreignId('created_by')->constrained('users');
            $table->uuid('request_key');
            $table->string('kind', 20);
            $table->string('paper', 3);
            $table->unsignedInteger('copy_number');
            $table->text('payload');
            $table->timestamps();
            $table->unique(['order_id', 'request_key']);
            $table->unique(['order_id', 'kind', 'copy_number']);
            $table->index(['tenant_id', 'order_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('order_prints');
    }
};
