<?php

namespace App\Actions\Home;

use App\Enums\AuditAction;
use App\Events\Audit\AuditEvent;
use App\Models\Admin;
use App\Models\Service;
use Illuminate\Support\Facades\DB;

/**
 * Manual featured curation only (API Design §18.11): no automatic
 * featuring exists. Inactive services keep their flag but the public
 * catalog visibility rules exclude them from the featured row.
 */
class ToggleServiceFeaturedAction
{
    public function handle(Admin $admin, Service $service, bool $isFeatured, ?int $sortOrder): Service
    {
        $previous = $service->is_featured;

        $service = DB::transaction(function () use ($service, $isFeatured, $sortOrder): Service {
            $service->is_featured = $isFeatured;

            if ($sortOrder !== null) {
                $service->sort_order = $sortOrder;
            }

            $service->save();

            return $service->refresh();
        });

        event(AuditEvent::record(
            action: AuditAction::ServiceFeatureToggle,
            admin: $admin,
            description: $isFeatured ? 'Service featured.' : 'Service unfeatured.',
            entityType: Service::class,
            entityId: $service->id,
            metadata: [
                'service_slug' => $service->slug,
                'previous_is_featured' => $previous,
                'is_featured' => $service->is_featured,
                'sort_order' => $service->sort_order,
            ],
        ));

        return $service;
    }
}
