<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('quotation_discussion_messages', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('quotation_id')
                ->constrained()
                ->restrictOnDelete();
            $table->string('sender_type', 20);
            $table->unsignedBigInteger('sender_id')->nullable();
            $table->text('message');
            $table->jsonb('attachments')->nullable();
            $table->timestamps();

            $table->index(['quotation_id', 'created_at']);
        });

        if (DB::getDriverName() === 'pgsql') {
            DB::statement(
                'ALTER TABLE quotation_discussion_messages '
                .'ADD CONSTRAINT quotation_discussion_messages_sender_type_check '
                ."CHECK (sender_type IN ('user', 'admin', 'system'))",
            );
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('quotation_discussion_messages');
    }
};
