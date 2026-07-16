<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('bookings', function (Blueprint $table): void {
            $table->foreignId('service_mode_id')
                ->constrained('service_modes')
                ->restrictOnDelete();
            $table->jsonb('address_snapshot');
            $table->text('customer_notes')->nullable();

            $table->index('service_mode_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('bookings', function (Blueprint $table): void {
            $table->dropIndex(['service_mode_id']);
            $table->dropConstrainedForeignId('service_mode_id');
            $table->dropColumn(['address_snapshot', 'customer_notes']);
        });
    }
};
