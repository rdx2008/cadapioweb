<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('catalog_imports', function (Blueprint $table) {
            $table->id();
            $table->uuid('reference')->unique();
            $table->foreignId('tenant_id')->constrained();
            $table->foreignId('store_id')->constrained();
            $table->foreignId('user_id')->constrained();
            $table->string('filename', 180);
            $table->string('fingerprint', 64);
            $table->string('state', 20)->default('draft');
            $table->unsignedInteger('revision')->default(1);
            $table->longText('payload')->nullable();
            $table->json('result')->nullable();
            $table->timestamp('expires_at');
            $table->timestamps();
            $table->index(['tenant_id', 'store_id', 'id']);
        });
        Schema::table('products', fn (Blueprint $table) => $table->boolean('combo_fixed')->default(false));
        Schema::table('combo_options', function (Blueprint $table) {
            $table->unsignedBigInteger('component_product_id')->nullable()->change();
            $table->string('label', 120)->nullable();
        });
    }

    public function down(): void
    {
        if (DB::table('combo_options')->whereNull('component_product_id')->exists()) {
            throw new RuntimeException('Exporte ou remova as opções próprias dos combos antes de reverter esta migration.');
        }
        Schema::table('combo_options', fn (Blueprint $table) => $table->unsignedBigInteger('component_product_id')->nullable(false)->change());
        Schema::dropIfExists('catalog_imports');
        Schema::table('combo_options', fn (Blueprint $table) => $table->dropColumn('label'));
        Schema::table('products', fn (Blueprint $table) => $table->dropColumn('combo_fixed'));
    }
};
