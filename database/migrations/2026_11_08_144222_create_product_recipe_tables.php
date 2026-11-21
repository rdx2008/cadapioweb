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
        Schema::create('product_recipes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->restrictOnDelete();
            $table->foreignId('store_id')->constrained()->restrictOnDelete();
            $table->foreignId('product_id')->constrained()->restrictOnDelete();
            $table->string('target_key', 64);
            $table->unsignedInteger('revision');
            $table->json('lines');
            $table->timestamps();
            $table->unique(['product_id', 'target_key']);
        });
        Schema::create('product_recipe_revisions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('recipe_id')->constrained('product_recipes')->restrictOnDelete();
            $table->foreignId('product_id')->constrained()->restrictOnDelete();
            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            $table->unsignedInteger('revision');
            $table->string('target_label', 500);
            $table->string('product_name', 255);
            $table->json('lines');
            $table->string('reason', 500);
            $table->uuid('request_key')->unique();
            $table->string('request_hash', 64);
            $table->timestamp('created_at');
            $table->unique(['recipe_id', 'revision']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (DB::table('product_recipes')->exists()) {
            throw new RuntimeException('Preserve as fichas técnicas e suas versões antes de reverter esta migração.');
        }
        Schema::dropIfExists('product_recipe_revisions');
        Schema::dropIfExists('product_recipes');
    }
};
