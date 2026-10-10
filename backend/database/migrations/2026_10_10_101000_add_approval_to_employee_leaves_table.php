<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Approval for leave. Unpaid leave reduces payroll only once it is APPROVED, so
 * it needs a real approval state. Every existing leave row is marked approved
 * (it was effective when recorded), so nothing already on file changes
 * meaning. The decision (who, when, note) is kept on the row, and leave rows
 * are never deleted; the audit log keeps the full decision history.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('employee_leaves', function (Blueprint $table): void {
            $table->string('approval_status', 20)->default('approved')->after('leave_type');
            $table->foreignId('decided_by')->nullable()->after('approval_status')->constrained('admins')->nullOnDelete();
            $table->timestampTz('decided_at')->nullable()->after('decided_by');
            $table->text('decision_note')->nullable()->after('decided_at');
            $table->index(['leave_type', 'approval_status', 'start_date']);
        });

        if (DB::getDriverName() === 'pgsql') {
            DB::statement(
                'ALTER TABLE employee_leaves ADD CONSTRAINT employee_leaves_approval_status_check '
                ."CHECK (approval_status IN ('pending', 'approved', 'rejected'))",
            );
        }
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE employee_leaves DROP CONSTRAINT IF EXISTS employee_leaves_approval_status_check');
        }

        Schema::table('employee_leaves', function (Blueprint $table): void {
            $table->dropIndex(['leave_type', 'approval_status', 'start_date']);
            $table->dropConstrainedForeignId('decided_by');
            $table->dropColumn(['approval_status', 'decided_at', 'decision_note']);
        });
    }
};
