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
        Schema::create('accounts', function (Blueprint $table): void {
            $table->id();
            $table->string('code', 30)->unique();
            $table->string('name');
            $table->string('account_type', 30);
            $table->string('account_category', 20);
            $table->foreignId('parent_account_id')->nullable()->constrained('accounts')->restrictOnDelete();
            $table->boolean('is_group')->default(false);
            $table->string('normal_balance', 10);
            $table->string('status', 10);
            $table->timestamps();

            $table->index(['account_type']);
            $table->index(['account_category']);
            $table->index(['parent_account_id']);
            $table->index(['status']);
        });

        if (DB::getDriverName() === 'pgsql') {
            DB::statement(
                'ALTER TABLE accounts ADD CONSTRAINT accounts_account_type_check '
                ."CHECK (account_type IN ('cash', 'bank', 'accounts_receivable', 'inventory', 'fixed_assets', 'accounts_payable', 'tax', 'sales', 'service_revenue', 'cost_of_goods_sold', 'operating_expense', 'payroll_expense', 'retained_earnings'))",
            );
            DB::statement(
                'ALTER TABLE accounts ADD CONSTRAINT accounts_account_category_check '
                ."CHECK (account_category IN ('assets', 'liabilities', 'equity', 'revenue', 'expenses'))",
            );
            DB::statement(
                'ALTER TABLE accounts ADD CONSTRAINT accounts_normal_balance_check '
                ."CHECK (normal_balance IN ('debit', 'credit'))",
            );
            DB::statement(
                'ALTER TABLE accounts ADD CONSTRAINT accounts_status_check '
                ."CHECK (status IN ('active', 'inactive'))",
            );
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('accounts');
    }
};
