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
        Schema::create('combo_groups', function (Blueprint $table) {
            $table->id();
            $table->foreignId('product_id')->constrained()->cascadeOnDelete();
            $table->string('name', 100);
            $table->unsignedSmallInteger('minimum')->default(1);
            $table->unsignedSmallInteger('maximum')->default(1);
            $table->timestamps();
        });
        Schema::create('combo_options', function (Blueprint $table) {
            $table->id();
            $table->foreignId('combo_group_id')->constrained()->cascadeOnDelete();
            $table->foreignId('component_product_id')->constrained('products');
            $table->foreignId('component_variant_id')->nullable()->constrained('product_variants');
            $table->unsignedSmallInteger('quantity')->default(1);
            $table->unsignedInteger('surcharge_cents')->default(0);
            $table->boolean('available')->default(true);
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('combo_options');
        Schema::dropIfExists('combo_groups');
    }
};
