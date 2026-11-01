<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->string('pos_channel', 20)->nullable();
            $table->foreignId('preferred_driver_id')->nullable()->constrained('drivers');
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dropConstrainedForeignId('preferred_driver_id');
            $table->dropColumn('pos_channel');
        });
    }
};
