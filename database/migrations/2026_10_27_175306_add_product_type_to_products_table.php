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
        Schema::table('products', function (Blueprint $table) {
            $table->string('product_type', 20)->default('common');
        });
        DB::table('products')->where(function ($query) {
            $query->where('pizza_enabled', true)->orWhereIn('id', DB::table('pizza_flavors')->select('product_id'))->orWhereIn('id', DB::table('option_groups')->where('kind', 'border')->select('product_id'));
        })->update(['product_type' => 'pizza']);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->dropColumn('product_type');
        });
    }
};
