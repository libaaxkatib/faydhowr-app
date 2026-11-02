<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Replacement cycles. The original Permanent assignment stays the one canonical record of a position while its holder is away.
 *
 *   assignment_absences       one row per absence: the window in which the original is NOT paid (payroll reads it). `absent_from` is the day after
 *                             the last working day; `returned_on` is the day the person is back (null while they are away).
 *   assignment_cycle_events   the readable ledger of each cycle: absence started, replacement started / ended, returned, original terminated.
 *
 * Nothing here ever replaces an assignment row, and nothing is deleted when a cycle ends, so any number of cycles can follow one another.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('assignment_absences', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('work_assignment_id')->constrained('employee_work_assignments')->restrictOnDelete();
            $table->date('last_working_day');
            $table->date('absent_from');
            $table->date('returned_on')->nullable();
            // Set when the original was terminated while still away (the Replacement keeps working): the absence is then never closed by a return.
            $table->date('terminated_on')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('admins')->nullOnDelete();
            $table->timestamps();

            $table->index(['work_assignment_id', 'absent_from']);
        });

        Schema::create('assignment_cycle_events', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('absence_id')->constrained('assignment_absences')->restrictOnDelete();
            $table->foreignId('work_assignment_id')->constrained('employee_work_assignments')->restrictOnDelete();
            $table->string('event_type', 40);
            $table->date('event_date');
            $table->foreignId('replacement_assignment_id')->nullable()->constrained('employee_work_assignments')->restrictOnDelete();
            $table->foreignId('admin_id')->nullable()->constrained('admins')->nullOnDelete();
            $table->json('details')->nullable();
            $table->timestamps();

            $table->index(['work_assignment_id', 'id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('assignment_cycle_events');
        Schema::dropIfExists('assignment_absences');
    }
};
