<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * HRM Phase 1 (docs/HRM_MARKETING_SRS.md HR §6): Damiin Qaadis / Guarantor
 * as a real structured entity - one row per employee - superseding the
 * existing employees.guarantor_confirmed_at timestamp as the source of
 * truth (that column is kept for backward compatibility; see
 * VerifyEmployeeGuarantorAction).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('employee_guarantors', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('employee_id')->unique()->constrained('employees')->cascadeOnDelete();
            $table->string('guarantor_name', 150)->nullable();
            $table->string('guarantor_phone', 40)->nullable();
            $table->string('relationship', 100)->nullable();
            $table->text('other_info')->nullable();
            $table->date('collected_date')->nullable();
            $table->timestampTz('verified_at')->nullable();
            $table->foreignId('verified_by')->nullable()->constrained('admins')->nullOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('admins')->nullOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('employee_guarantors');
    }
};
