<?php

use App\Enums\UserStatus;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->string('phone', 30)->nullable()->unique()->after('email');
            $table->string('status', 30)->default(UserStatus::Active->value)->after('password');
            $table->timestamp('last_login_at')->nullable()->after('status');
            $table->index('status');
            $table->index('last_login_at');
        });

        if (DB::getDriverName() === 'pgsql') {
            DB::statement(
                'ALTER TABLE users ADD CONSTRAINT users_status_check '.
                "CHECK (status IN ('pending_verification', 'active', 'suspended', 'deactivated'))",
            );
        }
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE users DROP CONSTRAINT IF EXISTS users_status_check');
        }

        Schema::table('users', function (Blueprint $table): void {
            $table->dropIndex(['status']);
            $table->dropIndex(['last_login_at']);
            $table->dropUnique(['phone']);
            $table->dropColumn(['phone', 'status', 'last_login_at']);
        });
    }
};
