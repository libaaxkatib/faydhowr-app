<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Foundation only, per docs/HRM_MARKETING_SRS.md §20-21/§39. `amount`
     * stays NULL and `status` stays 'pending_calculation' until management
     * approves a formula — no code computes a value from rate x anything in
     * this phase. Captures exactly what §20 asks for: employee, XARUN/
     * PROJECT brought, date, type, rate reference, status.
     */
    public function up(): void
    {
        Schema::create('commission_records', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('admin_id')->constrained('admins')->cascadeOnDelete();
            $table->foreignId('marketing_record_id')->constrained('marketing_records')->cascadeOnDelete();
            $table->string('type', 20);
            $table->date('reference_date');
            $table->foreignId('commission_rate_id')->nullable()->constrained('commission_rates')->nullOnDelete();
            $table->decimal('amount', 12, 2)->nullable();
            $table->string('status', 30)->default('pending_calculation');
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->index(['admin_id', 'reference_date']);
            $table->index('marketing_record_id');
        });

        if (DB::getDriverName() === 'pgsql') {
            DB::statement(
                'ALTER TABLE commission_records ADD CONSTRAINT commission_records_type_check '
                ."CHECK (type IN ('xarun', 'project'))",
            );
            DB::statement(
                'ALTER TABLE commission_records ADD CONSTRAINT commission_records_status_check '
                ."CHECK (status IN ('pending_calculation', 'calculated', 'approved', 'paid'))",
            );
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('commission_records');
    }
};
