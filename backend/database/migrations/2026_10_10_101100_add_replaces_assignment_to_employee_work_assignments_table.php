<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Permanent succession: when a Permanent employee leaves and a new Permanent
 * employee takes over the same post, the new assignment can point at the one
 * it replaces. That link is what lets payroll prove there is no overlap
 * (two people paid for the same calendar day) and report any gap. It is
 * optional and never deleted with the predecessor (history is preserved), and
 * it is unrelated to Temporary replacements, which cover a still-employed
 * person and are charged through the workday ledger.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('employee_work_assignments', function (Blueprint $table): void {
            $table->foreignId('replaces_assignment_id')->nullable()->after('client_company_id')
                ->constrained('employee_work_assignments')->restrictOnDelete();
            $table->index('replaces_assignment_id');
        });
    }

    public function down(): void
    {
        Schema::table('employee_work_assignments', function (Blueprint $table): void {
            $table->dropForeign(['replaces_assignment_id']);
            $table->dropIndex(['replaces_assignment_id']);
            $table->dropColumn('replaces_assignment_id');
        });
    }
};
