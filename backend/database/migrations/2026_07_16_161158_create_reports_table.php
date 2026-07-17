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
        Schema::create('reports', function (Blueprint $table): void {
            $table->id();
            $table->string('report_type', 30);
            $table->string('format', 30);
            $table->json('filters')->nullable();
            $table->foreignId('generated_by')->constrained('admins')->restrictOnDelete();
            $table->timestampTz('generated_at');
            $table->timestamp('created_at')->useCurrent();

            $table->index(['report_type']);
            $table->index(['format']);
            $table->index(['generated_by']);
            $table->index(['generated_at']);
            $table->index(['created_at']);
            $table->index(['report_type', 'generated_at']);
        });

        if (DB::getDriverName() === 'pgsql') {
            DB::statement(
                'ALTER TABLE reports ADD CONSTRAINT reports_report_type_check '
                ."CHECK (report_type IN ('bookings', 'quotations', 'orders', 'payments', 'store_orders', 'inventory', 'suppliers', 'purchase_orders', 'goods_receipts', 'customers'))",
            );
            DB::statement(
                'ALTER TABLE reports ADD CONSTRAINT reports_format_check '
                ."CHECK (format IN ('json', 'pdf', 'excel'))",
            );
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('reports');
    }
};
