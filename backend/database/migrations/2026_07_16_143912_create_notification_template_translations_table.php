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
        Schema::create('notification_template_translations', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('notification_template_id')
                ->constrained('notification_templates')
                ->cascadeOnDelete();
            $table->string('language', 10);
            $table->string('subject', 255)->nullable();
            $table->string('title', 255);
            $table->text('message');
            $table->timestamps();

            $table->unique(['notification_template_id', 'language'], 'notification_template_translations_unique');
            $table->index(['language']);
        });

        if (DB::getDriverName() === 'pgsql') {
            DB::statement(
                'ALTER TABLE notification_template_translations ADD CONSTRAINT notification_template_translations_language_check '
                ."CHECK (language IN ('so', 'en', 'ar'))",
            );
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('notification_template_translations');
    }
};
