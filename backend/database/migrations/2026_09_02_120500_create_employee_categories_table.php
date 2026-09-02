<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Seeds the 5 categories named explicitly in the HRM SRS (docs/HRM_MARKETING_SRS.md
     * §23), the same "seed reference data directly in the migration" pattern used by
     * create_permissions_table.
     */
    public function up(): void
    {
        Schema::create('employee_categories', function (Blueprint $table): void {
            $table->id();
            $table->string('name', 100);
            $table->text('description')->nullable();
            $table->timestamps();

            $table->unique('name');
        });

        $now = now();

        DB::table('employee_categories')->insert([
            ['name' => 'General Cleaning', 'description' => null, 'created_at' => $now, 'updated_at' => $now],
            ['name' => 'Cooking', 'description' => null, 'created_at' => $now, 'updated_at' => $now],
            ['name' => 'Waiter', 'description' => null, 'created_at' => $now, 'updated_at' => $now],
            ['name' => 'Barista', 'description' => null, 'created_at' => $now, 'updated_at' => $now],
            ['name' => 'Home Team', 'description' => null, 'created_at' => $now, 'updated_at' => $now],
        ]);
    }

    public function down(): void
    {
        Schema::dropIfExists('employee_categories');
    }
};
