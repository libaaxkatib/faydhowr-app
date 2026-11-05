<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * HR Release 1B: record integrity for penalties and one-month advances.
 *
 * Additive only. Every column is nullable or has a default, so rows written before this release (production has none) stay valid and keep meaning
 * what they meant: status 'active', version 1, nothing waived, no idempotency key.
 *
 *  - idempotency_key: the same request sent twice records ONE row (unique when present).
 *  - status / version / voided_*: a record is never deleted or overwritten; a void or a correction leaves the original row behind (status 'voided') and a
 *    correction adds a replacement row that points back at it (replaces_id). version lets a screen that went stale be refused instead of overwriting.
 *  - waived_*: a Super Admin may waive what is still owed after part of the record was deducted (the part already deducted is never rewritten).
 *  - recorded_by_role: who registered it, as a role, for the audit trail.
 *
 * What was deducted so far is NOT stored here: it is read from the stored payroll lines of finalized / approved company payrolls (DeductionBalances), so
 * reopening a company's payroll automatically puts the amount back as owed and nothing can be applied twice.
 */
return new class extends Migration
{
    private const TABLES = ['employee_penalties', 'employee_advances'];

    public function up(): void
    {
        foreach (self::TABLES as $name) {
            Schema::table($name, function (Blueprint $table): void {
                $table->string('idempotency_key', 100)->nullable()->unique();
                $table->string('status', 16)->default('active');
                $table->unsignedInteger('version')->default(1);
                $table->timestampTz('voided_at')->nullable();
                $table->unsignedBigInteger('voided_by')->nullable();
                $table->text('void_reason')->nullable();
                $table->unsignedBigInteger('replaces_id')->nullable();
                $table->unsignedBigInteger('waived_cents')->default(0);
                $table->timestampTz('waived_at')->nullable();
                $table->unsignedBigInteger('waived_by')->nullable();
                $table->text('waive_reason')->nullable();
                $table->string('recorded_by_role', 32)->nullable();
                $table->index(['payroll_period', 'status']);
            });
        }
    }

    public function down(): void
    {
        foreach (self::TABLES as $name) {
            Schema::table($name, function (Blueprint $table) use ($name): void {
                $table->dropIndex($name.'_payroll_period_status_index');
                $table->dropUnique($name.'_idempotency_key_unique');
                $table->dropColumn(['idempotency_key', 'status', 'version', 'voided_at', 'voided_by', 'void_reason', 'replaces_id', 'waived_cents', 'waived_at', 'waived_by', 'waive_reason', 'recorded_by_role']);
            });
        }
    }
};
