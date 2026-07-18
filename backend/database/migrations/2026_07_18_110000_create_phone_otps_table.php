<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('phone_otps', function (Blueprint $table): void {
            $table->id();
            $table->string('phone', 30);
            $table->string('purpose', 30);
            $table->string('otp_hash');
            $table->unsignedSmallInteger('attempts')->default(0);
            $table->timestamp('expires_at');
            $table->timestamp('consumed_at')->nullable();
            $table->timestamp('invalidated_at')->nullable();
            $table->timestamp('created_at');

            $table->index(['phone', 'purpose', 'created_at']);
            $table->index('expires_at');
        });

        if (DB::getDriverName() === 'pgsql') {
            DB::statement(
                'ALTER TABLE phone_otps ADD CONSTRAINT phone_otps_purpose_check '.
                "CHECK (purpose IN ('login', 'password_reset'))",
            );
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('phone_otps');
    }
};
