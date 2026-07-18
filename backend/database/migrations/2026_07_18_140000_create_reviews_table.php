<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('reviews', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('customer_profile_id')
                ->constrained()
                ->restrictOnDelete();
            $table->foreignId('booking_id')
                ->unique()
                ->constrained()
                ->restrictOnDelete();
            $table->foreignId('service_id')
                ->constrained()
                ->restrictOnDelete();
            $table->unsignedTinyInteger('rating');
            $table->string('title', 150)->nullable();
            $table->text('comment')->nullable();
            $table->string('status', 20)->default('pending');
            $table->timestamps();

            $table->index(['service_id', 'status', 'created_at']);
            $table->index(['customer_profile_id', 'created_at']);
            $table->index(['status', 'created_at']);
        });

        if (DB::getDriverName() === 'pgsql') {
            DB::statement(
                'ALTER TABLE reviews ADD CONSTRAINT reviews_rating_check '
                .'CHECK (rating BETWEEN 1 AND 5)',
            );
            DB::statement(
                'ALTER TABLE reviews ADD CONSTRAINT reviews_status_check '
                ."CHECK (status IN ('pending', 'published', 'hidden'))",
            );
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('reviews');
    }
};
