<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Facility Inspections (Phase 2). Additive: new tables only, nothing existing is touched, no backfill.
 *
 *  - facility_inspection_schedules   how often a company/workplace is to be inspected
 *  - facility_inspections            one visit (date, inspector, notes, next follow-up); voided (never deleted) with a reason
 *  - facility_inspection_items       the twelve checked items of a visit: satisfactory / unsatisfactory (+ issue and corrective action)
 *  - facility_uniform_issues         employees of THAT company found without the uniform (warning, reason, action, follow-up)
 *  - facility_complaints             staff and customer complaints with their own status, response and explicit satisfaction
 *  - facility_complaint_events       the complete history of every complaint (who changed the status, when, with which note)
 *
 * History is kept: records are voided, not deleted. Foreign keys to companies and employees RESTRICT, so a company or person with
 * inspection history cannot disappear.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('facility_inspection_schedules', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('client_company_id')->constrained('client_companies')->restrictOnDelete();
            $table->unsignedSmallInteger('frequency_days');
            $table->unsignedSmallInteger('due_soon_days')->default(7);
            $table->text('notes')->nullable();
            $table->foreignId('set_by')->nullable()->constrained('admins')->nullOnDelete();
            $table->timestamps();

            $table->unique('client_company_id');
        });

        Schema::create('facility_inspections', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('client_company_id')->constrained('client_companies')->restrictOnDelete();
            $table->foreignId('work_location_id')->nullable()->constrained('work_locations')->nullOnDelete();
            $table->string('inspector_name', 150);
            $table->date('inspected_on');
            $table->text('general_notes')->nullable();
            $table->date('next_follow_up_on')->nullable();
            $table->foreignId('recorded_by')->nullable()->constrained('admins')->nullOnDelete();
            $table->timestamp('voided_at')->nullable();
            $table->foreignId('voided_by')->nullable()->constrained('admins')->nullOnDelete();
            $table->text('void_reason')->nullable();
            $table->timestamps();

            $table->index(['client_company_id', 'inspected_on']);
            $table->index('inspected_on');
        });

        Schema::create('facility_inspection_items', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('facility_inspection_id')->constrained('facility_inspections')->cascadeOnDelete();
            $table->string('item_key', 30);
            $table->boolean('applicable')->default(true);
            $table->string('result', 20)->nullable();
            $table->text('issue_description')->nullable();
            $table->text('corrective_action')->nullable();
            $table->timestamps();

            $table->unique(['facility_inspection_id', 'item_key']);
        });

        Schema::create('facility_uniform_issues', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('facility_inspection_id')->constrained('facility_inspections')->cascadeOnDelete();
            $table->foreignId('employee_id')->constrained('employees')->restrictOnDelete();
            $table->text('issue');
            $table->boolean('warning_given')->default(false);
            $table->text('reason_not_worn')->nullable();
            $table->text('corrective_action')->nullable();
            $table->date('follow_up_on')->nullable();
            $table->timestamps();

            $table->index('employee_id');
        });

        Schema::create('facility_complaints', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('client_company_id')->constrained('client_companies')->restrictOnDelete();
            $table->foreignId('facility_inspection_id')->nullable()->constrained('facility_inspections')->nullOnDelete();
            $table->string('type', 20);
            $table->text('details');
            $table->string('reporter_name', 150)->nullable();
            $table->string('reporter_type', 60)->nullable();
            $table->foreignId('reporter_employee_id')->nullable()->constrained('employees')->restrictOnDelete();
            $table->string('responsible_person', 150)->nullable();
            $table->text('response')->nullable();
            $table->date('reported_on');
            $table->date('responded_on')->nullable();
            $table->date('resolved_on')->nullable();
            $table->string('status', 20)->default('open');
            $table->string('satisfaction', 20)->nullable();
            $table->text('satisfaction_evidence')->nullable();
            $table->date('satisfaction_recorded_on')->nullable();
            $table->foreignId('recorded_by')->nullable()->constrained('admins')->nullOnDelete();
            $table->timestamp('voided_at')->nullable();
            $table->foreignId('voided_by')->nullable()->constrained('admins')->nullOnDelete();
            $table->text('void_reason')->nullable();
            $table->timestamps();

            $table->index(['client_company_id', 'status']);
            $table->index(['type', 'status']);
            $table->index('reported_on');
        });

        Schema::create('facility_complaint_events', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('facility_complaint_id')->constrained('facility_complaints')->cascadeOnDelete();
            $table->foreignId('admin_id')->nullable()->constrained('admins')->nullOnDelete();
            $table->string('event', 30);
            $table->string('from_status', 20)->nullable();
            $table->string('to_status', 20)->nullable();
            $table->text('note')->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->index('facility_complaint_id');
        });

        if (DB::getDriverName() === 'pgsql') {
            DB::statement("ALTER TABLE facility_inspection_items ADD CONSTRAINT facility_inspection_items_item_key_check CHECK (item_key IN ('windows','doors','cleaning_supplies','mirrors','stairs','walls','toilets','beds','plants','employee_uniforms','electrical_sockets','fans'))");
            DB::statement("ALTER TABLE facility_inspection_items ADD CONSTRAINT facility_inspection_items_result_check CHECK (result IS NULL OR result IN ('satisfactory','unsatisfactory'))");
            // A not-applicable item has no result; an applicable one always has one; an unsatisfactory one always says what is wrong and what will be done.
            DB::statement("ALTER TABLE facility_inspection_items ADD CONSTRAINT facility_inspection_items_consistency_check CHECK ((applicable = false AND result IS NULL) OR (applicable = true AND result = 'satisfactory') OR (applicable = true AND result = 'unsatisfactory' AND issue_description IS NOT NULL AND corrective_action IS NOT NULL))");
            DB::statement("ALTER TABLE facility_complaints ADD CONSTRAINT facility_complaints_type_check CHECK (type IN ('staff','customer'))");
            DB::statement("ALTER TABLE facility_complaints ADD CONSTRAINT facility_complaints_status_check CHECK (status IN ('open','in_progress','answered','resolved'))");
            DB::statement("ALTER TABLE facility_complaints ADD CONSTRAINT facility_complaints_satisfaction_check CHECK (satisfaction IS NULL OR (satisfaction IN ('satisfied','not_satisfied') AND satisfaction_evidence IS NOT NULL))");
            DB::statement('ALTER TABLE facility_inspection_schedules ADD CONSTRAINT facility_inspection_schedules_frequency_check CHECK (frequency_days BETWEEN 1 AND 730)');
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('facility_complaint_events');
        Schema::dropIfExists('facility_complaints');
        Schema::dropIfExists('facility_uniform_issues');
        Schema::dropIfExists('facility_inspection_items');
        Schema::dropIfExists('facility_inspections');
        Schema::dropIfExists('facility_inspection_schedules');
    }
};
