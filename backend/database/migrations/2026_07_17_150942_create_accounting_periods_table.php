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
        Schema::create('accounting_periods', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->date('start_date');
            $table->date('end_date');
            $table->string('status', 10);
            $table->timestamp('closed_at')->nullable();
            $table->foreignId('closed_by')->nullable()->constrained('admins')->nullOnDelete();
            $table->timestamps();

            $table->index(['status']);
            $table->index(['start_date', 'end_date']);
        });

        if (DB::getDriverName() === 'pgsql') {
            DB::statement(
                'ALTER TABLE accounting_periods ADD CONSTRAINT accounting_periods_status_check '
                ."CHECK (status IN ('open', 'closed'))",
            );
            DB::statement(
                'ALTER TABLE accounting_periods ADD CONSTRAINT accounting_periods_date_range_check '
                .'CHECK (end_date >= start_date)',
            );
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('accounting_periods');
    }
};
