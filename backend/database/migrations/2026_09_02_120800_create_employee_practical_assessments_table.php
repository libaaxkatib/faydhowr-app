<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('employee_practical_assessments', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('employee_id')->constrained('employees')->cascadeOnDelete();
            $table->foreignId('assessed_by')->nullable()->constrained('admins')->nullOnDelete();
            $table->date('assessment_date');
            $table->string('result', 20)->default('pending');
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->index('employee_id');
        });

        if (DB::getDriverName() === 'pgsql') {
            DB::statement(
                'ALTER TABLE employee_practical_assessments ADD CONSTRAINT employee_practical_assessments_result_check '
                ."CHECK (result IN ('pass', 'fail', 'pending'))",
            );
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('employee_practical_assessments');
    }
};
