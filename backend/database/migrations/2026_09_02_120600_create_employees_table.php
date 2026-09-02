<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Field set reconciled against the real employee-registration spreadsheet
     * (structure only, not its personal-data rows) rather than invented — see
     * docs/HRM_MARKETING_SRS.md §25/§39 and the HRM implementation report for
     * the reconciliation notes (age not date-of-birth, no gender column; adds
     * marital_status/lives_with/reference_name/training_fee which the SRS's
     * own sketch omitted but the real source uses consistently).
     */
    public function up(): void
    {
        Schema::create('employees', function (Blueprint $table): void {
            $table->id();
            $table->string('employee_number', 20)->unique();
            $table->string('full_name', 150);
            $table->string('phone', 40);
            $table->string('alternate_phone', 40)->nullable();
            $table->string('location', 150)->nullable();
            $table->unsignedSmallInteger('age')->nullable();
            $table->string('marital_status', 50)->nullable();
            $table->string('lives_with', 150)->nullable();
            $table->string('reference_name', 150)->nullable();

            $table->foreignId('employee_category_id')->constrained('employee_categories')->restrictOnDelete();
            $table->foreignId('department_id')->nullable()->constrained('departments')->nullOnDelete();
            $table->foreignId('position_id')->nullable()->constrained('positions')->nullOnDelete();

            $table->string('status', 20)->default('applicant');
            $table->timestampTz('guarantor_confirmed_at')->nullable();

            $table->date('application_date');
            $table->date('joining_date')->nullable();
            $table->text('experience')->nullable();
            $table->decimal('training_fee_amount', 10, 2)->nullable();
            $table->string('training_fee_status', 30)->nullable();
            $table->string('source', 150)->nullable();
            $table->text('notes')->nullable();

            $table->foreignId('created_by')->nullable()->constrained('admins')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->index('status');
            $table->index('employee_category_id');
            $table->index('department_id');
            $table->index('application_date');
        });

        if (DB::getDriverName() === 'pgsql') {
            DB::statement(
                'ALTER TABLE employees ADD CONSTRAINT employees_status_check '
                ."CHECK (status IN ('applicant', 'recruitment', 'practical', 'waiting', "
                ."'approved', 'active', 'inactive'))",
            );
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('employees');
    }
};
