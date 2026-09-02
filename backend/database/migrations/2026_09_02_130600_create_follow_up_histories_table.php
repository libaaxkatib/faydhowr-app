<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('follow_up_histories', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('follow_up_id')->constrained('follow_ups')->cascadeOnDelete();
            $table->string('action', 30);
            $table->text('note')->nullable();
            $table->foreignId('performed_by')->nullable()->constrained('admins')->nullOnDelete();
            $table->timestamps();

            $table->index(['follow_up_id', 'created_at']);
        });

        if (DB::getDriverName() === 'pgsql') {
            DB::statement(
                'ALTER TABLE follow_up_histories ADD CONSTRAINT follow_up_histories_action_check '
                ."CHECK (action IN ('created', 'rescheduled', 'completed', 'feedback_updated', 'status_updated'))",
            );
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('follow_up_histories');
    }
};
