<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Cached rating aggregates (Database Design §3.2.2): computed from
     * published reviews only and recalculated on review publish/hide.
     */
    public function up(): void
    {
        Schema::table('services', function (Blueprint $table): void {
            $table->decimal('average_rating', 3, 2)->nullable()->after('sort_order');
            $table->unsignedInteger('reviews_count')->default(0)->after('average_rating');
        });
    }

    public function down(): void
    {
        Schema::table('services', function (Blueprint $table): void {
            $table->dropColumn(['average_rating', 'reviews_count']);
        });
    }
};
