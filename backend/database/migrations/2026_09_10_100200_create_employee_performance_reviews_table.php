<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * HRM Phase 4: a small categorical rating + free-text notes - no invented
 * numeric/weighted scoring, per the same caution the roadmap applied to
 * candidate matching in Phase 2.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('employee_performance_reviews', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('employee_id')->constrained('employees')->cascadeOnDelete();

            $table->date('review_date');
            $table->string('rating', 20);
            $table->text('notes')->nullable();

            $table->foreignId('reviewed_by')->nullable()->constrained('admins')->nullOnDelete();
            $table->timestamps();
        });

        if (DB::getDriverName() === 'pgsql') {
            DB::statement(
                'ALTER TABLE employee_performance_reviews ADD CONSTRAINT employee_performance_reviews_rating_check '
                ."CHECK (rating IN ('excellent', 'good', 'needs_improvement', 'poor'))",
            );
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('employee_performance_reviews');
    }
};
