<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Lightweight, Marketing-specific quotation tracking — deliberately NOT
     * the existing customer-facing `quotations` table (tied to
     * customer_profile_id and the Mobile App's booking/service-quoting
     * flow, an unrelated domain). Confirmed decision from the planning
     * session.
     */
    public function up(): void
    {
        Schema::create('marketing_quotations', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('marketing_record_id')->constrained('marketing_records')->cascadeOnDelete();
            $table->decimal('amount', 12, 2)->nullable();
            $table->date('sent_at')->nullable();
            $table->string('status', 20)->default('draft');
            $table->text('notes')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('admins')->nullOnDelete();
            $table->timestamps();

            $table->index('marketing_record_id');
        });

        if (DB::getDriverName() === 'pgsql') {
            DB::statement(
                'ALTER TABLE marketing_quotations ADD CONSTRAINT marketing_quotations_status_check '
                ."CHECK (status IN ('draft', 'sent', 'accepted', 'declined'))",
            );
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('marketing_quotations');
    }
};
