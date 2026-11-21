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
        Schema::table('products', function (Blueprint $table) {
            $table->boolean('pizza_enabled')->default(false);
            $table->string('pizza_pricing', 20)->default('highest');
        });
        Schema::table('product_variants', function (Blueprint $table) {
            $table->unsignedSmallInteger('max_flavors')->default(1);
        });
        Schema::table('option_groups', function (Blueprint $table) {
            $table->string('kind', 20)->default('addon');
        });
        Schema::create('pizza_flavors', function (Blueprint $table) {
            $table->id();
            $table->foreignId('product_id')->constrained()->cascadeOnDelete();
            $table->string('name', 80);
            $table->string('description', 240)->nullable();
            $table->json('prices');
            $table->boolean('available')->default(true);
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('pizza_flavors');
        Schema::table('option_groups', fn (Blueprint $table) => $table->dropColumn('kind'));
        Schema::table('product_variants', fn (Blueprint $table) => $table->dropColumn('max_flavors'));
        Schema::table('products', fn (Blueprint $table) => $table->dropColumn(['pizza_enabled', 'pizza_pricing']));
    }
};
