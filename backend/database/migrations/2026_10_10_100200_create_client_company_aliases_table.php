<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Traceability for merged company identities: the original spelling(s) found
 * in a source (e.g. the Excel workbook) are kept against the one canonical
 * company, with the source reference, so a merge never loses the original
 * values.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('client_company_aliases', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('client_company_id')->constrained('client_companies')->cascadeOnDelete();
            $table->string('alias', 150);
            $table->string('source', 100)->nullable();
            $table->string('source_reference', 255)->nullable();
            $table->foreignId('created_by')->nullable()->constrained('admins')->nullOnDelete();
            $table->timestamps();

            $table->unique(['client_company_id', 'alias']);
            $table->index('alias');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('client_company_aliases');
    }
};
