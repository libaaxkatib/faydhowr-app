<?php

namespace App\Support;

use App\Enums\AdminRole;
use App\Enums\AdminStatus;
use App\Models\Admin;
use DomainException;

/**
 * Keeps at least one active Super Admin. Super Admin cannot be created or assigned from the panel, so losing the last one would leave nobody who can
 * manage accounts, roles or settings without direct database work.
 *
 * Call it inside the transaction that is about to change or delete $adminId. It locks every active Super Admin row in a fixed order (by id) before
 * counting, so two requests that each demote or deactivate a different Super Admin at the same moment are serialised: the second one re-reads the
 * rows after the first has committed and sees that only one is left. The lock is taken before the target row is locked, never after, so two such
 * requests cannot deadlock on each other.
 */
final class LastSuperAdminGuard
{
    public const CODE = 'LAST_SUPER_ADMIN';

    /**
     * @param  bool  $deleting  the account is being removed rather than edited
     *
     * @throws DomainException with CODE when the change would leave no active Super Admin
     */
    public static function assertOneRemains(int $adminId, ?AdminRole $newRole, ?AdminStatus $newStatus, bool $deleting = false): void
    {
        /** @var list<int> $activeSuperAdminIds */
        $activeSuperAdminIds = Admin::query()
            ->where('role', AdminRole::SuperAdmin->value)
            ->where('status', AdminStatus::Active->value)
            ->orderBy('id')
            ->lockForUpdate()
            ->pluck('id')
            ->map(fn ($id): int => (int) $id)
            ->all();

        // Not an active Super Admin now: nothing can be lost by changing or removing this account.
        if (! in_array($adminId, $activeSuperAdminIds, true)) {
            return;
        }

        $staysActiveSuperAdmin = ! $deleting
            && ($newRole === null || $newRole === AdminRole::SuperAdmin)
            && ($newStatus === null || $newStatus === AdminStatus::Active);

        if ($staysActiveSuperAdmin) {
            return;
        }

        if (array_values(array_diff($activeSuperAdminIds, [$adminId])) === []) {
            throw new DomainException(self::CODE);
        }
    }
}
