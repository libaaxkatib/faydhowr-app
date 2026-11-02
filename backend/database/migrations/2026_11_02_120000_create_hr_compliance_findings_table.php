<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * HR compliance and accountability report: one row per underlying issue (employee + rule + assignment), kept so the HR Manager can track it.
 * The facts (what is missing, who is responsible, the dates) are recomputed from the live records on every scan; this table holds the TRACKING of
 * each issue - when it was first detected, its resolution status, the follow-up note and who changed it. `finding_key` is unique, so one issue is
 * one row however many times it is scanned. Nothing existing is altered and nothing here changes an employee, assignment or payroll record.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('hr_compliance_findings', function (Blueprint $table): void {
            $table->id();
            $table->string('finding_key', 120)->unique();
            $table->string('category', 30);
            $table->string('code', 60);
            $table->string('severity', 10);
            $table->string('confidence', 15);
            $table->unsignedBigInteger('employee_id');
            $table->unsignedBigInteger('assignment_id')->nullable();
            $table->unsignedBigInteger('client_company_id')->nullable();
            $table->string('employee_name', 150);
            $table->string('employee_number', 30);
            $table->string('company_name', 150)->nullable();
            $table->string('position_name', 150)->nullable();
            $table->string('shift', 20)->nullable();
            $table->string('employment_type', 15);
            $table->string('employment_status', 20);
            $table->string('assignment_status', 20)->nullable();
            $table->text('requirement');
            $table->text('explanation');
            $table->text('recommended_action');
            $table->json('details')->nullable();
            $table->foreignId('responsible_admin_id')->nullable()->constrained('admins')->nullOnDelete();
            $table->string('responsible_action', 120)->nullable();
            $table->timestamp('responsible_at')->nullable();
            $table->date('appointed_on')->nullable();
            $table->timestamp('first_detected_at');
            $table->timestamp('last_detected_at');
            $table->string('status', 15)->default('open');
            $table->string('resolution_source', 15)->nullable();
            $table->timestamp('resolved_at')->nullable();
            $table->foreignId('resolved_by')->nullable()->constrained('admins')->nullOnDelete();
            $table->text('note')->nullable();
            $table->foreignId('updated_by')->nullable()->constrained('admins')->nullOnDelete();
            $table->timestamps();

            $table->index('status');
            $table->index('category');
            $table->index('severity');
            $table->index('employee_id');
            $table->index('client_company_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('hr_compliance_findings');
    }
};
