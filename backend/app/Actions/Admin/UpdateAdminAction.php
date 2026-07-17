<?php

namespace App\Actions\Admin;

use App\Enums\AdminRole;
use App\Enums\AuditAction;
use App\Events\Audit\AuditEvent;
use App\Models\Admin;
use DomainException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class UpdateAdminAction
{
    /**
     * @param  array{
     *     full_name?: string,
     *     email?: string,
     *     phone?: string,
     *     role?: string,
     *     status?: string
     * }  $data
     */
    public function handle(Admin $actor, Admin $admin, array $data): Admin
    {
        if ($actor->role !== AdminRole::SuperAdmin) {
            throw new DomainException('FORBIDDEN');
        }

        $updatedAdmin = DB::transaction(function () use ($admin, $data): Admin {
            $admin = Admin::query()
                ->whereKey($admin)
                ->lockForUpdate()
                ->firstOrFail();

            if (array_key_exists('email', $data)) {
                $data['email'] = Str::lower($data['email']);
            }

            $admin->fill($data);
            $admin->save();

            return $admin->refresh();
        });

        event(AuditEvent::record(
            action: AuditAction::Update,
            admin: $actor,
            description: 'Admin account updated.',
            entityType: Admin::class,
            entityId: $updatedAdmin->id,
            metadata: [
                'updated_fields' => array_keys($data),
            ],
        ));

        return $updatedAdmin;
    }
}
