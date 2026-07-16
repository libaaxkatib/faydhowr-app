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
        Schema::create('orders', function (Blueprint $table): void {
            $table->id();
            $table->string('order_number', 40)->unique();
            $table->foreignId('customer_profile_id')
                ->constrained()
                ->restrictOnDelete();
            $table->foreignId('quotation_id')
                ->constrained()
                ->restrictOnDelete();
            $table->string('status', 30)->default('pending_payment');
            $table->decimal('subtotal', 12, 2);
            $table->decimal('discount_amount', 12, 2)->default(0);
            $table->decimal('tax_amount', 12, 2)->default(0);
            $table->decimal('total_amount', 12, 2);
            $table->text('notes')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['customer_profile_id', 'created_at']);
            $table->index(['quotation_id']);
            $table->index(['status', 'created_at']);
        });

        if (DB::getDriverName() === 'pgsql') {
            DB::statement(
                "ALTER TABLE orders ADD CONSTRAINT orders_status_check "
                ."CHECK (status IN ('pending_payment', 'confirmed', 'processing', 'completed', 'cancelled'))",
            );
            DB::statement(
                "ALTER TABLE orders ADD CONSTRAINT orders_number_format_check "
                ."CHECK (order_number ~ '^ORD-[0-9]{4}-[0-9]{6}$')",
            );
            DB::statement(
                'ALTER TABLE orders ADD CONSTRAINT orders_amounts_non_negative_check '
                .'CHECK (subtotal >= 0 AND discount_amount >= 0 AND tax_amount >= 0 '
                .'AND total_amount >= 0 AND discount_amount <= subtotal)',
            );
            DB::statement(
                'ALTER TABLE orders ADD CONSTRAINT orders_total_amount_check '
                .'CHECK (total_amount = subtotal - discount_amount + tax_amount)',
            );
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('orders');
    }
};
