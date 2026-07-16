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
        Schema::create('admins', function (Blueprint $table): void {
            $table->id();
            $table->string('full_name', 150);
            $table->string('email', 191);
            $table->string('phone', 40);
            $table->string('password');
            $table->string('role', 30);
            $table->string('status', 30)->default('active');
            $table->timestampTz('last_login_at')->nullable();
            $table->rememberToken();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['status']);
            $table->index(['role']);
            $table->index(['status', 'role']);
        });

        if (DB::getDriverName() === 'pgsql') {
            DB::statement(
                'ALTER TABLE admins ADD CONSTRAINT admins_status_check '
                ."CHECK (status IN ('active', 'inactive'))",
            );
            DB::statement(
                'ALTER TABLE admins ADD CONSTRAINT admins_role_check '
                ."CHECK (role IN ('super_admin', 'manager', 'sales', 'inventory', 'accountant'))",
            );
        }

        DB::statement(
            'CREATE UNIQUE INDEX admins_email_unique ON admins (email) WHERE deleted_at IS NULL',
        );
        DB::statement(
            'CREATE UNIQUE INDEX admins_phone_unique ON admins (phone) WHERE deleted_at IS NULL',
        );
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS admins_email_unique');
        DB::statement('DROP INDEX IF EXISTS admins_phone_unique');
        Schema::dropIfExists('admins');
    }
};
