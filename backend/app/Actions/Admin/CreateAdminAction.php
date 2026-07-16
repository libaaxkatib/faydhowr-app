<?php

namespace App\Actions\Admin;

use App\Enums\AdminRole;
use App\Enums\AdminStatus;
use App\Enums\AuditAction;
use App\Events\Audit\AuditEvent;
use App\Models\Admin;
use DomainException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class CreateAdminAction
{
    /**
     * @param  array{
     *     full_name: string,
     *     email: string,
     *     phone: string,
     *     password: string,
     *     role: string,
     *     status: string
     * }  $data
     */
    public function handle(Admin $actor, array $data): Admin
    {
        if ($actor->role !== AdminRole::SuperAdmin) {
            throw new DomainException('FORBIDDEN');
        }

        $admin = DB::transaction(function () use ($data): Admin {
            return Admin::query()->create([
                'full_name' => $data['full_name'],
                'email' => Str::lower($data['email']),
                'phone' => $data['phone'],
                'password' => $data['password'],
                'role' => $data['role'],
                'status' => $data['status'] ?? AdminStatus::Active->value,
            ]);
        });

        event(AuditEvent::record(
            action: AuditAction::Create,
            admin: $actor,
            description: 'Admin account created.',
            entityType: Admin::class,
            entityId: $admin->id,
            metadata: [
                'created_admin_id' => $admin->id,
                'role' => $admin->role->value,
            ],
        ));

        GetDashboardStatisticsAction::forgetFor($actor);

        return $admin;
    }
}
