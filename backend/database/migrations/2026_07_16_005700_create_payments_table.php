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
        Schema::create('payments', function (Blueprint $table): void {
            $table->id();
            $table->string('payment_number', 40)->unique();
            $table->string('receipt_number', 40)->nullable()->unique();
            $table->foreignId('customer_profile_id')
                ->constrained()
                ->restrictOnDelete();
            $table->morphs('payable');
            $table->string('status', 30)->default('pending');
            $table->decimal('amount', 12, 2);
            $table->char('currency', 3);
            $table->string('gateway', 50)->nullable();
            $table->string('gateway_reference', 191)->nullable();
            $table->timestampTz('paid_at')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['customer_profile_id', 'created_at']);
            $table->index(['status', 'created_at']);
            $table->unique(['gateway', 'gateway_reference']);
        });

        if (DB::getDriverName() === 'pgsql') {
            DB::statement(
                'ALTER TABLE payments ADD CONSTRAINT payments_status_check '
                ."CHECK (status IN ('pending', 'initialized', 'processing', 'paid', 'failed', 'cancelled'))",
            );
            DB::statement(
                'ALTER TABLE payments ADD CONSTRAINT payments_number_format_check '
                ."CHECK (payment_number ~ '^PAY-[0-9]{4}-[0-9]{6}$')",
            );
            DB::statement(
                'ALTER TABLE payments ADD CONSTRAINT payments_receipt_number_format_check '
                ."CHECK (receipt_number IS NULL OR receipt_number ~ '^RCPT-[0-9]{4}-[0-9]{6}$')",
            );
            DB::statement(
                'ALTER TABLE payments ADD CONSTRAINT payments_amount_check '
                .'CHECK (amount > 0)',
            );
            DB::statement(
                'ALTER TABLE payments ADD CONSTRAINT payments_paid_fields_check '
                ."CHECK ((receipt_number IS NULL AND paid_at IS NULL) OR status = 'paid')",
            );
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('payments');
    }
};
