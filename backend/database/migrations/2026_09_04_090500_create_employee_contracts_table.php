<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * HRM Phase 1 (docs/HRM_MARKETING_SRS.md HR §28): Employee Contract /
 * Agreement, a distinct pipeline stage from Guarantor per the business
 * decision confirmed for this phase (Contract hard-blocks Uniform the same
 * way Uniform hard-blocks Training). A renewed/replaced contract is always a
 * new row - signed historical contracts are never overwritten.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('employee_contracts', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('employee_id')->constrained('employees')->cascadeOnDelete();
            $table->string('contract_type', 50)->default('standard');
            $table->string('contract_number', 100)->nullable();
            $table->date('date_issued')->nullable();
            $table->date('start_date')->nullable();
            $table->date('end_date')->nullable();
            $table->string('status', 20)->default('draft');
            $table->date('signed_date')->nullable();
            $table->foreignId('signed_document_id')->nullable()->constrained('employee_documents')->nullOnDelete();
            $table->text('notes')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('admins')->nullOnDelete();
            $table->timestamps();

            $table->index('employee_id');
        });

        if (DB::getDriverName() === 'pgsql') {
            DB::statement(
                'ALTER TABLE employee_contracts ADD CONSTRAINT employee_contracts_status_check '
                ."CHECK (status IN ('draft', 'issued', 'signed', 'verified', 'expired', 'cancelled'))",
            );
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('employee_contracts');
    }
};
