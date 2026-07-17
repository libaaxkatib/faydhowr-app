<?php

namespace App\Actions\Admin;

use App\Enums\AdminRole;
use App\Enums\AuditAction;
use App\Events\Audit\AuditEvent;
use App\Models\Admin;
use App\Models\Permission;
use DomainException;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;

class UpdateRolePermissionsAction
{
    /**
     * @param  list<string>  $permissionKeys
     * @return Collection<int, Permission>
     */
    public function handle(Admin $actor, AdminRole $role, array $permissionKeys): Collection
    {
        if ($actor->role !== AdminRole::SuperAdmin) {
            throw new DomainException('FORBIDDEN');
        }

        if ($role === AdminRole::SuperAdmin) {
            throw new DomainException('SUPER_ADMIN_PERMISSIONS_IMMUTABLE');
        }

        $uniqueKeys = array_values(array_unique($permissionKeys));

        $permissions = Permission::query()
            ->whereIn('key', $uniqueKeys)
            ->orderBy('group')
            ->orderBy('key')
            ->get();

        if ($permissions->count() !== count($uniqueKeys)) {
            throw new DomainException('INVALID_PERMISSIONS');
        }

        DB::transaction(function () use ($role, $permissions): void {
            DB::table('admin_role_permissions')
                ->where('role', $role->value)
                ->delete();

            if ($permissions->isEmpty()) {
                return;
            }

            $now = now();

            DB::table('admin_role_permissions')->insert(
                $permissions
                    ->map(fn (Permission $permission): array => [
                        'role' => $role->value,
                        'permission_id' => $permission->id,
                        'created_at' => $now,
                        'updated_at' => $now,
                    ])
                    ->all(),
            );
        });

        event(AuditEvent::record(
            action: AuditAction::RoleUpdate,
            admin: $actor,
            description: 'Role permissions updated.',
            entityType: 'role',
            entityId: null,
            metadata: [
                'role' => $role->value,
                'permissions' => $permissions->pluck('key')->values()->all(),
            ],
        ));

        return $permissions;
    }
}
