<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * HRM Phase 1 (docs/HRM_MARKETING_SRS.md HR §10-11): links assessments to a
 * practical_batches session, adds attempt_number so "Ku Celis Practical"
 * repeats are visible as separate, ordered attempts, and widens the result
 * CHECK constraint to the three canonical decision outcomes (approved,
 * rejected, ku_celis_practical) - the same drop-and-recreate pattern already
 * used by 2026_09_02_120000_widen_admin_roles_for_hr_and_marketing.php.
 * The legacy pass/fail/pending values are kept, not removed, since dropping
 * them serves no purpose and this migration must stay additive.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('employee_practical_assessments', function (Blueprint $table): void {
            $table->foreignId('practical_batch_id')->nullable()->after('employee_id')
                ->constrained('practical_batches')->nullOnDelete();
            $table->unsignedSmallInteger('attempt_number')->nullable()->after('result');
        });

        if (DB::getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE employee_practical_assessments DROP CONSTRAINT IF EXISTS employee_practical_assessments_result_check');
            DB::statement(
                'ALTER TABLE employee_practical_assessments ADD CONSTRAINT employee_practical_assessments_result_check '
                ."CHECK (result IN ('pass', 'fail', 'pending', 'approved', 'rejected', 'ku_celis_practical'))",
            );
        }
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE employee_practical_assessments DROP CONSTRAINT IF EXISTS employee_practical_assessments_result_check');
            DB::statement(
                'ALTER TABLE employee_practical_assessments ADD CONSTRAINT employee_practical_assessments_result_check '
                ."CHECK (result IN ('pass', 'fail', 'pending'))",
            );
        }

        Schema::table('employee_practical_assessments', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('practical_batch_id');
            $table->dropColumn('attempt_number');
        });
    }
};
