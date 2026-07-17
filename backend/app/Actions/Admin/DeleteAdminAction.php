<?php

namespace App\Actions\Admin;

use App\Enums\AdminRole;
use App\Enums\AuditAction;
use App\Events\Audit\AuditEvent;
use App\Models\Admin;
use DomainException;
use Illuminate\Support\Facades\DB;

class DeleteAdminAction
{
    public function handle(Admin $actor, Admin $admin): void
    {
        if ($actor->role !== AdminRole::SuperAdmin) {
            throw new DomainException('FORBIDDEN');
        }

        if ($actor->is($admin)) {
            throw new DomainException('SELF_DELETE_NOT_ALLOWED');
        }

        $deletedAdminId = $admin->id;

        DB::transaction(function () use ($admin): void {
            $admin = Admin::query()
                ->whereKey($admin)
                ->lockForUpdate()
                ->firstOrFail();

            $admin->delete();
        });

        event(AuditEvent::record(
            action: AuditAction::Delete,
            admin: $actor,
            description: 'Admin account deleted.',
            entityType: Admin::class,
            entityId: $deletedAdminId,
            metadata: [
                'deleted_admin_id' => $deletedAdminId,
            ],
        ));
    }
}
