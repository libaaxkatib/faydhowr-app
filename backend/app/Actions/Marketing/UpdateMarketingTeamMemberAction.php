<?php

namespace App\Actions\Marketing;

use App\Enums\AuditAction;
use App\Events\Audit\AuditEvent;
use App\Models\Admin;

/** Changes a team member's name, phone or active/inactive status. An inactive account is refused at every request. */
class UpdateMarketingTeamMemberAction
{
    /** @param  array{full_name?: string, phone?: string, status?: string}  $data */
    public function handle(Admin $actor, Admin $member, array $data): Admin
    {
        $member->fill(array_intersect_key($data, array_flip(['full_name', 'phone', 'status'])))->save();

        event(AuditEvent::record(
            action: AuditAction::Update,
            admin: $actor,
            description: 'Marketing team member account updated.',
            entityType: Admin::class,
            entityId: $member->id,
            metadata: ['updated_fields' => array_keys($data)],
        ));

        return $member->refresh();
    }
}
