<?php

namespace App\Actions\Home;

use App\Contracts\Home\Repositories\HeroBannerRepositoryInterface;
use App\DataTransferObjects\Home\CreateHeroBannerData;
use App\Enums\AuditAction;
use App\Events\Audit\AuditEvent;
use App\Models\Admin;
use App\Models\HeroBanner;
use Illuminate\Support\Facades\DB;

class CreateHeroBannerAction
{
    public function __construct(private HeroBannerRepositoryInterface $heroBanners) {}

    public function handle(Admin $admin, CreateHeroBannerData $data): HeroBanner
    {
        $banner = DB::transaction(
            fn (): HeroBanner => $this->heroBanners->create($data),
        );

        event(AuditEvent::record(
            action: AuditAction::HeroBannerCreate,
            admin: $admin,
            description: 'Hero banner created.',
            entityType: HeroBanner::class,
            entityId: $banner->id,
            metadata: [
                'title' => $banner->title,
                'action_type' => $banner->action_type->value,
                'is_active' => $banner->is_active,
            ],
        ));

        return $banner;
    }
}
