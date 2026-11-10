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
            $table->foreignId('current_table_id')->nullable()->constrained('dining_tables')->restrictOnDelete();
        });
        DB::table('dining_tabs')->update(['current_table_id' => DB::raw('dining_table_id')]);
        Schema::create('dining_transfers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->restrictOnDelete();
            $table->foreignId('store_id')->constrained()->restrictOnDelete();
            $table->foreignId('dining_tab_id')->constrained()->restrictOnDelete();
            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            $table->foreignId('from_table_id')->constrained('dining_tables')->restrictOnDelete();
            $table->foreignId('to_table_id')->constrained('dining_tables')->restrictOnDelete();
            $table->string('from_name', 80);
            $table->string('to_name', 80);
            $table->string('reason', 500);
            $table->uuid('request_key')->unique();
            $table->timestamp('created_at');
            $table->index(['dining_tab_id', 'id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        DB::table('dining_tabs')->whereNotNull('current_table_id')->update(['dining_table_id' => DB::raw('current_table_id')]);
        Schema::dropIfExists('dining_transfers');
        Schema::table('dining_tabs', fn (Blueprint $table) => $table->dropConstrainedForeignId('current_table_id'));
    }
};
