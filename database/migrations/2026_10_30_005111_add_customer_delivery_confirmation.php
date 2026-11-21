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
        Schema::table('rides', function (Blueprint $table) {
            $table->boolean('confirmation_required')->default(false);
            $table->text('confirmation_cipher')->nullable();
            $table->timestamp('confirmation_verified_at')->nullable();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('rides', fn (Blueprint $table) => $table->dropColumn(['confirmation_required', 'confirmation_cipher', 'confirmation_verified_at']));
    }
};
