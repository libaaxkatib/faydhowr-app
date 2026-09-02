<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * PROJECT-only fields per docs/HRM_MARKETING_SRS.md §9-10. company_name
     * is deliberately nullable — §9.1/§36's critical rule: "Company Name ma
     * aha required field" (company is not mandatory for every project; the
     * responsible party may be an Engineer/Owner/Other with no company at
     * all). Enforced only at the FormRequest level (required only when
     * responsible_party_type = company), never at the DB level.
     */
    public function up(): void
    {
        Schema::create('project_details', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('marketing_record_id')->unique()->constrained('marketing_records')->cascadeOnDelete();
            $table->string('responsible_party_type', 20);
            $table->string('company_name', 200)->nullable();
            $table->string('responsible_person_name', 150)->nullable();
            $table->string('phone', 40)->nullable();
            $table->string('location', 150)->nullable();
            $table->string('project_type', 150)->nullable();
            $table->string('project_size', 100)->nullable();
            $table->date('construction_completion_date')->nullable();
            $table->date('fayadhowr_work_date')->nullable();
            $table->timestamps();
        });

        if (DB::getDriverName() === 'pgsql') {
            DB::statement(
                'ALTER TABLE project_details ADD CONSTRAINT project_details_responsible_party_type_check '
                ."CHECK (responsible_party_type IN ('company', 'engineer', 'owner', 'other'))",
            );
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('project_details');
    }
};
