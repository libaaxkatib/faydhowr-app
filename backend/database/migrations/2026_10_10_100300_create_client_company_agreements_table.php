<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Optional company agreement attachments. Replacing an agreement adds a new
 * row; earlier rows are kept as history (the newest is the current one). The
 * storage disk name is stored per file so files stay reachable if the
 * configured disk later changes.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('client_company_agreements', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('client_company_id')->constrained('client_companies')->restrictOnDelete();
            $table->foreignId('uploaded_by')->nullable()->constrained('admins')->nullOnDelete();
            $table->string('file_name', 255);
            $table->string('file_type', 100);
            $table->unsignedBigInteger('file_size');
            $table->string('disk', 50);
            $table->string('file_path', 500);
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->index(['client_company_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('client_company_agreements');
    }
};
