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
        Schema::create('stock_ledgers', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('product_id')
                ->constrained('products')
                ->restrictOnDelete();
            $table->string('movement_type', 40);
            $table->integer('quantity');
            $table->nullableMorphs('reference');
            $table->timestampTz('created_at')->useCurrent();

            $table->index(['product_id', 'created_at']);
            $table->index(['movement_type', 'created_at']);
        });

        if (DB::getDriverName() === 'pgsql') {
            DB::statement(
                'ALTER TABLE stock_ledgers ADD CONSTRAINT stock_ledgers_movement_type_check '
                ."CHECK (movement_type IN ('purchase_receipt', 'customer_sale', 'adjustment', 'correction', 'damage', 'loss'))",
            );
            DB::statement(
                'ALTER TABLE stock_ledgers ADD CONSTRAINT stock_ledgers_quantity_nonzero_check '
                .'CHECK (quantity <> 0)',
            );
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('stock_ledgers');
    }
};
