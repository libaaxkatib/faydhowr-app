<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('favorites', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('customer_profile_id')
                ->constrained()
                ->restrictOnDelete();
            $table->foreignId('service_id')
                ->constrained()
                ->cascadeOnDelete();
            $table->timestamps();

            $table->unique(['customer_profile_id', 'service_id']);
            $table->index(['customer_profile_id', 'created_at']);
            $table->index('service_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('favorites');
    }
};
