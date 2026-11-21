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
        Schema::table('financial_accounts', function (Blueprint $table) {
            $table->uuid('installment_group')->nullable();
            $table->unsignedSmallInteger('installment_number')->nullable();
            $table->unsignedSmallInteger('installment_count')->nullable();
            $table->unique(['installment_group', 'installment_number'], 'financial_installment_unique');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (DB::table('financial_accounts')->whereNotNull('installment_group')->exists()) {
            throw new RuntimeException('Existem parcelas cadastradas. Preserve os vínculos financeiros.');
        }
        Schema::table('financial_accounts', function (Blueprint $table) {
            $table->dropUnique('financial_installment_unique');
            $table->dropColumn(['installment_group', 'installment_number', 'installment_count']);
        });
    }
};
