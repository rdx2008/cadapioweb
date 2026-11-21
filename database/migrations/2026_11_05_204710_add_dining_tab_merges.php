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
        Schema::table('dining_tabs', function (Blueprint $table) {
            $table->foreignId('merged_into_tab_id')->nullable()->constrained('dining_tabs')->restrictOnDelete();
        });
        Schema::create('dining_merges', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->restrictOnDelete();
            $table->foreignId('store_id')->constrained()->restrictOnDelete();
            $table->foreignId('source_tab_id')->unique()->constrained('dining_tabs')->restrictOnDelete();
            $table->foreignId('target_tab_id')->constrained('dining_tabs')->restrictOnDelete();
            $table->foreignId('user_id')->constrained('users')->restrictOnDelete();
            $table->string('from_name', 80);
            $table->string('to_name', 80);
            $table->string('reason', 500);
            $table->string('source_hash', 64);
            $table->string('target_hash', 64);
            $table->uuid('request_key')->unique();
            $table->timestamp('created_at');
            $table->index(['target_tab_id', 'id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (DB::table('dining_merges')->exists()) {
            throw new RuntimeException('Há comandas unidas. Mantenha esta migração para preservar o histórico e os saldos.');
        }
        Schema::dropIfExists('dining_merges');
        Schema::table('dining_tabs', fn (Blueprint $table) => $table->dropConstrainedForeignId('merged_into_tab_id'));
    }
};
