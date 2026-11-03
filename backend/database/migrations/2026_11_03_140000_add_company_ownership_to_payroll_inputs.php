<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Each company owns its own payroll (owner decision 2026-11-03). A person can work for two companies in one month, and a penalty, an advance, an
 * unpaid leave, an absence or an overpayment recovery must be charged to the company it belongs to - never to "the person's first payroll line" and
 * never spread over both. So every one of these records now says which company it belongs to.
 *
 * Additive only: one nullable column (client_company_id) on each of employee_penalties, employee_advances, employee_leaves, employee_attendances
 * and payroll_corrections. Existing rows are NOT rewritten (production has none of these records): a record without a company is "legacy" and is
 * charged only where it is unambiguous (the person works for exactly one company that month); otherwise it is reported and not charged anywhere.
 *
 * Attendance was unique per employee and day. A person who works for two companies can be absent from one of them on a day, so the day's mark is
 * now unique per employee, day AND company (expression index; a mark without a company keeps the same one-per-day behaviour as before).
 */
return new class extends Migration
{
    private const TABLES = ['employee_penalties', 'employee_advances', 'employee_leaves', 'employee_attendances', 'payroll_corrections'];

    public function up(): void
    {
        foreach (self::TABLES as $table) {
            Schema::table($table, function (Blueprint $blueprint): void {
                $blueprint->foreignId('client_company_id')->nullable()->constrained('client_companies')->restrictOnDelete();
            });
        }

        Schema::table('employee_attendances', function (Blueprint $table): void {
            $table->dropUnique('employee_attendances_employee_id_date_unique');
        });
        DB::statement('CREATE UNIQUE INDEX employee_attendances_employee_date_company_unique ON employee_attendances (employee_id, date, COALESCE(client_company_id, 0))');
    }

    public function down(): void
    {
        DB::statement('DROP INDEX employee_attendances_employee_date_company_unique');

        Schema::table('employee_attendances', function (Blueprint $table): void {
            $table->unique(['employee_id', 'date']);
        });

        foreach (array_reverse(self::TABLES) as $table) {
            Schema::table($table, function (Blueprint $blueprint): void {
                $blueprint->dropConstrainedForeignId('client_company_id');
            });
        }
    }
};
