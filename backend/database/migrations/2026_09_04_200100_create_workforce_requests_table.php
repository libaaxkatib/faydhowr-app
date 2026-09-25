<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * HRM Phase 2 (Waiting & Company Matching): the demand side of staffing -
 * a client company (via one of its work_locations, or Fayadhowr Office)
 * asking for N workers matching optional criteria. client_company_id is
 * intentionally omitted; it's always reachable via work_location.client_company_id.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('workforce_requests', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('work_location_id')->constrained('work_locations')->restrictOnDelete();
            $table->foreignId('employee_category_id')->nullable()->constrained('employee_categories')->nullOnDelete();
            $table->foreignId('position_id')->nullable()->constrained('positions')->nullOnDelete();

            $table->string('gender_requirement', 10)->nullable();
            $table->unsignedSmallInteger('quantity_needed')->default(1);
            $table->string('status', 20)->default('open');
            $table->date('requested_date');
            $table->text('notes')->nullable();

            $table->foreignId('created_by')->nullable()->constrained('admins')->nullOnDelete();
            $table->timestamps();

            $table->index(['work_location_id', 'status']);
        });

        if (DB::getDriverName() === 'pgsql') {
            DB::statement(
                'ALTER TABLE workforce_requests ADD CONSTRAINT workforce_requests_gender_requirement_check '
                ."CHECK (gender_requirement IN ('male', 'female'))",
            );
            DB::statement(
                'ALTER TABLE workforce_requests ADD CONSTRAINT workforce_requests_status_check '
                ."CHECK (status IN ('open', 'partially_filled', 'fulfilled', 'cancelled'))",
            );
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('workforce_requests');
    }
};
