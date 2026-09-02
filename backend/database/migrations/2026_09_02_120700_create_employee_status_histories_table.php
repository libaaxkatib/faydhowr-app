<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('employee_status_histories', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('employee_id')->constrained('employees')->cascadeOnDelete();
            $table->string('from_status', 20)->nullable();
            $table->string('to_status', 20);
            $table->foreignId('changed_by')->nullable()->constrained('admins')->nullOnDelete();
            $table->text('note')->nullable();
            $table->timestamps();

            $table->index(['employee_id', 'created_at']);
        });

        if (DB::getDriverName() === 'pgsql') {
            DB::statement(
                'ALTER TABLE employee_status_histories ADD CONSTRAINT employee_status_histories_to_status_check '
                ."CHECK (to_status IN ('applicant', 'recruitment', 'practical', 'waiting', "
                ."'approved', 'active', 'inactive'))",
            );
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('employee_status_histories');
    }
};
