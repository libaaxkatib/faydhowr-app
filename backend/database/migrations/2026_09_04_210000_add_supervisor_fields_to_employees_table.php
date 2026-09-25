<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * HRM Phase 3 (Workforce Operations): Supervisor Pool is a cross-cutting flag,
 * not an employee_category - an employee keeps their existing category while
 * also being marked eligible/designated as a supervisor.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('employees', function (Blueprint $table): void {
            $table->boolean('is_supervisor')->default(false)->after('waiting_since');
            $table->timestampTz('supervisor_since')->nullable()->after('is_supervisor');
            $table->index('is_supervisor');
        });
    }

    public function down(): void
    {
        Schema::table('employees', function (Blueprint $table): void {
            $table->dropIndex(['is_supervisor']);
            $table->dropColumn(['is_supervisor', 'supervisor_since']);
        });
    }
};
