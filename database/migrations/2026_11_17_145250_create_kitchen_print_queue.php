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
        Schema::table('stores', function (Blueprint $table) {
            $table->string('kitchen_printer_token_hash', 64)->nullable()->unique();
        });
        Schema::create('kitchen_print_jobs', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignId('store_id')->constrained()->restrictOnDelete();
            $table->foreignId('order_id')->constrained()->restrictOnDelete();
            $table->unsignedInteger('sequence');
            $table->string('station', 60)->nullable();
            $table->text('ticket');
            $table->string('status', 12)->default('pending');
            $table->timestamp('claimed_at')->nullable();
            $table->timestamps();
            $table->unique(['order_id', 'sequence']);
            $table->index(['store_id', 'status', 'created_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('kitchen_print_jobs');
        Schema::table('stores', function (Blueprint $table) {
            $table->dropUnique(['kitchen_printer_token_hash']);
            $table->dropColumn('kitchen_printer_token_hash');
        });
    }
};
