<?php

namespace App\Actions\Home;

use App\Contracts\Home\Repositories\HeroBannerRepositoryInterface;
use App\Enums\AuditAction;
use App\Events\Audit\AuditEvent;
use App\Models\Admin;
use App\Models\HeroBanner;
use Illuminate\Support\Facades\DB;

class DeleteHeroBannerAction
{
    public function __construct(private HeroBannerRepositoryInterface $heroBanners) {}

    public function handle(Admin $admin, HeroBanner $banner): void
    {
        DB::transaction(function () use ($banner): void {
            $this->heroBanners->delete($banner);
        });

        event(AuditEvent::record(
            action: AuditAction::HeroBannerDelete,
            admin: $admin,
            description: 'Hero banner deleted.',
            entityType: HeroBanner::class,
            entityId: $banner->id,
            metadata: ['title' => $banner->title],
        ));
    }
}
