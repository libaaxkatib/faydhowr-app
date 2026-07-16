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
        Schema::create('order_status_histories', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('order_id')
                ->constrained()
                ->restrictOnDelete();
            $table->string('status', 30);
            $table->string('changed_by_type', 20);
            $table->unsignedBigInteger('changed_by_id')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->index(['order_id', 'created_at']);
        });

        if (DB::getDriverName() === 'pgsql') {
            DB::statement(
                "ALTER TABLE order_status_histories ADD CONSTRAINT order_status_histories_status_check "
                ."CHECK (status IN ('pending_payment', 'confirmed', 'processing', 'completed', 'cancelled'))",
            );
            DB::statement(
                "ALTER TABLE order_status_histories ADD CONSTRAINT order_status_histories_actor_type_check "
                ."CHECK (changed_by_type IN ('user', 'admin', 'system'))",
            );
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('order_status_histories');
    }
};
