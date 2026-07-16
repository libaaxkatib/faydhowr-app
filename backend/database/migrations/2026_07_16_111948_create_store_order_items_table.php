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
        Schema::create('store_order_items', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('store_order_id')
                ->constrained('store_orders')
                ->cascadeOnDelete();
            $table->foreignId('product_id')
                ->nullable()
                ->constrained('products')
                ->nullOnDelete();
            $table->string('sku_snapshot', 64);
            $table->string('product_name_snapshot', 200);
            $table->unsignedInteger('quantity');
            $table->decimal('unit_price_snapshot', 12, 2);
            $table->decimal('line_total_snapshot', 12, 2);
            $table->timestamps();

            $table->index(['store_order_id']);
        });

        if (DB::getDriverName() === 'pgsql') {
            DB::statement(
                'ALTER TABLE store_order_items ADD CONSTRAINT store_order_items_quantity_check '
                .'CHECK (quantity > 0)',
            );
            DB::statement(
                'ALTER TABLE store_order_items ADD CONSTRAINT store_order_items_prices_check '
                .'CHECK (unit_price_snapshot >= 0 AND line_total_snapshot >= 0)',
            );
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('store_order_items');
    }
};
