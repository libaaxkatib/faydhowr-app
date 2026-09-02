<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Mirrors customer_attachments exactly: file metadata stored directly on
     * the row (file_name/file_type/file_size/file_path), not a reference to
     * the generic uploads table — matching the existing convention.
     */
    public function up(): void
    {
        Schema::create('employee_documents', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('employee_id')->constrained('employees')->cascadeOnDelete();
            $table->foreignId('admin_id')->constrained('admins')->restrictOnDelete();
            $table->string('file_name', 255);
            $table->string('file_type', 100);
            $table->unsignedBigInteger('file_size');
            $table->string('file_path', 500);
            $table->timestamps();

            $table->index('employee_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('employee_documents');
    }
};
