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
        Schema::create('goods_receipt_items', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('goods_receipt_id')
                ->constrained('goods_receipts')
                ->cascadeOnDelete();
            $table->foreignId('purchase_order_item_id')
                ->nullable()
                ->constrained('purchase_order_items')
                ->restrictOnDelete();
            $table->foreignId('product_id')
                ->constrained('products')
                ->restrictOnDelete();
            $table->unsignedInteger('quantity_received');
            $table->decimal('unit_cost', 12, 2);
            $table->timestamps();

            $table->index(['goods_receipt_id']);
            $table->index(['product_id']);
            $table->index(['purchase_order_item_id']);
        });

        if (DB::getDriverName() === 'pgsql') {
            DB::statement(
                'ALTER TABLE goods_receipt_items ADD CONSTRAINT goods_receipt_items_quantity_check '
                .'CHECK (quantity_received > 0)',
            );
            DB::statement(
                'ALTER TABLE goods_receipt_items ADD CONSTRAINT goods_receipt_items_unit_cost_check '
                .'CHECK (unit_cost >= 0)',
            );
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('goods_receipt_items');
    }
};
