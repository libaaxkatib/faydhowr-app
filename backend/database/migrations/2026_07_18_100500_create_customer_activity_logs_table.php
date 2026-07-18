<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('customer_activity_logs', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('customer_profile_id')->constrained('customer_profiles')->restrictOnDelete();
            $table->string('event_type', 50);
            $table->string('description', 255)->nullable();
            $table->string('subject_type', 150)->nullable();
            $table->unsignedBigInteger('subject_id')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->index('customer_profile_id');
            $table->index(['customer_profile_id', 'created_at']);
            $table->index('event_type');
        });

        if (DB::getDriverName() === 'pgsql') {
            DB::statement(
                'ALTER TABLE customer_activity_logs ADD CONSTRAINT customer_activity_logs_event_type_check '.
                'CHECK (event_type IN ('.
                "'registration', 'login', 'profile_update', 'password_reset', ".
                "'address_added', 'address_updated', ".
                "'booking_created', 'booking_updated', 'booking_completed', ".
                "'quotation_requested', 'quotation_accepted', ".
                "'store_order_created', 'payment_recorded', 'review_submitted'".
                '))',
            );
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('customer_activity_logs');
    }
};
