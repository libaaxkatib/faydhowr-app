<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('bookings', function (Blueprint $table): void {
            $table->id();
            $table->string('booking_number', 40)->unique();
            $table->foreignId('customer_profile_id')
                ->constrained()
                ->restrictOnDelete();
            $table->foreignId('service_id')
                ->constrained()
                ->restrictOnDelete();
            $table->string('status', 30)->default('submitted');
            $table->date('requested_date');
            $table->string('requested_time_window', 100);
            $table->timestampTz('scheduled_start_at')->nullable();
            $table->timestampTz('scheduled_end_at')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['customer_profile_id', 'created_at']);
            $table->index(['service_id', 'scheduled_start_at', 'status']);
            $table->index(['status', 'created_at']);
        });

        if (DB::getDriverName() === 'pgsql') {
            DB::statement(
                "ALTER TABLE bookings ADD CONSTRAINT bookings_status_check "
                ."CHECK (status IN ('submitted', 'pending_review', 'quotation_ready', "
                ."'under_discussion', 'accepted', 'scheduled', 'in_progress', "
                ."'completed', 'cancelled'))",
            );
            DB::statement(
                "ALTER TABLE bookings ADD CONSTRAINT bookings_number_format_check "
                ."CHECK (booking_number ~ '^BK-[0-9]{4}-[0-9]{6}$')",
            );
            DB::statement(
                'ALTER TABLE bookings ADD CONSTRAINT bookings_confirmed_schedule_check '
                .'CHECK (scheduled_end_at IS NULL OR '
                .'(scheduled_start_at IS NOT NULL AND scheduled_end_at > scheduled_start_at))',
            );
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('bookings');
    }
};
