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
        Schema::create('payment_transactions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('payment_id')
                ->constrained()
                ->restrictOnDelete();
            $table->string('gateway', 50);
            $table->string('transaction_reference', 191)->nullable();
            $table->jsonb('request_payload')->nullable();
            $table->jsonb('response_payload')->nullable();
            $table->string('status', 30);
            $table->timestampTz('processed_at')->nullable();
            $table->timestamps();

            $table->index(['payment_id', 'created_at']);
            $table->unique(['gateway', 'transaction_reference']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('payment_transactions');
    }
};
