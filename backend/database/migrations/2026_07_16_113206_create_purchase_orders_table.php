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
        Schema::create('purchase_orders', function (Blueprint $table): void {
            $table->id();
            $table->string('po_number', 40)->unique();
            $table->foreignId('supplier_id')
                ->constrained('suppliers')
                ->restrictOnDelete();
            $table->string('status', 30)->default('draft');
            $table->char('currency', 3);
            $table->decimal('subtotal', 12, 2)->default(0);
            $table->text('notes')->nullable();
            $table->timestampTz('submitted_at')->nullable();
            $table->timestampTz('approved_at')->nullable();
            $table->timestampTz('completed_at')->nullable();
            $table->timestampTz('cancelled_at')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['status', 'supplier_id', 'created_at']);
            $table->index(['supplier_id', 'created_at']);
        });

        if (DB::getDriverName() === 'pgsql') {
            DB::statement(
                'ALTER TABLE purchase_orders ADD CONSTRAINT purchase_orders_status_check '
                ."CHECK (status IN ('draft', 'submitted', 'approved', 'partially_received', 'completed', 'cancelled'))",
            );
            DB::statement(
                'ALTER TABLE purchase_orders ADD CONSTRAINT purchase_orders_number_format_check '
                ."CHECK (po_number ~ '^PO-[0-9]{4}-[0-9]{6}$')",
            );
            DB::statement(
                'ALTER TABLE purchase_orders ADD CONSTRAINT purchase_orders_currency_format_check '
                ."CHECK (currency ~ '^[A-Z]{3}$')",
            );
            DB::statement(
                'ALTER TABLE purchase_orders ADD CONSTRAINT purchase_orders_subtotal_check '
                .'CHECK (subtotal >= 0)',
            );
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('purchase_orders');
    }
};
