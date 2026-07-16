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
        Schema::create('products', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('category_id')
                ->constrained('product_categories')
                ->restrictOnDelete();
            $table->string('sku', 64)->unique();
            $table->string('name', 200);
            $table->string('slug', 220)->unique();
            $table->text('description')->nullable();
            $table->decimal('selling_price', 12, 2);
            $table->decimal('cost_price', 12, 2);
            $table->char('currency', 3);
            $table->unsignedInteger('current_stock')->default(0);
            $table->unsignedInteger('low_stock_threshold')->default(0);
            $table->string('status', 30)->default('active');
            $table->boolean('is_featured')->default(false);
            $table->timestamps();
            $table->softDeletes();

            $table->index(['status', 'category_id']);
            $table->index(['is_featured', 'status']);
            $table->index(['current_stock', 'low_stock_threshold']);
        });

        if (DB::getDriverName() === 'pgsql') {
            DB::statement(
                'ALTER TABLE products ADD CONSTRAINT products_status_check '
                ."CHECK (status IN ('active', 'inactive'))",
            );
            DB::statement(
                'ALTER TABLE products ADD CONSTRAINT products_selling_price_check '
                .'CHECK (selling_price >= 0)',
            );
            DB::statement(
                'ALTER TABLE products ADD CONSTRAINT products_cost_price_check '
                .'CHECK (cost_price >= 0)',
            );
            DB::statement(
                'ALTER TABLE products ADD CONSTRAINT products_current_stock_check '
                .'CHECK (current_stock >= 0)',
            );
            DB::statement(
                'ALTER TABLE products ADD CONSTRAINT products_low_stock_threshold_check '
                .'CHECK (low_stock_threshold >= 0)',
            );
            DB::statement(
                'ALTER TABLE products ADD CONSTRAINT products_currency_format_check '
                ."CHECK (currency ~ '^[A-Z]{3}$')",
            );
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('products');
    }
};
