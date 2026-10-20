<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Tells the people registered BEFORE the strict registration workflow apart from the ones registered after it.
 *
 *  - 'existing'     every employee that is already in the system when this migration runs (archived ones included). They stay manageable
 *                   one by one: an authorized user can move them to a stage (Waiting List, Training, Practice ...) with a reason.
 *  - 'new'          the default for everybody registered afterwards: they must complete guarantor, contract, uniform, training and practice
 *                   (each with its record) before they can be Waiting, Active or added to a company.
 *  - 'office_staff' people registered directly as Office Staff (hired, not recruited - an already approved separate rule).
 *
 * Additive: one nullable-free column with a default; the single UPDATE only sets the new column on rows that exist now. No other column of any
 * employee, and no other table, is read or changed.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('employees', function (Blueprint $table): void {
            $table->string('registration_origin', 20)->default('new')->after('status');
        });

        // Everyone registered up to now is an existing record.
        DB::table('employees')->update(['registration_origin' => 'existing']);

        if (DB::getDriverName() === 'pgsql') {
            DB::statement("ALTER TABLE employees ADD CONSTRAINT employees_registration_origin_check CHECK (registration_origin IN ('existing', 'new', 'office_staff'))");
        }
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE employees DROP CONSTRAINT IF EXISTS employees_registration_origin_check');
        }

        Schema::table('employees', function (Blueprint $table): void {
            $table->dropColumn('registration_origin');
        });
    }
};
