<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('rides', function (Blueprint $table) {
            $table->string('received_by', 100)->nullable();
            $table->string('proof_photo_path')->nullable();
            $table->timestamp('proof_recorded_at')->nullable();
        });
        Schema::create('delivery_incidents', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained();
            $table->foreignId('ride_id')->constrained('rides');
            $table->foreignId('author_id')->constrained('users');
            $table->uuid('request_key');
            $table->string('kind', 30);
            $table->text('description');
            $table->string('photo_path')->nullable();
            $table->string('status', 20)->default('open');
            $table->text('resolution')->nullable();
            $table->foreignId('resolved_by')->nullable()->constrained('users');
            $table->timestamp('resolved_at')->nullable();
            $table->timestamps();
            $table->unique(['ride_id', 'request_key']);
            $table->index(['tenant_id', 'status', 'id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('delivery_incidents');
        Schema::table('rides', fn (Blueprint $table) => $table->dropColumn(['received_by', 'proof_photo_path', 'proof_recorded_at']));
    }
};
