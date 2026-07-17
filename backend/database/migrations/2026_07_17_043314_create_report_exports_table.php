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
        Schema::create('report_exports', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('report_id')->constrained('reports')->cascadeOnDelete();
            $table->string('report_type', 30);
            $table->foreignId('requested_by')->constrained('admins')->restrictOnDelete();
            $table->string('format', 10);
            $table->json('filters')->nullable();
            $table->string('status', 20)->default('pending');
            $table->string('file_path')->nullable();
            $table->timestampTz('started_at')->nullable();
            $table->timestampTz('completed_at')->nullable();
            $table->timestampTz('failed_at')->nullable();
            $table->text('failure_reason')->nullable();
            $table->timestamps();

            $table->index(['report_id']);
            $table->index(['report_type']);
            $table->index(['requested_by']);
            $table->index(['status']);
            $table->index(['created_at']);
            $table->index(['status', 'created_at']);
        });

        if (DB::getDriverName() === 'pgsql') {
            DB::statement(
                'ALTER TABLE report_exports ADD CONSTRAINT report_exports_report_type_check '
                ."CHECK (report_type IN ('bookings', 'quotations', 'orders', 'payments', 'store_orders', 'inventory', 'suppliers', 'purchase_orders', 'goods_receipts', 'customers'))",
            );
            DB::statement(
                'ALTER TABLE report_exports ADD CONSTRAINT report_exports_format_check '
                ."CHECK (format IN ('pdf', 'xlsx'))",
            );
            DB::statement(
                'ALTER TABLE report_exports ADD CONSTRAINT report_exports_status_check '
                ."CHECK (status IN ('pending', 'processing', 'completed', 'failed'))",
            );
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('report_exports');
    }
};
