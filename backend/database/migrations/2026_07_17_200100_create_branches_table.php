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
        Schema::create('branches', function (Blueprint $table): void {
            $table->id();
            $table->string('code', 10)->unique();
            $table->string('name', 100);
            $table->string('city', 100);
            $table->string('status', 20);
            $table->boolean('is_default')->default(false);
            $table->timestamp('activated_at')->nullable();
            $table->foreignId('activated_by')->nullable()->constrained('admins')->nullOnDelete();
            $table->timestamps();

            $table->index(['status']);
        });

        if (DB::getDriverName() === 'pgsql') {
            DB::statement(
                'ALTER TABLE branches ADD CONSTRAINT branches_status_check '
                ."CHECK (status IN ('ACTIVE', 'INACTIVE', 'COMING_SOON'))",
            );
            DB::statement(
                'ALTER TABLE branches ADD CONSTRAINT branches_default_requires_active_check '
                ."CHECK (is_default = FALSE OR status = 'ACTIVE')",
            );
            DB::statement(
                'CREATE UNIQUE INDEX branches_single_default_unique '
                .'ON branches (is_default) WHERE is_default = TRUE',
            );
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('branches');
    }
};
