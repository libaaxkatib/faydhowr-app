<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Where an employee's payroll/payment number came from, and whether it agrees with the registration phone in the Employee Register.
 *
 * The number itself stays in employees.payment_account_number (a varchar, so leading zeros and every character survive). These three
 * nullable columns only describe it; they are never read as a payment number, and the registration phone, the employee number and the
 * internal id are never touched. No backfill: existing rows keep NULL ("source not recorded"), which the exports show as such.
 *
 *  - payment_account_source        'workbook_part_one' | 'workbook_part_two' | 'manual'
 *  - payment_account_source_ref    e.g. "EMPLOYEE AND COMPANY.xlsx / Sheet1 / row 12" (free text, informational)
 *  - payment_account_register_check 'match' (agrees with the registration phone), 'mismatch' (differs: flagged for review, the source
 *                                   value is kept) or 'accepted' (a person reviewed the mismatch and accepted it)
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('employees', function (Blueprint $table): void {
            $table->string('payment_account_source', 40)->nullable()->after('payment_account_number');
            $table->string('payment_account_source_ref', 150)->nullable()->after('payment_account_source');
            $table->string('payment_account_register_check', 20)->nullable()->after('payment_account_source_ref');
        });

        if (DB::getDriverName() === 'pgsql') {
            DB::statement(
                "ALTER TABLE employees ADD CONSTRAINT employees_payment_account_register_check_check CHECK (payment_account_register_check IS NULL OR payment_account_register_check IN ('match', 'mismatch', 'accepted'))",
            );
        }
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE employees DROP CONSTRAINT IF EXISTS employees_payment_account_register_check_check');
        }

        Schema::table('employees', function (Blueprint $table): void {
            $table->dropColumn(['payment_account_source', 'payment_account_source_ref', 'payment_account_register_check']);
        });
    }
};
