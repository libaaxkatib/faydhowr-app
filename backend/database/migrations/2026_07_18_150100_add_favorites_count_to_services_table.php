<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Cached favorites aggregate (Database Design §3.2.2): maintained on
     * favorite add/remove and automatic removal; internal only — never
     * exposed in public catalog payloads.
     */
    public function up(): void
    {
        Schema::table('services', function (Blueprint $table): void {
            $table->unsignedInteger('favorites_count')->default(0)->after('reviews_count');
        });
    }

    public function down(): void
    {
        Schema::table('services', function (Blueprint $table): void {
            $table->dropColumn('favorites_count');
        });
    }
};
