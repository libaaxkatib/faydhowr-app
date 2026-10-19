<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * HR on/off policies that only the Super Admin may change (the first one: whether one employee may hold assignments at more than
 * one company at the same time). A policy without a row is OFF, so adding the table changes no behaviour. Additive.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('hr_policies', function (Blueprint $table): void {
            $table->id();
            $table->string('key', 80)->unique();
            $table->boolean('enabled')->default(false);
            $table->foreignId('changed_by')->nullable()->constrained('admins')->nullOnDelete();
            $table->string('reason', 500)->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('hr_policies');
    }
};
