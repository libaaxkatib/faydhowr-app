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
        Schema::create('store_orders', function (Blueprint $table): void {
            $table->id();
            $table->string('store_order_number', 40)->unique();
            $table->foreignId('customer_profile_id')
                ->constrained('customer_profiles')
                ->restrictOnDelete();
            $table->foreignId('cart_id')
                ->nullable()
                ->constrained('carts')
                ->nullOnDelete();
            $table->foreignId('customer_address_id')
                ->nullable()
                ->constrained('customer_addresses')
                ->nullOnDelete();
            $table->string('status', 30)->default('pending_payment');
            $table->char('currency', 3);
            $table->unsignedInteger('total_items');
            $table->unsignedInteger('total_quantity');
            $table->decimal('subtotal', 12, 2);
            $table->json('shipping_address_snapshot');
            $table->text('notes')->nullable();
            $table->timestampTz('cancelled_at')->nullable();
            $table->string('cancellation_reason', 255)->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['customer_profile_id', 'created_at']);
            $table->index(['status', 'created_at']);
        });

        if (DB::getDriverName() === 'pgsql') {
            DB::statement(
                'ALTER TABLE store_orders ADD CONSTRAINT store_orders_status_check '
                ."CHECK (status IN ('pending_payment', 'confirmed', 'processing', 'completed', 'cancelled'))",
            );
            DB::statement(
                'ALTER TABLE store_orders ADD CONSTRAINT store_orders_number_format_check '
                ."CHECK (store_order_number ~ '^STO-[0-9]{4}-[0-9]{6}$')",
            );
            DB::statement(
                'ALTER TABLE store_orders ADD CONSTRAINT store_orders_currency_format_check '
                ."CHECK (currency ~ '^[A-Z]{3}$')",
            );
            DB::statement(
                'ALTER TABLE store_orders ADD CONSTRAINT store_orders_totals_check '
                .'CHECK (total_items > 0 AND total_quantity > 0 AND subtotal >= 0)',
            );
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('store_orders');
    }
};
