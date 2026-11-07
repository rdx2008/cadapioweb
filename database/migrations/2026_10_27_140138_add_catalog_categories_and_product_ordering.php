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
        Schema::create('catalog_categories', function (Blueprint $table) {
            $table->id();
            $table->foreignId('store_id')->constrained()->cascadeOnDelete();
            $table->string('name', 80);
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();
            $table->unique(['store_id', 'name']);
            $table->unique(['id', 'store_id']);
        });
        Schema::table('products', function (Blueprint $table) {
            $table->unsignedBigInteger('category_id')->nullable();
            $table->unsignedInteger('sort_order')->default(0);
            $table->boolean('featured')->default(false);
            $table->foreign(['category_id', 'store_id'])->references(['id', 'store_id'])->on('catalog_categories');
        });
        foreach (DB::table('products')->select('store_id', 'category')->distinct()->get() as $category) {
            $id = DB::table('catalog_categories')->insertGetId(['store_id' => $category->store_id, 'name' => $category->category, 'created_at' => now(), 'updated_at' => now()]);
            DB::table('products')->where('store_id', $category->store_id)->where('category', $category->category)->update(['category_id' => $id]);
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->dropForeign(['category_id', 'store_id']);
            $table->dropColumn(['category_id', 'sort_order', 'featured']);
        });
        Schema::dropIfExists('catalog_categories');
    }
};
