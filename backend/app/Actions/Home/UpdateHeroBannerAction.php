<?php

namespace App\Actions\Home;

use App\Contracts\Home\Repositories\HeroBannerRepositoryInterface;
use App\Enums\AuditAction;
use App\Enums\Home\HeroBannerActionType;
use App\Events\Audit\AuditEvent;
use App\Exceptions\Home\HeroBannerInvalidException;
use App\Models\Admin;
use App\Models\HeroBanner;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Partial banner update (API Design §18.11). Cross-field invariants are
 * validated against the merged state; toggling is_active is audited as a
 * dedicated publish/hide event.
 */
class UpdateHeroBannerAction
{
    public function __construct(private HeroBannerRepositoryInterface $heroBanners) {}

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function handle(Admin $admin, HeroBanner $banner, array $attributes): HeroBanner
    {
        $this->assertValidMergedState($banner, $attributes);

        $wasActive = $banner->is_active;

        $banner = DB::transaction(
            fn (): HeroBanner => $this->heroBanners->update($banner, $attributes),
        );

        event(AuditEvent::record(
            action: $this->auditAction($wasActive, $banner->is_active),
            admin: $admin,
            description: $this->auditDescription($wasActive, $banner->is_active),
            entityType: HeroBanner::class,
            entityId: $banner->id,
            metadata: [
                'changed_fields' => array_keys($attributes),
                'is_active' => $banner->is_active,
            ],
        ));

        return $banner;
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function assertValidMergedState(HeroBanner $banner, array $attributes): void
    {
        $actionType = array_key_exists('action_type', $attributes)
            ? HeroBannerActionType::from((string) $attributes['action_type'])
            : $banner->action_type;

        $actionReference = array_key_exists('action_reference', $attributes)
            ? $attributes['action_reference']
            : $banner->action_reference;

        if ($actionType === HeroBannerActionType::None && $actionReference !== null) {
            throw HeroBannerInvalidException::forbiddenActionReference();
        }

        if ($actionType !== HeroBannerActionType::None && ($actionReference === null || $actionReference === '')) {
            throw HeroBannerInvalidException::missingActionReference();
        }

        $startsAt = array_key_exists('starts_at', $attributes)
            ? ($attributes['starts_at'] !== null ? CarbonImmutable::parse($attributes['starts_at']) : null)
            : $banner->starts_at;

        $endsAt = array_key_exists('ends_at', $attributes)
            ? ($attributes['ends_at'] !== null ? CarbonImmutable::parse($attributes['ends_at']) : null)
            : $banner->ends_at;

        if ($startsAt !== null && $endsAt !== null && $endsAt->lessThanOrEqualTo($startsAt)) {
            throw HeroBannerInvalidException::invalidSchedule();
        }
    }

    private function auditAction(bool $wasActive, bool $isActive): AuditAction
    {
        if ($wasActive === $isActive) {
            return AuditAction::HeroBannerUpdate;
        }

        return $isActive ? AuditAction::HeroBannerPublish : AuditAction::HeroBannerHide;
    }

    private function auditDescription(bool $wasActive, bool $isActive): string
    {
        if ($wasActive === $isActive) {
            return 'Hero banner updated.';
        }

        return $isActive ? 'Hero banner published.' : 'Hero banner hidden.';
    }
}
