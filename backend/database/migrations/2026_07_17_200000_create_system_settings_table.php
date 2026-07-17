<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Key-value store for all system configuration. Settings are addressed by
     * the fully-qualified dotted key `category.key`; the category column stores
     * the namespace and the key column stores the segment after the dot.
     */
    public function up(): void
    {
        Schema::create('system_settings', function (Blueprint $table): void {
            $table->id();
            $table->string('category', 50);
            $table->string('key', 100);
            $table->json('value')->nullable();
            $table->json('default_value')->nullable();
            $table->boolean('is_sensitive')->default(false);
            $table->foreignId('updated_by')->nullable()->constrained('admins')->nullOnDelete();
            $table->timestamps();

            $table->unique(['category', 'key']);
            $table->index(['category']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('system_settings');
    }
};
