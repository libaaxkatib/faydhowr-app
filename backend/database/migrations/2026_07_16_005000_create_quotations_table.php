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
        Schema::create('quotations', function (Blueprint $table): void {
            $table->id();
            $table->string('quotation_number', 40)->unique();
            $table->foreignId('customer_profile_id')
                ->constrained()
                ->restrictOnDelete();
            $table->foreignId('booking_id')
                ->nullable()
                ->constrained()
                ->restrictOnDelete();
            $table->string('status', 30)->default('draft');
            $table->decimal('subtotal', 12, 2);
            $table->decimal('discount_amount', 12, 2)->default(0);
            $table->decimal('tax_amount', 12, 2)->default(0);
            $table->decimal('total_amount', 12, 2);
            $table->timestampTz('valid_until')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['customer_profile_id', 'created_at']);
            $table->index(['booking_id']);
            $table->index(['status', 'valid_until']);
        });

        if (DB::getDriverName() === 'pgsql') {
            DB::statement(
                "ALTER TABLE quotations ADD CONSTRAINT quotations_status_check "
                ."CHECK (status IN ('draft', 'issued', 'under_discussion', 'accepted', "
                ."'rejected', 'expired', 'cancelled'))",
            );
            DB::statement(
                "ALTER TABLE quotations ADD CONSTRAINT quotations_number_format_check "
                ."CHECK (quotation_number ~ '^QT-[0-9]{4}-[0-9]{6}$')",
            );
            DB::statement(
                'ALTER TABLE quotations ADD CONSTRAINT quotations_amounts_non_negative_check '
                .'CHECK (subtotal >= 0 AND discount_amount >= 0 AND tax_amount >= 0 '
                .'AND total_amount >= 0 AND discount_amount <= subtotal)',
            );
            DB::statement(
                'ALTER TABLE quotations ADD CONSTRAINT quotations_total_amount_check '
                .'CHECK (total_amount = subtotal - discount_amount + tax_amount)',
            );
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('quotations');
    }
};
