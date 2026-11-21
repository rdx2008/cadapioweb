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
        Schema::table('orders', function (Blueprint $table) {
            $table->string('dining_person', 80)->nullable();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (DB::table('orders')->whereNotNull('dining_person')->exists()) {
            throw new RuntimeException('Preserve a identificação do consumo por pessoa antes de reverter esta migração.');
        }
        Schema::table('orders', function (Blueprint $table) {
            $table->dropColumn('dining_person');
        });
    }
};
