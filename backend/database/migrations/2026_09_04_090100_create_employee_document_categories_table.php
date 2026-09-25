<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * HRM Phase 1 (docs/HRM_MARKETING_SRS.md HR §27): an HR-editable document
 * category list, mirroring the existing employee_categories/departments/
 * positions pattern exactly, so new categories never require a code change.
 * Seeded with the master prompt's example list plus Profile Picture (used by
 * the profile-picture upload, which is stored as a categorized document).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('employee_document_categories', function (Blueprint $table): void {
            $table->id();
            $table->string('name', 100)->unique();
            $table->text('description')->nullable();
            $table->timestamps();
        });

        DB::table('employee_document_categories')->insert([
            ['name' => 'Guarantor Documents', 'description' => null, 'created_at' => now(), 'updated_at' => now()],
            ['name' => 'Signed Contract', 'description' => null, 'created_at' => now(), 'updated_at' => now()],
            ['name' => 'Identity Documents', 'description' => null, 'created_at' => now(), 'updated_at' => now()],
            ['name' => 'Training Documents', 'description' => null, 'created_at' => now(), 'updated_at' => now()],
            ['name' => 'Practical Documents', 'description' => null, 'created_at' => now(), 'updated_at' => now()],
            ['name' => 'Employment Documents', 'description' => null, 'created_at' => now(), 'updated_at' => now()],
            ['name' => 'Other HR Documents', 'description' => null, 'created_at' => now(), 'updated_at' => now()],
            ['name' => 'Profile Picture', 'description' => null, 'created_at' => now(), 'updated_at' => now()],
        ]);
    }

    public function down(): void
    {
        Schema::dropIfExists('employee_document_categories');
    }
};
