<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * HRM Phase 6: additive index only, no behavior change.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('work_locations', function (Blueprint $table): void {
            $table->index('status');
            $table->index('location_type');
        });
    }

    public function down(): void
    {
        Schema::table('work_locations', function (Blueprint $table): void {
            $table->dropIndex(['status']);
            $table->dropIndex(['location_type']);
        });
    }
};
