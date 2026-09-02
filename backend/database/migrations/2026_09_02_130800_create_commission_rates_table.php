<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Stores WHAT rate is configured, not a formula — the calculation rule
     * itself is explicitly TBD (docs/HRM_MARKETING_SRS.md §21/§39) and must
     * not be invented by the developer. admin_id nullable = an org-wide
     * default rate; a non-null admin_id overrides it for that employee.
     */
    public function up(): void
    {
        Schema::create('commission_rates', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('admin_id')->nullable()->constrained('admins')->cascadeOnDelete();
            $table->string('rate_type', 20);
            $table->decimal('rate_value', 10, 4);
            $table->date('effective_from');
            $table->foreignId('created_by')->nullable()->constrained('admins')->nullOnDelete();
            $table->timestamps();

            $table->index(['admin_id', 'effective_from']);
        });

        if (DB::getDriverName() === 'pgsql') {
            DB::statement(
                'ALTER TABLE commission_rates ADD CONSTRAINT commission_rates_rate_type_check '
                ."CHECK (rate_type IN ('percentage', 'fixed'))",
            );
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('commission_rates');
    }
};
