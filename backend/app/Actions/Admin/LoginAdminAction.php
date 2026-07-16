<?php

namespace App\Actions\Admin;

use App\Enums\AdminStatus;
use App\Enums\AuditAction;
use App\Events\Audit\AuditEvent;
use App\Models\Admin;
use DomainException;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

class LoginAdminAction
{
    /**
     * @param  array{email: string, password: string}  $credentials
     * @return array{admin: Admin, access_token: string, token_type: string}
     */
    public function handle(array $credentials): array
    {
        $admin = Admin::query()
            ->where('email', Str::lower($credentials['email']))
            ->first();

        if ($admin === null || ! Hash::check($credentials['password'], $admin->password)) {
            throw new DomainException('INVALID_CREDENTIALS');
        }

        if ($admin->status !== AdminStatus::Active) {
            throw new DomainException('ADMIN_ACCOUNT_INACTIVE');
        }

        $admin->forceFill([
            'last_login_at' => now(),
        ])->save();

        $admin = $admin->fresh();

        event(AuditEvent::record(
            action: AuditAction::Login,
            admin: $admin,
            description: 'Admin logged in.',
            entityType: Admin::class,
            entityId: $admin->id,
        ));

        return [
            'admin' => $admin,
            'access_token' => $admin->createToken('admin-panel')->plainTextToken,
            'token_type' => 'Bearer',
        ];
    }
}
