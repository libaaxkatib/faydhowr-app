<?php

namespace App\Actions\Marketing;

use App\Enums\AdminRole;
use App\Enums\AdminStatus;
use App\Enums\AuditAction;
use App\Events\Audit\AuditEvent;
use App\Models\Admin;
use App\Models\MarketingTeam;
use App\Models\Permission;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Creates a Marketing Employee account (the existing Admin entity, role marketing_employee) and puts it in one team, in one
 * transaction. The password is set by the creator and hashed by the model cast - the same convention as the existing admin
 * creation; no new login, invitation or activation flow exists or is invented here.
 *
 * The new account receives the fixed team-member permission set as DIRECT grants (the existing permission mechanism), because
 * the marketing_employee role has no role-level permissions and the panel cannot edit them. The set is a constant: nothing
 * about it can be chosen through the request.
 */
class CreateMarketingTeamMemberAction
{
    /** What a team member needs to work their own team's records and log their own commission entries. */
    public const BASELINE_PERMISSIONS = ['marketing.view', 'marketing.manage', 'marketing.commission.view'];

    /**
     * @param  array{full_name: string, email: string, phone: string, password: string, team_id: int, status?: string}  $data
     */
    public function handle(Admin $actor, array $data): Admin
    {
        $team = MarketingTeam::query()->findOrFail((int) $data['team_id']);

        $member = DB::transaction(function () use ($data, $team): Admin {
            $member = Admin::query()->create([
                'full_name' => $data['full_name'],
                'email' => Str::lower($data['email']),
                'phone' => $data['phone'],
                'password' => $data['password'],
                'role' => AdminRole::MarketingEmployee,
                'status' => $data['status'] ?? AdminStatus::Active->value,
            ]);

            $team->members()->syncWithoutDetaching([$member->id]);

            $now = now();
            DB::table('admin_permissions')->insert(
                Permission::query()->whereIn('key', self::BASELINE_PERMISSIONS)->pluck('id')
                    ->map(fn ($id): array => ['admin_id' => $member->id, 'permission_id' => $id, 'created_at' => $now, 'updated_at' => $now])
                    ->all(),
            );

            return $member;
        });

        event(AuditEvent::record(
            action: AuditAction::Create,
            admin: $actor,
            description: 'Marketing team member account created.',
            entityType: Admin::class,
            entityId: $member->id,
            metadata: ['created_admin_id' => $member->id, 'role' => AdminRole::MarketingEmployee->value, 'team_id' => $team->id, 'permissions' => self::BASELINE_PERMISSIONS],
        ));
        event(AuditEvent::record(
            action: AuditAction::Update,
            admin: $actor,
            description: "Admin #{$member->id} added to marketing team '{$team->name}'.",
            entityType: MarketingTeam::class,
            entityId: $team->id,
            metadata: ['admin_id' => $member->id],
        ));

        return $member;
    }
}
