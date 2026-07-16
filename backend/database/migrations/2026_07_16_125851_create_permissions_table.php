<?php

use App\Enums\AdminPermission;
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
        Schema::create('permissions', function (Blueprint $table): void {
            $table->id();
            $table->string('key', 100);
            $table->string('name', 150);
            $table->string('group', 50);
            $table->timestamps();

            $table->unique('key');
            $table->index(['group']);
            $table->index(['group', 'key']);
        });

        $now = now();

        $rows = array_map(
            fn (AdminPermission $permission): array => [
                'key' => $permission->value,
                'name' => $permission->label(),
                'group' => $permission->group(),
                'created_at' => $now,
                'updated_at' => $now,
            ],
            AdminPermission::cases(),
        );

        DB::table('permissions')->insert($rows);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('permissions');
    }
};
