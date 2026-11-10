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
        Schema::create('delivery_areas', function (Blueprint $table) {
            $table->id();
            $table->foreignId('store_id')->constrained()->restrictOnDelete();
            $table->string('name', 120);
            $table->unsignedInteger('fee_cents');
            $table->boolean('enabled')->default(true);
            $table->string('color', 7)->default('#ff623f');
            $table->json('polygon');
            $table->uuid('request_key')->unique();
            $table->string('request_hash', 64);
            $table->unsignedInteger('revision')->default(1);
            $table->softDeletes();
            $table->timestamps();
            $table->index(['store_id', 'enabled', 'deleted_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (DB::table('delivery_areas')->exists()) {
            throw new RuntimeException('Preserve as áreas desenhadas antes de reverter esta migração.');
        }
        Schema::dropIfExists('delivery_areas');
    }
};
