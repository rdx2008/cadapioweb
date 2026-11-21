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
        Schema::table('dining_settlements', function (Blueprint $table) {
            $table->string('person_key', 64)->nullable();
            $table->string('person_label', 160)->nullable();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (DB::table('dining_settlements')->whereNotNull('person_key')->exists()) {
            throw new RuntimeException('Preserve os recebimentos por pessoa antes de reverter esta migração.');
        }
        Schema::table('dining_settlements', function (Blueprint $table) {
            $table->dropColumn(['person_key', 'person_label']);
        });
    }
};
