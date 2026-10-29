<?php

namespace App\Actions\Hr;

use App\Enums\AuditAction;
use App\Events\Audit\AuditEvent;
use App\Models\Admin;
use App\Models\HrPolicy;
use App\Support\ApiResponse;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Support\Facades\DB;

/**
 * Turns an HR policy on or off. Only the roles the policy lists in HrPolicy::CATALOG may change it - today only the Super Admin (owner decision
 * 2026-10-29: the HR Manager neither sees nor changes the multi-company switch) - re-checked here, not only by the route. A reason is required, a no-op is
 * refused so the audit trail only holds real changes, and every change is audited with the old and new value and the reason.
 */
class SetHrPolicyAction
{
    public function handle(Admin $actor, string $key, bool $enabled, string $reason): HrPolicy
    {
        if (! array_key_exists($key, HrPolicy::CATALOG)) {
            throw new HttpResponseException(ApiResponse::error('Unknown policy.', 'HR_POLICY_NOT_FOUND', 404));
        }

        if (! HrPolicy::roleMayChange($key, $actor->role)) {
            throw new HttpResponseException(ApiResponse::error('Only the Super Admin can change this policy.', 'FORBIDDEN', 403));
        }

        return DB::transaction(function () use ($actor, $key, $enabled, $reason): HrPolicy {
            $policy = HrPolicy::query()->where('key', $key)->lockForUpdate()->first();
            $before = (bool) $policy?->enabled;

            if ($before === $enabled) {
                throw new HttpResponseException(ApiResponse::error('This policy is already '.($enabled ? 'on' : 'off').'. Nothing was changed.', 'HR_POLICY_UNCHANGED', 422));
            }

            $policy = HrPolicy::query()->updateOrCreate(['key' => $key], ['enabled' => $enabled, 'changed_by' => $actor->id, 'reason' => trim($reason)]);

            event(AuditEvent::record(
                action: AuditAction::Update,
                admin: $actor,
                description: 'HR policy "'.HrPolicy::CATALOG[$key]['label'].'" switched '.($enabled ? 'ON' : 'OFF').'.',
                entityType: HrPolicy::class,
                entityId: $policy->id,
                metadata: ['key' => $key, 'before' => $before, 'after' => $enabled, 'reason' => trim($reason)],
            ));

            return $policy;
        });
    }
}
