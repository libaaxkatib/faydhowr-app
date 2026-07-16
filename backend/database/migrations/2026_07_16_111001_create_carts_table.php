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
        Schema::create('carts', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('customer_profile_id')
                ->constrained('customer_profiles')
                ->restrictOnDelete();
            $table->string('status', 20)->default('active');
            $table->timestamps();

            $table->unique('customer_profile_id');
            $table->index(['customer_profile_id', 'status']);
        });

        if (DB::getDriverName() === 'pgsql') {
            DB::statement(
                'ALTER TABLE carts ADD CONSTRAINT carts_status_check '
                ."CHECK (status IN ('active', 'converted', 'abandoned'))",
            );
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('carts');
    }
};
