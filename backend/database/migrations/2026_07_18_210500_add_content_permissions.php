<?php

use App\Enums\AdminPermission;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Fresh installs already receive these keys from create_permissions_table
     * which seeds all AdminPermission cases; this backfills existing databases
     * with the Sprint 29 Home content permissions (API Design §18.11).
     */
    public function up(): void
    {
        $now = now();

        foreach ($this->permissions() as $permission) {
            $exists = DB::table('permissions')
                ->where('key', $permission->value)
                ->exists();

            if (! $exists) {
                DB::table('permissions')->insert([
                    'key' => $permission->value,
                    'name' => $permission->label(),
                    'group' => $permission->group(),
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
            }
        }
    }

    public function down(): void
    {
        DB::table('permissions')
            ->whereIn('key', array_map(
                fn (AdminPermission $permission): string => $permission->value,
                $this->permissions(),
            ))
            ->delete();
    }

    /**
     * @return list<AdminPermission>
     */
    private function permissions(): array
    {
        return [
            AdminPermission::ContentView,
            AdminPermission::ContentManage,
        ];
    }
};
