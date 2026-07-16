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
        Schema::create('customer_profiles', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')
                ->constrained()
                ->restrictOnDelete();
            $table->string('customer_number', 40)->unique();
            $table->string('full_name', 150);
            $table->string('avatar_url', 500)->nullable();
            $table->string('preferred_language', 10);
            $table->jsonb('notification_preferences')->nullable();
            $table->string('classification', 30)->default('lead');
            $table->timestamps();
            $table->softDeletes();

            $table->unique('user_id');
        });

        if (DB::getDriverName() === 'pgsql') {
            DB::statement(
                "ALTER TABLE customer_profiles ADD CONSTRAINT customer_profiles_preferred_language_check "
                ."CHECK (preferred_language IN ('so', 'en', 'ar'))",
            );
            DB::statement(
                "ALTER TABLE customer_profiles ADD CONSTRAINT customer_profiles_classification_check "
                ."CHECK (classification IN ('lead', 'active_customer'))",
            );
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('customer_profiles');
    }
};
