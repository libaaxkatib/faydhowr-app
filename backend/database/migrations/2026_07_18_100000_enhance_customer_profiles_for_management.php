<?php

use App\Enums\Customer\CustomerStatus;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('customer_profiles', function (Blueprint $table): void {
            $table->string('gender', 20)->nullable()->after('avatar_url');
            $table->date('date_of_birth')->nullable()->after('gender');
            $table->string('status', 20)->default(CustomerStatus::Active->value)->after('preferred_language');
            $table->json('tags')->nullable()->after('status');
            $table->index('status');
            $table->index('created_at');
        });

        if (DB::getDriverName() === 'pgsql') {
            DB::statement(
                'ALTER TABLE customer_profiles ADD CONSTRAINT customer_profiles_status_check '.
                "CHECK (status IN ('ACTIVE', 'INACTIVE', 'BLOCKED'))",
            );
            DB::statement(
                'ALTER TABLE customer_profiles ADD CONSTRAINT customer_profiles_gender_check '.
                "CHECK (gender IS NULL OR gender IN ('male', 'female'))",
            );
        }
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE customer_profiles DROP CONSTRAINT IF EXISTS customer_profiles_status_check');
            DB::statement('ALTER TABLE customer_profiles DROP CONSTRAINT IF EXISTS customer_profiles_gender_check');
        }

        Schema::table('customer_profiles', function (Blueprint $table): void {
            $table->dropIndex(['status']);
            $table->dropIndex(['created_at']);
            $table->dropColumn(['gender', 'date_of_birth', 'status', 'tags']);
        });
    }
};
