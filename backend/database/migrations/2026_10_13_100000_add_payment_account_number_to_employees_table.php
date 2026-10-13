<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Optional payment/account number for paying an employee (for example a mobile-money or bank account).
 *
 * It is DISTINCT from the registration phone (`phone`) and the alternate phone: it is never used to identify
 * or match a person, never required, not unique (family members may share an account) and not indexed
 * (nothing looks employees up by it). Purely additive: every existing employee keeps NULL and every existing
 * column is untouched.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('employees', function (Blueprint $table): void {
            $table->string('payment_account_number', 50)->nullable()->after('alternate_phone');
        });
    }

    public function down(): void
    {
        Schema::table('employees', function (Blueprint $table): void {
            $table->dropColumn('payment_account_number');
        });
    }
};
