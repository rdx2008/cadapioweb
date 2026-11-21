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
        Schema::create('order_feedback', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->restrictOnDelete();
            $table->foreignId('store_id')->constrained()->restrictOnDelete();
            $table->foreignId('order_id')->unique()->constrained()->restrictOnDelete();
            $table->unsignedSmallInteger('rating');
            $table->string('comment', 1000)->nullable();
            $table->foreignId('treated_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamp('treated_at')->nullable();
            $table->string('treatment_note', 1000)->nullable();
            $table->timestamps();
            $table->index(['tenant_id', 'store_id', 'created_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (DB::table('order_feedback')->exists()) {
            throw new RuntimeException('Existem avaliações. Preserve o histórico dos clientes.');
        }
        Schema::dropIfExists('order_feedback');
    }
};
