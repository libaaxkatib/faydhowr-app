<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Seeds Team A / Team B per docs/HRM_MARKETING_SRS.md §5.1 — "System-ka
     * waa inuu mustaqbalka oggolaadaa teams kale" (must allow more teams
     * later), so this is a real table, not a fixed enum.
     */
    public function up(): void
    {
        Schema::create('marketing_teams', function (Blueprint $table): void {
            $table->id();
            $table->string('name', 100);
            $table->text('description')->nullable();
            $table->timestamps();

            $table->unique('name');
        });

        $now = now();
        DB::table('marketing_teams')->insert([
            ['name' => 'Team A', 'description' => null, 'created_at' => $now, 'updated_at' => $now],
            ['name' => 'Team B', 'description' => null, 'created_at' => $now, 'updated_at' => $now],
        ]);
    }

    public function down(): void
    {
        Schema::dropIfExists('marketing_teams');
    }
};
