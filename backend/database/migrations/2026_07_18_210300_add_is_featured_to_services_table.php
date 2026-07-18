<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Manual featured curation for the Home Featured Services row
     * (SRS FR-098.4, Database Design §3.2.2).
     */
    public function up(): void
    {
        Schema::table('services', function (Blueprint $table): void {
            $table->boolean('is_featured')->default(false)->after('is_active');

            $table->index(['is_featured', 'is_active', 'sort_order']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('services', function (Blueprint $table): void {
            $table->dropIndex(['is_featured', 'is_active', 'sort_order']);
            $table->dropColumn('is_featured');
        });
    }
};
