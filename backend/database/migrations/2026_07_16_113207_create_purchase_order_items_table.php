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
        Schema::create('purchase_order_items', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('purchase_order_id')
                ->constrained('purchase_orders')
                ->cascadeOnDelete();
            $table->foreignId('product_id')
                ->constrained('products')
                ->restrictOnDelete();
            $table->string('sku', 64);
            $table->string('product_name', 200);
            $table->unsignedInteger('quantity');
            $table->decimal('unit_cost', 12, 2);
            $table->decimal('line_total', 12, 2);
            $table->timestamps();

            $table->index(['purchase_order_id']);
            $table->index(['product_id']);
            $table->unique(['purchase_order_id', 'product_id']);
        });

        if (DB::getDriverName() === 'pgsql') {
            DB::statement(
                'ALTER TABLE purchase_order_items ADD CONSTRAINT purchase_order_items_quantity_check '
                .'CHECK (quantity > 0)',
            );
            DB::statement(
                'ALTER TABLE purchase_order_items ADD CONSTRAINT purchase_order_items_costs_check '
                .'CHECK (unit_cost >= 0 AND line_total >= 0)',
            );
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('purchase_order_items');
    }
};
