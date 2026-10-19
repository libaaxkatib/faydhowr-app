<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Company agreements get the same integrity record as employee documents: the SHA-256 of the file as it was stored, so a
 * download can prove the bytes are the ones that were uploaded. Additive and nullable: production has no agreement rows
 * yet, and any older row simply has no checksum (it is then served without the extra check, never refused for lacking one).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('client_company_agreements', function (Blueprint $table): void {
            $table->string('sha256', 64)->nullable()->after('file_path');
        });
    }

    public function down(): void
    {
        Schema::table('client_company_agreements', function (Blueprint $table): void {
            $table->dropColumn('sha256');
        });
    }
};
