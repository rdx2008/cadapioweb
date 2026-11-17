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
        Schema::create('financial_recurrences', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->restrictOnDelete();
            $table->foreignId('store_id')->constrained()->restrictOnDelete();
            $table->string('direction', 12);
            $table->string('description', 160);
            $table->string('counterparty', 120);
            $table->string('category', 25);
            $table->unsignedBigInteger('amount_cents');
            $table->string('notes', 500)->nullable();
            $table->date('first_due_on');
            $table->date('end_on')->nullable();
            $table->unsignedInteger('next_index')->default(0);
            $table->boolean('enabled')->default(true);
            $table->unsignedInteger('revision')->default(1);
            $table->uuid('request_key')->unique();
            $table->string('request_hash', 64);
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->timestamp('last_run_at')->nullable();
            $table->timestamps();
            $table->index(['tenant_id', 'store_id', 'enabled']);
        });
        Schema::table('financial_accounts', function (Blueprint $table) {
            $table->foreignId('recurrence_id')->nullable()->constrained('financial_recurrences')->restrictOnDelete();
            $table->date('recurrence_occurrence')->nullable();
            $table->unsignedInteger('recurrence_revision')->nullable();
            $table->unique(['recurrence_id', 'recurrence_occurrence'], 'financial_recurrence_occurrence_unique');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (DB::table('financial_recurrences')->exists()) {
            throw new RuntimeException('Preserve as programações financeiras e seu histórico.');
        }
        Schema::table('financial_accounts', function (Blueprint $table) {
            $table->dropUnique('financial_recurrence_occurrence_unique');
            $table->dropConstrainedForeignId('recurrence_id');
            $table->dropColumn(['recurrence_occurrence', 'recurrence_revision']);
        });
        Schema::dropIfExists('financial_recurrences');
    }
};
