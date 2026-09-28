<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * HR Excel migration (write-mode): the real REGISTRATION workbook has people
 * with no reliable phone number and/or no reliable application date (see
 * ExcelMigrationService — phone recovery and RegistrationDateInferrer both
 * still leave a residual group with neither, who must still be importable
 * rather than fabricated). employee_separations.separation_date has the same
 * problem for Cancelled people with no reliable date. Confirmed business
 * decision: alter all three to nullable rather than invent values.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('employees', function (Blueprint $table): void {
            $table->string('phone', 40)->nullable()->change();
            $table->date('application_date')->nullable()->change();
        });

        Schema::table('employee_separations', function (Blueprint $table): void {
            $table->date('separation_date')->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('employees', function (Blueprint $table): void {
            $table->string('phone', 40)->nullable(false)->change();
            $table->date('application_date')->nullable(false)->change();
        });

        Schema::table('employee_separations', function (Blueprint $table): void {
            $table->date('separation_date')->nullable(false)->change();
        });
    }
};
