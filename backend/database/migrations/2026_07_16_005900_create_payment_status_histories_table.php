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
        Schema::create('payment_status_histories', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('payment_id')
                ->constrained()
                ->restrictOnDelete();
            $table->string('status', 30);
            $table->string('changed_by_type', 20);
            $table->unsignedBigInteger('changed_by_id')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->index(['payment_id', 'created_at']);
        });

        if (DB::getDriverName() === 'pgsql') {
            DB::statement(
                'ALTER TABLE payment_status_histories ADD CONSTRAINT payment_status_histories_status_check '
                ."CHECK (status IN ('pending', 'initialized', 'processing', 'paid', 'failed', 'cancelled'))",
            );
            DB::statement(
                'ALTER TABLE payment_status_histories ADD CONSTRAINT payment_status_histories_actor_type_check '
                ."CHECK (changed_by_type IN ('user', 'admin', 'system'))",
            );
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('payment_status_histories');
    }
};
